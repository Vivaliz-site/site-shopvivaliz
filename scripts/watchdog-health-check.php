<?php
/**
 * 🛡️ WATCHDOG - Health Check + Auto-Rollback Circuit Breaker
 * Executa após cada deploy para validar integridade do site
 * Se falhar, faz auto-revert automático
 */

class WatchdogHealthCheck {
    private $baseUrl = 'https://shopvivaliz.com.br';
    private $gitPath = '/home/ubuntu/shopvivaliz-deploy/repo';
    private $currentPath = '/home/ubuntu/shopvivaliz-deploy/current';
    private $alertWebhook = ''; // Será preenchido via env
    private $maxRetries = 3;
    private $logFile = '/var/log/watchdog-health.log';

    public function __construct() {
        $this->alertWebhook = getenv('WHATSAPP_WEBHOOK') ?: '';
    }

    public function run() {
        $this->log('⏳ Iniciando Watchdog Health Check...');

        $checks = [
            'homepage_200' => $this->checkHomepage(),
            'admin_accessible' => $this->checkAdminPanel(),
            'api_endpoints' => $this->checkApiEndpoints(),
            'database_connection' => $this->checkDatabase(),
            'critical_files' => $this->checkCriticalFiles(),
        ];

        $passed = array_sum($checks);
        $total = count($checks);

        $this->log("📊 Resultado: $passed/$total checks passaram");

        if ($passed === $total) {
            $this->log('✅ TODOS os checks passaram. Sistema saudável.');
            $this->recordHealthStatus('HEALTHY', $checks);
            return true;
        } else {
            $this->log('❌ FALHA detectada. Auto-rollback Git foi desativado no modelo de releases imutáveis.');
            $this->triggerAutoRollback($checks);
            return false;
        }
    }

    private function checkHomepage() {
        $this->log('🔍 Verificando homepage...');

        for ($i = 0; $i < $this->maxRetries; $i++) {
            $response = $this->curlGet($this->baseUrl . '/');

            if ($response['http_code'] === 200 && strpos($response['body'], 'Shopvivaliz') !== false) {
                $this->log('✅ Homepage respondendo (HTTP 200)');
                return true;
            }

            $this->log("⚠️ Tentativa " . ($i + 1) . " falhou. HTTP: {$response['http_code']}");
            sleep(2);
        }

        $this->log('❌ Homepage indisponível após ' . $this->maxRetries . ' tentativas');
        return false;
    }

    private function checkAdminPanel() {
        $this->log('🔍 Verificando painel admin...');

        $response = $this->curlGet($this->baseUrl . '/admin/');

        if ($response['http_code'] === 200 || $response['http_code'] === 302) {
            $this->log('✅ Admin painel acessível');
            return true;
        }

        $this->log("❌ Admin painel indisponível (HTTP: {$response['http_code']})");
        return false;
    }

    private function checkApiEndpoints() {
        $this->log('🔍 Verificando endpoints de API...');

        $endpoints = [
            '/api/products/' => 'GET',
            '/api/agent/squad-chat.php' => 'POST',
            '/api/olist/sync-company-profile.php' => 'GET',
        ];

        $passed = 0;
        foreach ($endpoints as $endpoint => $method) {
            if ($method === 'GET') {
                $response = $this->curlGet($this->baseUrl . $endpoint);
            } else {
                $response = $this->curlPost($this->baseUrl . $endpoint, []);
            }

            if ($response['http_code'] !== 404 && $response['http_code'] !== 500) {
                $passed++;
                $this->log("✅ {$endpoint} respondendo");
            } else {
                $this->log("❌ {$endpoint} erro (HTTP: {$response['http_code']})");
            }
        }

        return $passed === count($endpoints);
    }

    private function checkDatabase() {
        $this->log('🔍 Verificando conexão com banco de dados...');

        try {
            $conn = new PDO('mysql:host=localhost;dbname=shopvivaliz',
                getenv('DB_USER'),
                getenv('DB_PASS'));

            $result = $conn->query('SELECT 1');
            $this->log('✅ Banco de dados conectado');
            return true;
        } catch (Exception $e) {
            $this->log('❌ Erro na conexão DB: ' . $e->getMessage());
            return false;
        }
    }

    private function checkCriticalFiles() {
        $this->log('🔍 Verificando arquivos críticos...');

        $files = [
            '/home/ubuntu/shopvivaliz-deploy/current/index.php',
            '/home/ubuntu/shopvivaliz-deploy/current/config/bootstrap-env.php',
            '/home/ubuntu/shopvivaliz-deploy/current/includes/footer.php',
        ];

        $passed = 0;
        foreach ($files as $file) {
            if (file_exists($file) && filesize($file) > 100) {
                $passed++;
                $this->log("✅ {$file} OK");
            } else {
                $this->log("❌ {$file} ausente ou vazio");
            }
        }

        return $passed === count($files);
    }

    private function triggerAutoRollback($checks) {
        $this->log('🚨 FALHA detectada. Rollback automático por Git está desativado; revisão manual necessária.');
        $activeRelease = trim((string) shell_exec("readlink -f {$this->currentPath}"));
        $activeSha = is_file($this->currentPath . '/.release-sha')
            ? trim((string) file_get_contents($this->currentPath . '/.release-sha'))
            : '';
        $this->sendAlert(
            '🚨 FALHA DE SAÚDE EM PRODUÇÃO',
            "Problema detectado no deploy.\n\n" .
            "❌ Checks falharam: " . json_encode($checks) . "\n\n" .
            "⚠️ Rollback automático via Git foi desativado no modelo de releases imutáveis.\n" .
            "Release ativa: {$activeRelease}\n" .
            "SHA ativo: {$activeSha}\n" .
            "Acione rollback manual revisado se necessário.\n\n" .
            "Verifique o painel admin em: {$this->baseUrl}/admin/"
        );

        $this->recordHealthStatus('MANUAL_REVIEW_REQUIRED', $checks);
    }

    private function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return compact('body', 'http_code');
    }

    private function curlPost($url, $data) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return compact('body', 'http_code');
    }

    private function sendAlert($title, $message) {
        $this->log("📱 Enviando alerta: {$title}");

        // WhatsApp via webhook
        if ($this->alertWebhook) {
            $payload = json_encode([
                'title' => $title,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            $ch = curl_init($this->alertWebhook);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        // Email como fallback
        mail(
            'fredmourao@gmail.com',
            "[ALERTAS SHOPVIVALIZ] {$title}",
            $message,
            'From: watchdog@shopvivaliz.com.br'
        );
    }

    private function recordHealthStatus($status, $checks) {
        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $status,
            'checks' => $checks,
            'git_commit' => trim(shell_exec('cd ' . $this->gitPath . ' && git log -1 --format=%H')),
        ];

        file_put_contents(
            '.health-status.json',
            json_encode($record, JSON_PRETTY_PRINT)
        );
    }

    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}

// Executar
$watchdog = new WatchdogHealthCheck();
exit($watchdog->run() ? 0 : 1);
