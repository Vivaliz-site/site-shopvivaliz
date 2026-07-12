<?php
/**
 * Agent Heartbeat Monitor
 * Garante que Claude, Gemini e GPT estejam sempre ativos 24/7
 */

declare(strict_types=1);

class AgentHeartbeatMonitor {
    private $heartbeatDir = __DIR__ . '/../.agent-heartbeats';
    private $agents = ['claude', 'gemini', 'gpt'];
    private $heartbeatTTL = 600; // 10 minutos

    public function __construct() {
        if (!is_dir($this->heartbeatDir)) {
            mkdir($this->heartbeatDir, 0755, true);
        }
    }

    /**
     * Registrar heartbeat de um agente
     */
    public function recordHeartbeat(string $agentId): void {
        $file = $this->getHeartbeatFile($agentId);
        $data = [
            'agent_id' => $agentId,
            'timestamp' => date('c'),
            'unix_timestamp' => time(),
            'status' => 'alive',
            'tasks_processed' => $this->getTasksCount($agentId)
        ];
        
        file_put_contents($file, json_encode($data));
    }

    /**
     * Verificar se agente está vivo
     */
    public function isAlive(string $agentId): bool {
        $file = $this->getHeartbeatFile($agentId);
        
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);
        $age = time() - ($data['unix_timestamp'] ?? 0);

        return $age < $this->heartbeatTTL;
    }

    /**
     * Obter status de todos os agentes
     */
    public function getStatus(): array {
        $status = [];
        
        foreach ($this->agents as $agent) {
            $isAlive = $this->isAlive($agent);
            $file = $this->getHeartbeatFile($agent);
            
            $data = [];
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
            }

            $status[$agent] = [
                'alive' => $isAlive,
                'last_heartbeat' => $data['timestamp'] ?? 'Never',
                'status' => $isAlive ? '✅ ATIVO' : '❌ INATIVO',
                'tasks_processed' => $data['tasks_processed'] ?? 0
            ];
        }

        return $status;
    }

    /**
     * Alertar se algum agente está inativo
     */
    public function checkForInactiveAgents(): array {
        $inactive = [];
        $status = $this->getStatus();

        foreach ($status as $agent => $data) {
            if (!$data['alive']) {
                $inactive[$agent] = $data;
            }
        }

        if (!empty($inactive)) {
            $this->sendAlert($inactive);
        }

        return $inactive;
    }

    /**
     * Enviar alerta para agentes inativos
     */
    private function sendAlert(array $inactive): void {
        $message = "⚠️ AGENTES INATIVOS DETECTADOS:\n";
        foreach ($inactive as $agent => $data) {
            $message .= "  • $agent: Última atividade em {$data['last_heartbeat']}\n";
        }

        error_log($message);
        // Aqui poderia enviar email, Slack, etc.
    }

    /**
     * Gerar relatório de atividade
     */
    public function generateReport(): string {
        $status = $this->getStatus();
        
        $report = "═══════════════════════════════════════════\n";
        $report .= "📊 AGENT HEARTBEAT STATUS REPORT\n";
        $report .= "═══════════════════════════════════════════\n\n";

        $allAlive = true;
        foreach ($status as $agent => $data) {
            $report .= "[" . strtoupper($agent) . "]\n";
            $report .= "  Status: " . $data['status'] . "\n";
            $report .= "  Last Heartbeat: " . $data['last_heartbeat'] . "\n";
            $report .= "  Tasks: " . $data['tasks_processed'] . "\n";
            $report .= "\n";

            if (!$data['alive']) {
                $allAlive = false;
            }
        }

        $report .= "═══════════════════════════════════════════\n";
        $report .= "Overall Status: " . ($allAlive ? "✅ ALL AGENTS ACTIVE" : "⚠️ SOME AGENTS INACTIVE") . "\n";
        $report .= "Monitoring Interval: Every 10 minutes\n";
        $report .= "═══════════════════════════════════════════\n";

        return $report;
    }

    /**
     * Caminho do arquivo de heartbeat
     */
    private function getHeartbeatFile(string $agentId): string {
        return $this->heartbeatDir . '/' . $agentId . '.heartbeat';
    }

    /**
     * Obter contagem de tarefas processadas
     */
    private function getTasksCount(string $agentId): int {
        $logFile = __DIR__ . '/../logs/agent-' . $agentId . '.log';
        if (!file_exists($logFile)) {
            return 0;
        }

        $lines = file($logFile);
        $count = 0;
        foreach ($lines as $line) {
            if (strpos($line, 'task') !== false && strpos($line, 'processed') !== false) {
                $count++;
            }
        }

        return $count;
    }
}

// Uso
$monitor = new AgentHeartbeatMonitor();

// Registrar heartbeat do agente
$agentId = $_ENV['AGENT_ID'] ?? 'claude';
$monitor->recordHeartbeat($agentId);

// Verificar status
$status = $monitor->getStatus();
echo $monitor->generateReport();

// Alertar se necessário
$inactive = $monitor->checkForInactiveAgents();
if (!empty($inactive)) {
    exit(1);
}
