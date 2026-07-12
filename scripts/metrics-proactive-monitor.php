<?php
/**
 * 📊 Proactive Metrics Monitor - Detecta degradação antes da falha
 * Monitora: CPU, memória, taxa de sucesso, latência, queue size
 */

class MetricsProactiveMonitor {
    private $metricsFile = '.agent-metrics.json';
    private $dashboardFile = 'admin/agent-metrics-dashboard.php';
    private $alertThresholds = [
        'success_rate' => 90, // % (alerta se < 90%)
        'execution_time' => 2.0, // segundos (alerta se 2x acima do normal)
        'queue_size' => 50, // tasks (alerta se > 50 pendentes)
        'cpu_usage' => 80, // % (alerta se > 80%)
        'memory_usage' => 85, // % (alerta se > 85%)
    ];

    public function run() {
        echo "📊 Coletando métricas de agentes...\n";

        $metrics = $this->collectMetrics();
        $alerts = $this->detectAlerts($metrics);

        $this->saveMetrics($metrics);
        $this->generateDashboard($metrics, $alerts);

        if (!empty($alerts)) {
            echo "🚨 " . count($alerts) . " alertas detectados!\n";
            $this->triggerAlerts($alerts);
        } else {
            echo "✅ Todas as métricas normais\n";
        }

        return true;
    }

    private function collectMetrics() {
        $metrics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'agents' => [],
            'system' => $this->getSystemMetrics(),
        ];

        // Coletar per-agent
        foreach (['claude', 'gemini', 'gpt'] as $agent) {
            $metrics['agents'][$agent] = $this->getAgentMetrics($agent);
        }

        return $metrics;
    }

    private function getSystemMetrics() {
        $metrics = [];

        // CPU
        if (function_exists('shell_exec')) {
            $cpuUsage = trim(shell_exec('top -bn1 | grep "Cpu(s)" | sed "s/.*, *\\([0-9.]*\\)%* id.*/\\1/" | awk \'{print 100 - $1}\''));
            $metrics['cpu_usage'] = floatval($cpuUsage) ?? 0;

            // Memória
            $memInfo = shell_exec('free | grep Mem | awk \'{print ($3/$2) * 100}\'');
            $metrics['memory_usage'] = floatval($memInfo) ?? 0;

            // Disk
            $diskUsage = shell_exec('df / | tail -1 | awk \'{print $5}\'');
            $metrics['disk_usage'] = floatval($diskUsage) ?? 0;
        }

        // Load average
        $loadavg = sys_getloadavg();
        $metrics['load_average'] = $loadavg[0] ?? 0;

        return $metrics;
    }

    private function getAgentMetrics($agent) {
        $agentFile = ".agent-queue-{$agent}.json";
        $heartbeatFile = ".agent-heartbeats/{$agent}.heartbeat";

        $queue = file_exists($agentFile) ? json_decode(file_get_contents($agentFile), true) : [];
        $heartbeat = file_exists($heartbeatFile) ? json_decode(file_get_contents($heartbeatFile), true) : null;

        // Contar status
        $total = count($queue);
        $pending = count(array_filter($queue, fn($t) => ($t['status'] ?? '') === 'pending'));
        $completed = count(array_filter($queue, fn($t) => ($t['status'] ?? '') === 'completed'));
        $failed = count(array_filter($queue, fn($t) => ($t['status'] ?? '') === 'failed'));

        // Taxa de sucesso
        $successRate = $total > 0 ? ($completed / $total) * 100 : 100;

        // Tempo médio de execução
        $avgExecutionTime = $this->calculateAverageExecutionTime($queue);

        // Status do heartbeat
        $isAlive = $heartbeat && (time() - strtotime($heartbeat['timestamp'])) < 600;

        return [
            'is_alive' => $isAlive,
            'total_tasks' => $total,
            'pending' => $pending,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => round($successRate, 2),
            'avg_execution_time' => round($avgExecutionTime, 2),
            'last_heartbeat' => $heartbeat['timestamp'] ?? 'never',
            'tasks_processed' => $heartbeat['tasks_processed'] ?? 0,
        ];
    }

    private function calculateAverageExecutionTime($queue) {
        $times = [];

        foreach ($queue as $task) {
            if (!empty($task['started_at']) && !empty($task['completed_at'])) {
                $start = strtotime($task['started_at']);
                $end = strtotime($task['completed_at']);
                $times[] = $end - $start;
            }
        }

        return !empty($times) ? array_sum($times) / count($times) : 0;
    }

    private function detectAlerts($metrics) {
        $alerts = [];

        // Verificar cada agente
        foreach ($metrics['agents'] as $agent => $agentMetrics) {
            // Alert: Taxa de sucesso baixa
            if ($agentMetrics['success_rate'] < $this->alertThresholds['success_rate']) {
                $alerts[] = [
                    'severity' => 'high',
                    'type' => 'low_success_rate',
                    'agent' => $agent,
                    'value' => $agentMetrics['success_rate'],
                    'threshold' => $this->alertThresholds['success_rate'],
                    'message' => "$agent com taxa de sucesso de {$agentMetrics['success_rate']}% (esperado: >{$this->alertThresholds['success_rate']}%)",
                ];
            }

            // Alert: Heartbeat expirado
            if (!$agentMetrics['is_alive']) {
                $alerts[] = [
                    'severity' => 'critical',
                    'type' => 'heartbeat_expired',
                    'agent' => $agent,
                    'message' => "$agent offline (heartbeat expirado)",
                ];
            }

            // Alert: Queue acumulando
            if ($agentMetrics['pending'] > $this->alertThresholds['queue_size']) {
                $alerts[] = [
                    'severity' => 'medium',
                    'type' => 'queue_backlog',
                    'agent' => $agent,
                    'value' => $agentMetrics['pending'],
                    'threshold' => $this->alertThresholds['queue_size'],
                    'message' => "$agent com {$agentMetrics['pending']} tasks pendentes",
                ];
            }

            // Alert: Tempo de execução anormalmente alto
            // (precisaríamos de histórico para comparar)
        }

        // Verificar sistema
        if ($metrics['system']['cpu_usage'] > $this->alertThresholds['cpu_usage']) {
            $alerts[] = [
                'severity' => 'medium',
                'type' => 'high_cpu',
                'value' => $metrics['system']['cpu_usage'],
                'threshold' => $this->alertThresholds['cpu_usage'],
                'message' => "CPU usage: {$metrics['system']['cpu_usage']}% (threshold: {$this->alertThresholds['cpu_usage']}%)",
            ];
        }

        if ($metrics['system']['memory_usage'] > $this->alertThresholds['memory_usage']) {
            $alerts[] = [
                'severity' => 'medium',
                'type' => 'high_memory',
                'value' => $metrics['system']['memory_usage'],
                'threshold' => $this->alertThresholds['memory_usage'],
                'message' => "Memory usage: {$metrics['system']['memory_usage']}% (threshold: {$this->alertThresholds['memory_usage']}%)",
            ];
        }

        return $alerts;
    }

    private function saveMetrics($metrics) {
        file_put_contents(
            $this->metricsFile,
            json_encode($metrics, JSON_PRETTY_PRINT)
        );

        echo "✅ Métricas salvas em {$this->metricsFile}\n";
    }

    private function generateDashboard($metrics, $alerts) {
        $html = $this->buildDashboardHTML($metrics, $alerts);
        file_put_contents(
            'admin/agent-metrics-dashboard-realtime.html',
            $html
        );

        echo "✅ Dashboard gerado\n";
    }

    private function buildDashboardHTML($metrics, $alerts) {
        $alertRows = '';
        foreach ($alerts as $alert) {
            $severity = $alert['severity'];
            $color = $severity === 'critical' ? '#ff3333' : ($severity === 'high' ? '#ff9900' : '#ffcc00');
            $alertRows .= "<tr style='background: {$color}20;'>";
            $alertRows .= "<td>{$alert['type']}</td>";
            $alertRows .= "<td>" . ($alert['agent'] ?? 'system') . "</td>";
            $alertRows .= "<td>{$alert['message']}</td>";
            $alertRows .= "<td>" . date('H:i:s') . "</td>";
            $alertRows .= "</tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Agent Metrics Dashboard</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #ccc; margin: 20px; }
        h1 { color: #00ff00; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #222; padding: 10px; text-align: left; border-bottom: 2px solid #00ff00; }
        td { padding: 8px; border-bottom: 1px solid #333; }
        .metric { display: inline-block; width: 23%; margin: 1%; padding: 15px; background: #222; border-left: 4px solid #00ff00; }
        .metric-value { font-size: 24px; font-weight: bold; color: #00ff00; }
        .metric-label { font-size: 12px; color: #999; }
        .alert { padding: 10px; margin: 10px 0; background: #ff3333; color: white; border-radius: 4px; }
    </style>
    <meta http-equiv="refresh" content="30">
</head>
<body>
    <h1>🤖 Agent Metrics Dashboard (Auto-refresh 30s)</h1>

    <h2>📊 System Metrics</h2>
    <div class="metric">
        <div class="metric-value">{$metrics['system']['cpu_usage']}%</div>
        <div class="metric-label">CPU Usage</div>
    </div>
    <div class="metric">
        <div class="metric-value">{$metrics['system']['memory_usage']}%</div>
        <div class="metric-label">Memory</div>
    </div>
    <div class="metric">
        <div class="metric-value">{$metrics['system']['load_average']}</div>
        <div class="metric-label">Load Average</div>
    </div>

    <h2>🤖 Agent Metrics</h2>
    <table>
        <tr>
            <th>Agent</th>
            <th>Status</th>
            <th>Pending</th>
            <th>Completed</th>
            <th>Success Rate</th>
            <th>Avg Time</th>
        </tr>
        <tr>
            <td>Claude</td>
            <td>{$this->statusBadge($metrics['agents']['claude']['is_alive'])}</td>
            <td>{$metrics['agents']['claude']['pending']}</td>
            <td>{$metrics['agents']['claude']['completed']}</td>
            <td>{$metrics['agents']['claude']['success_rate']}%</td>
            <td>{$metrics['agents']['claude']['avg_execution_time']}s</td>
        </tr>
        <tr>
            <td>Gemini</td>
            <td>{$this->statusBadge($metrics['agents']['gemini']['is_alive'])}</td>
            <td>{$metrics['agents']['gemini']['pending']}</td>
            <td>{$metrics['agents']['gemini']['completed']}</td>
            <td>{$metrics['agents']['gemini']['success_rate']}%</td>
            <td>{$metrics['agents']['gemini']['avg_execution_time']}s</td>
        </tr>
        <tr>
            <td>GPT</td>
            <td>{$this->statusBadge($metrics['agents']['gpt']['is_alive'])}</td>
            <td>{$metrics['agents']['gpt']['pending']}</td>
            <td>{$metrics['agents']['gpt']['completed']}</td>
            <td>{$metrics['agents']['gpt']['success_rate']}%</td>
            <td>{$metrics['agents']['gpt']['avg_execution_time']}s</td>
        </tr>
    </table>

    <h2>🚨 Alertas Ativos ({count($alerts)})</h2>
    <table>
        <tr>
            <th>Tipo</th>
            <th>Agent</th>
            <th>Mensagem</th>
            <th>Horário</th>
        </tr>
        {$alertRows}
    </table>

    <div style="margin-top: 30px; color: #666; font-size: 12px;">
        Atualizado em: {$metrics['timestamp']}
    </div>
</body>
</html>
HTML;
    }

    private function statusBadge($isAlive) {
        return $isAlive ? '✅ ALIVE' : '❌ OFFLINE';
    }

    private function triggerAlerts($alerts) {
        // Agrupar por severidade
        $critical = array_filter($alerts, fn($a) => $a['severity'] === 'critical');
        $high = array_filter($alerts, fn($a) => $a['severity'] === 'high');

        if (!empty($critical)) {
            $this->sendAlert(
                '🚨 CRÍTICO: ' . count($critical) . ' alertas críticos',
                implode("\n", array_map(fn($a) => "- {$a['message']}", $critical))
            );
        }

        if (!empty($high)) {
            $this->sendAlert(
                '⚠️ ALTO: ' . count($high) . ' alertas de alta prioridade',
                implode("\n", array_map(fn($a) => "- {$a['message']}", $high))
            );
        }
    }

    private function sendAlert($title, $message) {
        // Enviar email
        mail(
            'fredmourao@gmail.com',
            "[SHOPVIVALIZ METRICS] $title",
            $message,
            'From: monitor@shopvivaliz.com.br'
        );

        // Registrar em log
        file_put_contents(
            '.metrics-alerts.log',
            "[" . date('Y-m-d H:i:s') . "] $title\n$message\n\n",
            FILE_APPEND
        );
    }
}

// Executar
$monitor = new MetricsProactiveMonitor();
exit($monitor->run() ? 0 : 1);
