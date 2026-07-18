<?php
/**
 * PROJECT DIRECTOR AGENT
 * Audita estado geral do projeto e identifica lacunas
 * Roda 24/7 para garantir qualidade e eficiência
 */

declare(strict_types=1);

class ProjectDirectorAgent {
    private array $audit_results = [];
    private array $critical_issues = [];
    private array $warnings = [];

    public function __construct() {
        $this->log("🎯 PROJECT DIRECTOR AGENT - Iniciando auditoria", "info");
    }

    /**
     * Executa auditoria completa do projeto
     */
    public function run_full_audit(): array {
        $this->audit_admin_panel();
        $this->audit_database();
        $this->audit_integrations();
        $this->audit_api_endpoints();
        $this->audit_deployment();
        $this->audit_documentation();

        return $this->generate_report();
    }

    /**
     * Auditoria: Painel de Admin
     */
    private function audit_admin_panel(): void {
        $admin_files = [
            '/admin/index.php' => 'Dashboard principal',
            '/admin/produtos.php' => 'Gestão de produtos',
            '/admin/pedidos.php' => 'Gestão de pedidos',
            '/admin/clientes.php' => 'Gestão de clientes',
            '/admin/monitor/' => 'Monitor',
            '/admin/menu-completo.php' => 'Menu centralizado',
        ];

        foreach ($admin_files as $file => $desc) {
            $path = __DIR__ . '/../' . ltrim($file, '/');
            if (!file_exists($path)) {
                $this->add_critical("ADMIN: Faltando $desc ($file)");
            }
        }

        $this->log("✅ Admin panel audit concluído", "info");
    }

    /**
     * Auditoria: Banco de Dados
     */
    private function audit_database(): void {
        $required_tables = [
            'users', 'products', 'orders', 'customers',
            'order_items', 'payments', 'shipping'
        ];

        $pdo = $this->db();
        if ($pdo === null) {
            $this->add_warning('DATABASE: nao foi possivel conectar (credenciais ausentes ou banco fora do ar) -- checagem de tabelas pulada');
            return;
        }

        foreach ($required_tables as $table) {
            $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $stmt->execute([$table]);
            $exists = (bool)$stmt->fetchColumn();
            $this->audit_results["database_table_$table"] = $exists ? 'ok' : 'missing';
            if (!$exists) {
                $this->add_warning("DATABASE: tabela '$table' nao encontrada");
            }
        }

        $this->log("✅ Database audit concluído", "info");
    }

    private function db(): ?PDO {
        static $pdo = false;
        if ($pdo instanceof PDO) return $pdo;
        if ($pdo === null) return null;

        $constants = __DIR__ . '/../config/constants.php';
        if (is_file($constants)) require_once $constants;

        $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: '');
        $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: '');
        $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: '');
        $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: '');
        $port = defined('DB_PORT') ? DB_PORT : (getenv('DB_PORT') ?: '3306');
        if ($host === '' || $name === '' || $user === '') {
            $pdo = null;
            return null;
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            return $pdo;
        } catch (Throwable $e) {
            $this->log("DB connection failed: " . $e->getMessage(), 'error');
            $pdo = null;
            return null;
        }
    }

    /**
     * Auditoria: Integrações
     */
    private function audit_integrations(): void {
        $integrations = [
            'olist' => 'Olist/Tiny ERP',
            'mercadolivre' => 'Mercado Livre',
            'pagarme' => 'Pagar.me',
            'mercadopago' => 'Mercado Pago',
            'shopee' => 'Shopee',
        ];

        foreach ($integrations as $key => $name) {
            $status = $this->check_integration_status($key);
            if ($status === 'disconnected') {
                $this->add_warning("INTEGRAÇÃO: $name desconectada");
            }
        }

        $this->log("✅ Integrations audit concluído", "info");
    }

    /**
     * Auditoria: Endpoints de API
     */
    private function audit_api_endpoints(): void {
        // GET puro (sem POST) so faz sentido pra endpoints de leitura --
        // create.php e mercadopago/* exigem payload valido, entao so
        // confirmamos que o arquivo existe no disco em vez de bater via
        // HTTP com corpo vazio (o que so provaria erro 4xx esperado, nao
        // informacao real sobre o endpoint estar funcionando).
        $readEndpoints = [
            '/api/health.php' => 'Health check',
            '/api/catalog/products.php' => 'Produtos',
        ];
        $baseUrl = 'https://dev.shopvivaliz.com.br';

        foreach ($readEndpoints as $endpoint => $desc) {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true]]);
            $result = @file_get_contents($baseUrl . $endpoint, false, $ctx);
            $statusLine = $http_response_header[0] ?? '';
            $ok = $result !== false && (bool)preg_match('~ (2\d\d) ~', $statusLine);
            $this->audit_results["api_$endpoint"] = $ok ? 'ok' : 'failed';
            if (!$ok) {
                $this->add_critical("API: $desc ($endpoint) respondeu '$statusLine' ou falhou a conexao");
            }
        }

        $fileOnlyEndpoints = [
            '/api/orders/create.php' => 'Criar pedido',
            '/api/mercadopago/create-preference.php' => 'Mercado Pago',
        ];
        foreach ($fileOnlyEndpoints as $endpoint => $desc) {
            $path = __DIR__ . '/..' . $endpoint;
            $exists = is_file($path);
            $this->audit_results["api_$endpoint"] = $exists ? 'ok' : 'missing';
            if (!$exists) {
                $this->add_critical("API: $desc ($endpoint) nao existe no disco");
            }
        }

        $this->log("✅ API endpoints audit concluído", "info");
    }

    /**
     * Auditoria: Deploy & Produção
     */
    private function audit_deployment(): void {
        // Verificar se sincronização está funcionando
        $last_sync = $this->get_last_sync_time();
        $time_diff = time() - strtotime($last_sync);

        if ($time_diff > 1800) { // 30 minutos
            $this->add_warning("DEPLOY: Última sincronização há " . floor($time_diff/60) . " minutos");
        }

        if (!file_exists(__DIR__ . '/../.env')) {
            $this->add_critical("DEPLOY: .env não encontrado");
        }

        $this->log("✅ Deployment audit concluído", "info");
    }

    /**
     * Auditoria: Documentação
     */
    private function audit_documentation(): void {
        $docs = [
            'CLAUDE.md' => 'Instruções do projeto',
            'README.md' => 'Documentação',
            'CHANGELOG.md' => 'Histórico de mudanças',
        ];

        foreach ($docs as $file => $desc) {
            $path = __DIR__ . '/../' . $file;
            if (!file_exists($path)) {
                $this->add_warning("DOCS: Faltando $desc");
            }
        }

        $this->log("✅ Documentation audit concluído", "info");
    }

    /**
     * Gera relatório de auditoria
     */
    private function generate_report(): array {
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'agent' => 'ProjectDirectorAgent',
            'audit_type' => 'full_scan',
            'critical_issues' => $this->critical_issues,
            'warnings' => $this->warnings,
            'total_checks' => count($this->audit_results),
            'status' => empty($this->critical_issues) ? 'healthy' : 'issues_found',
            'next_audit' => date('Y-m-d H:i:s', time() + 3600),
        ];
    }

    // Helpers
    private function add_critical(string $issue): void {
        $this->critical_issues[] = $issue;
        $this->log("🔴 CRÍTICO: $issue", "error");
    }

    private function add_warning(string $issue): void {
        $this->warnings[] = $issue;
        $this->log("🟡 AVISO: $issue", "warning");
    }

    private function check_integration_status(string $key): string {
        // Verifica presenca real de credenciais configuradas (.env/tokens.json)
        // em vez de simular "connected" sempre. Nao faz chamada de API ao
        // vivo pra cada provedor (custaria rate limit a cada ciclo de 1min
        // do orquestrador) -- presenca de credencial e um proxy real e
        // barato de "integracao configurada".
        $envKeys = match ($key) {
            'olist' => ['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN'],
            'mercadolivre' => ['ML_CLIENT_ID', 'ML_CLIENT_SECRET'],
            'pagarme' => ['PAGARME_API_KEY', 'PAGARME_SECRET_KEY'],
            'mercadopago' => ['MERCADOPAGO_ACCESS_TOKEN'],
            'shopee' => ['SHOPEE_PARTNER_ID', 'SHOPEE_PARTNER_KEY'],
            default => [],
        };
        foreach ($envKeys as $envKey) {
            $value = getenv($envKey);
            if (is_string($value) && trim($value) !== '') {
                return 'connected';
            }
        }
        return 'disconnected';
    }

    private function get_last_sync_time(): string {
        $sync_file = __DIR__ . '/../logs/tri-environment-sync.json';
        if (file_exists($sync_file)) {
            $data = json_decode(file_get_contents($sync_file), true);
            return $data['timestamp'] ?? date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s');
    }

    private function log(string $message, string $level = 'info'): void {
        $log_file = __DIR__ . '/../logs/project-director-agent.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_line = "[$timestamp] [$level] $message\n";
        @file_put_contents($log_file, $log_line, FILE_APPEND);
    }
}

// EXECUTAR AUDITORIA
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    $director = new ProjectDirectorAgent();
    $report = $director->run_full_audit();

    // Salvar relatório
    $report_file = __DIR__ . '/../logs/project-director-report.json';
    file_put_contents($report_file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if (php_sapi_name() === 'cli') {
        echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($report, JSON_PRETTY_PRINT);
    }
}
