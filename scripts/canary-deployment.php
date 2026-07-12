<?php
/**
 * 🔄 Canary Deployment - Deploy progressivo com feature flags
 * 5% → 25% → 50% → 100% com rollback automático
 */

class CanaryDeployment {
    private $featureFlagsFile = 'config/feature-flags.json';
    private $canaryMetricsFile = '.canary-metrics.json';
    private $stages = [5, 25, 50, 100]; // % de usuários
    private $stageInterval = 30 * 60; // 30 min entre stages
    private $errorThreshold = 2.0; // 2% de erro rate para rollback

    public function __construct() {}

    public function initializeCanary($featureName, $commitHash) {
        echo "🔄 Iniciando Canary Deployment: {$featureName}\n";

        $flags = $this->readFeatureFlags();

        $flags[$featureName] = [
            'enabled' => true,
            'canary_mode' => true,
            'rollout_percentage' => 5,
            'commit_hash' => $commitHash,
            'started_at' => time(),
            'stages_completed' => [5 => false],
            'error_rate' => 0,
            'status' => 'canary_5_percent',
        ];

        $this->writeFeatureFlags($flags);

        echo "✅ Canary iniciado em 5% de usuários\n";
        echo "📅 Próximo stage em 30 minutos\n";
    }

    public function checkCanaryHealth($featureName) {
        echo "🏥 Verificando saúde do Canary: {$featureName}\n";

        $metrics = $this->getCanaryMetrics($featureName);
        $errorRate = $metrics['error_rate'] ?? 0;
        $responseTime = $metrics['avg_response_time'] ?? 0;
        $uptime = $metrics['uptime'] ?? 100;

        echo "   Error Rate: {$errorRate}%\n";
        echo "   Response Time: {$responseTime}ms\n";
        echo "   Uptime: {$uptime}%\n";

        if ($errorRate > $this->errorThreshold || $uptime < 95) {
            echo "❌ Canary FALHOU - Iniciando rollback\n";
            return $this->rollbackCanary($featureName);
        }

        echo "✅ Canary saudável\n";
        return true;
    }

    public function progressCanary($featureName) {
        echo "📈 Progredindo Canary: {$featureName}\n";

        $flags = $this->readFeatureFlags();

        if (!isset($flags[$featureName])) {
            echo "❌ Feature não encontrada\n";
            return false;
        }

        $feature = &$flags[$featureName];
        $currentPercentage = $feature['rollout_percentage'];

        // Encontrar próximo stage
        $nextPercentage = null;
        foreach ($this->stages as $stage) {
            if ($stage > $currentPercentage) {
                $nextPercentage = $stage;
                break;
            }
        }

        if (!$nextPercentage) {
            echo "✅ Canary completo - 100% de usuários\n";
            $feature['canary_mode'] = false;
            $feature['status'] = 'fully_rolled_out';
            $this->writeFeatureFlags($flags);
            return true;
        }

        // Atualizar percentage
        $feature['rollout_percentage'] = $nextPercentage;
        $feature['status'] = "canary_{$nextPercentage}_percent";
        $feature['stages_completed'][$nextPercentage] = true;
        $feature['last_progressed'] = time();

        $this->writeFeatureFlags($flags);

        echo "✅ Canary progredido para {$nextPercentage}% de usuários\n";
        echo "📅 Próximo stage em 30 minutos\n";

        return true;
    }

    private function rollbackCanary($featureName) {
        echo "🔙 EXECUTANDO ROLLBACK: {$featureName}\n";

        $flags = $this->readFeatureFlags();

        if (isset($flags[$featureName])) {
            $flags[$featureName]['enabled'] = false;
            $flags[$featureName]['canary_mode'] = false;
            $flags[$featureName]['status'] = 'rolled_back';
            $flags[$featureName]['rolled_back_at'] = time();

            $this->writeFeatureFlags($flags);

            // Git revert
            echo "🔄 Revertendo commit...\n";
            $commitHash = $flags[$featureName]['commit_hash'];
            shell_exec("cd /home/ubuntu/site-shopvivaliz && git revert {$commitHash} --no-edit 2>&1");
            shell_exec("cd /home/ubuntu/site-shopvivaliz && git push origin main 2>&1");

            echo "✅ Rollback concluído\n";

            // Notificar admin
            $this->notifyRollback($featureName, $flags[$featureName]);
        }

        return false;
    }

    public function getCanaryStatus($featureName) {
        echo "📊 Status do Canary: {$featureName}\n";

        $flags = $this->readFeatureFlags();

        if (!isset($flags[$featureName])) {
            echo "❌ Feature não encontrada\n";
            return null;
        }

        $feature = $flags[$featureName];
        $metrics = $this->getCanaryMetrics($featureName);

        $status = [
            'feature' => $featureName,
            'enabled' => $feature['enabled'],
            'canary_mode' => $feature['canary_mode'],
            'rollout_percentage' => $feature['rollout_percentage'],
            'status' => $feature['status'],
            'commit' => substr($feature['commit_hash'], 0, 8),
            'started_at' => date('Y-m-d H:i:s', $feature['started_at']),
            'metrics' => $metrics,
        ];

        echo json_encode($status, JSON_PRETTY_PRINT);

        return $status;
    }

    public function isFeatureEnabled($featureName, $userId) {
        $flags = $this->readFeatureFlags();

        if (!isset($flags[$featureName])) {
            return false;
        }

        $feature = $flags[$featureName];

        if (!$feature['enabled']) {
            return false;
        }

        // Calcular se usuário está no rollout percentage
        if (!$feature['canary_mode']) {
            return true; // Feature totalmente ativada
        }

        // Hash do user ID para distribuição consistente
        $userHash = crc32($userId) % 100;

        return $userHash < $feature['rollout_percentage'];
    }

    private function getCanaryMetrics($featureName) {
        // Em produção, isso viria do Datadog, New Relic, etc
        // Por agora, retornar métricas simuladas

        $metricsData = json_decode(file_get_contents($this->canaryMetricsFile) ?: '{}', true);

        return $metricsData[$featureName] ?? [
            'error_rate' => 0.5,
            'avg_response_time' => 245,
            'uptime' => 99.8,
            'users_in_canary' => 150,
        ];
    }

    private function readFeatureFlags() {
        if (!file_exists($this->featureFlagsFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->featureFlagsFile), true) ?: [];
    }

    private function writeFeatureFlags($flags) {
        file_put_contents(
            $this->featureFlagsFile,
            json_encode($flags, JSON_PRETTY_PRINT)
        );
    }

    private function notifyRollback($featureName, $feature) {
        $message = "🔙 Canary ROLLBACK: {$featureName}\n\n" .
                   "Commit: {$feature['commit_hash']}\n" .
                   "Status antes: {$feature['status']}\n" .
                   "Tempo: " . date('Y-m-d H:i:s', $feature['rolled_back_at']) . "\n\n" .
                   "Por favor, investigue o commit para encontrar o problema.";

        mail(
            'fredmourao@gmail.com',
            "[CANARY ROLLBACK] {$featureName}",
            $message,
            'From: canary@shopvivaliz.com.br'
        );
    }
}

// CLI Interface
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $canary = new CanaryDeployment();

    switch ($argv[1]) {
        case 'init':
            if (isset($argv[2], $argv[3])) {
                $canary->initializeCanary($argv[2], $argv[3]);
            }
            break;

        case 'check':
            if (isset($argv[2])) {
                $canary->checkCanaryHealth($argv[2]);
            }
            break;

        case 'progress':
            if (isset($argv[2])) {
                $canary->progressCanary($argv[2]);
            }
            break;

        case 'status':
            if (isset($argv[2])) {
                $canary->getCanaryStatus($argv[2]);
            }
            break;

        case 'rollback':
            if (isset($argv[2])) {
                $canary->rollbackCanary($argv[2]);
            }
            break;

        default:
            echo "Uso: php canary-deployment.php [init|check|progress|status|rollback] <feature_name> [commit_hash]\n";
    }
}

// Exemplo de uso em código
/*
$canary = new CanaryDeployment();

// No código da aplicação, usar assim:
if ($canary->isFeatureEnabled('new-checkout', $userId)) {
    // Usar novo checkout
    include 'checkout-v2.php';
} else {
    // Usar checkout antigo
    include 'checkout-v1.php';
}
*/
