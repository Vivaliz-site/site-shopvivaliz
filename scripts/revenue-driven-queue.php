<?php
/**
 * 💸 Revenue-Driven Queue - Prioriza tarefas por impacto em conversão
 * Monitora: Analytics, Carrinho, Checkout
 * Se conversão cair, move tarefas críticas para o topo
 */

class RevenueDrivenQueue {
    private $queueFile = '/home/ubuntu/site-shopvivaliz/tasks-queue.json';
    private $analyticsFile = './.analytics-metrics.json';
    private $conversionThreshold = 0.05; // Alerta se cair 5%+

    public function __construct() {}

    public function run() {
        echo "💸 Revenue-Driven Queue - Analisando...\n";

        // 1. Colher métricas de conversão
        $metrics = $this->getConversionMetrics();

        echo "📊 Conversão: {$metrics['conversion_rate']}%\n";
        echo "💰 Receita (4h): R$ {$metrics['revenue']}\n";

        // 2. Detectar queda
        $hasDecline = $this->detectConversionDecline($metrics);

        if ($hasDecline) {
            echo "🚨 Queda de conversão detectada!\n";
            $this->prioritizeCheckoutTasks();
        } else {
            echo "✅ Conversão estável. Fila normal.\n";
            $this->normalizeQueue();
        }

        return true;
    }

    private function getConversionMetrics() {
        // Simular dados de Analytics/Pagar.me
        // Em produção, isso viria da API do Google Analytics ou Pagar.me

        $previousMetrics = $this->readAnalytics();

        $currentMetrics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'visits' => rand(150, 250),
            'add_to_cart' => rand(30, 60),
            'checkout_initiated' => rand(15, 40),
            'purchases' => rand(5, 20),
            'revenue' => rand(500, 2000),
            'cart_abandonment' => rand(40, 70),
        ];

        // Calcular taxa de conversão
        if ($currentMetrics['visits'] > 0) {
            $currentMetrics['conversion_rate'] = round(
                ($currentMetrics['purchases'] / $currentMetrics['visits']) * 100,
                2
            );
        }

        // Salvar para próxima comparação
        $this->writeAnalytics($currentMetrics);

        return $currentMetrics;
    }

    private function detectConversionDecline($current) {
        $previous = $this->readAnalytics();

        if (!$previous) {
            return false;
        }

        $currentRate = $current['conversion_rate'] ?? 0;
        $previousRate = $previous['conversion_rate'] ?? 0;

        $decline = (($previousRate - $currentRate) / $previousRate) * 100;

        echo "📉 Mudança: {$decline}%\n";

        return $decline >= ($this->conversionThreshold * 100);
    }

    private function prioritizeCheckoutTasks() {
        echo "🔴 ACIONANDO PRIORIDADE MÁXIMA PARA CHECKOUT\n";

        $queue = $this->readQueue();

        // 1. Encontrar todas as tasks de checkout
        $checkoutTasks = array_filter($queue, function($task) {
            return strpos($task['action'] ?? '', 'checkout') !== false ||
                   strpos($task['action'] ?? '', 'cart') !== false ||
                   strpos($task['target'] ?? '', 'checkout') !== false;
        });

        // 2. Encontrar tarefas de otimização de página
        $optimizationTasks = array_filter($queue, function($task) {
            return strpos($task['action'] ?? '', 'optimize') !== false ||
                   strpos($task['action'] ?? '', 'performance') !== false;
        });

        // 3. Reordenar: Checkout + Otimização primeiro
        $prioritized = [];

        // Colocar tarefas críticas first
        foreach ($checkoutTasks as $task) {
            $task['priority'] = 'CRITICAL';
            $task['reason'] = 'Revenue protection - checkout optimization';
            $prioritized[] = $task;
        }

        foreach ($optimizationTasks as $task) {
            $task['priority'] = 'HIGH';
            $task['reason'] = 'Revenue protection - performance';
            $prioritized[] = $task;
        }

        // Depois tarefas normais
        foreach ($queue as $task) {
            if (!isset($task['reason']) || $task['reason'] !== 'Revenue protection') {
                $task['priority'] = $task['priority'] ?? 'MEDIUM';
                $prioritized[] = $task;
            }
        }

        // Salvar fila reordenada
        $this->writeQueue($prioritized);

        // Notificar agentes
        $this->notifyAgents(
            'Revenue Alert',
            'Conversão caiu ' . $this->conversionThreshold * 100 . '%+. ' .
            'Tarefas de checkout movidas para CRÍTICO.'
        );

        // Criar tarefa urgente de investigação
        $this->createEmergencyTask([
            'task_id' => 'REVENUE-' . date('YmdHis'),
            'action' => 'diagnose_checkout_issue',
            'priority' => 'CRITICAL',
            'assigned_to' => ['claude', 'gemini'],
            'target' => 'checkout_flow',
            'reason' => 'Auto-triggered by revenue drop detection',
            'status' => 'pending',
        ]);
    }

    private function normalizeQueue() {
        echo "✅ Conversão estável. Restaurando ordem normal.\n";

        $queue = $this->readQueue();

        // Restaurar prioridades normais
        foreach ($queue as &$task) {
            if (isset($task['reason']) && strpos($task['reason'], 'Revenue protection') !== false) {
                $task['priority'] = 'MEDIUM';
                unset($task['reason']);
            }
        }

        $this->writeQueue($queue);
    }

    private function readQueue() {
        if (!file_exists($this->queueFile)) {
            return [];
        }

        $content = file_get_contents($this->queueFile);
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    private function writeQueue($queue) {
        file_put_contents(
            $this->queueFile,
            json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        echo "✅ Fila atualizada: " . count($queue) . " tasks\n";
    }

    private function readAnalytics() {
        if (!file_exists($this->analyticsFile)) {
            return null;
        }

        $content = file_get_contents($this->analyticsFile);
        return json_decode($content, true);
    }

    private function writeAnalytics($metrics) {
        file_put_contents(
            $this->analyticsFile,
            json_encode($metrics, JSON_PRETTY_PRINT)
        );
    }

    private function createEmergencyTask($task) {
        $queue = $this->readQueue();
        array_unshift($queue, $task); // Adicionar no início

        $this->writeQueue($queue);

        echo "⚠️ Task de emergência criada: {$task['task_id']}\n";
    }

    private function notifyAgents($title, $message) {
        echo "📧 Notificando agentes: $title\n";

        // Enviar para logs
        file_put_contents(
            '.revenue-alerts.log',
            "[" . date('Y-m-d H:i:s') . "] $title: $message\n",
            FILE_APPEND
        );

        // Em produção, seria Slack/Email/WhatsApp
    }
}

// Executar
$revenueDrivenQueue = new RevenueDrivenQueue();
exit($revenueDrivenQueue->run() ? 0 : 1);
