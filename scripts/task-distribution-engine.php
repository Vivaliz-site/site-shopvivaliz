<?php
/**
 * 🎯 Task Distribution Engine - Balanceia carga entre Claude, Gemini, GPT
 * Reduz contenção e acelera processamento
 */

class TaskDistributionEngine {
    private $queueFile = '/home/ubuntu/site-shopvivaliz/tasks-queue.json';
    private $agentCapacities = [
        'claude' => ['max_concurrent' => 3, 'specialties' => ['code_review', 'refactor', 'security']],
        'gemini' => ['max_concurrent' => 4, 'specialties' => ['sync', 'import', 'api_calls']],
        'gpt' => ['max_concurrent' => 2, 'specialties' => ['analysis', 'fallback', 'special']],
    ];
    private $agentLoads = [];

    public function __construct() {
        $this->loadAgentStatus();
    }

    public function distribute() {
        echo "🎯 Task Distribution Engine iniciado...\n";

        $queue = $this->readQueue();

        if (empty($queue)) {
            echo "✅ Fila vazia\n";
            return true;
        }

        // Separar por prioridade
        $byPriority = $this->groupByPriority($queue);

        foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $priority) {
            if (!empty($byPriority[$priority])) {
                echo "📋 Processando $priority tasks (" . count($byPriority[$priority]) . ")\n";
                $this->assignToAgents($byPriority[$priority]);
            }
        }

        $this->saveDistribution();

        return true;
    }

    private function groupByPriority($queue) {
        $grouped = [
            'CRITICAL' => [],
            'HIGH' => [],
            'MEDIUM' => [],
            'LOW' => [],
        ];

        foreach ($queue as $task) {
            $priority = $task['priority'] ?? 'MEDIUM';
            $grouped[$priority][] = $task;
        }

        return $grouped;
    }

    private function assignToAgents($tasks) {
        foreach ($tasks as $task) {
            $agent = $this->selectBestAgent($task);

            if ($agent) {
                $this->assignTask($task, $agent);
                echo "  ✅ {$task['task_id']} → $agent\n";
            } else {
                echo "  ⚠️ {$task['task_id']} → Fila (todos agentes ocupados)\n";
            }
        }
    }

    private function selectBestAgent($task) {
        $action = $task['action'] ?? '';
        $priority = $task['priority'] ?? 'MEDIUM';

        // 1. Checar se task já está assinalada
        if (!empty($task['assigned_to'])) {
            $assignedAgent = is_array($task['assigned_to']) ? $task['assigned_to'][0] : $task['assigned_to'];

            if ($this->canAcceptTask($assignedAgent, $priority)) {
                return $assignedAgent;
            }
        }

        // 2. Encontrar agente por especialidade
        $bestAgent = null;
        $lowestLoad = PHP_INT_MAX;

        foreach ($this->agentCapacities as $agent => $config) {
            $load = $this->agentLoads[$agent] ?? 0;

            // Se agente está especializado nesta tarefa
            if (in_array($action, $config['specialties'])) {
                if ($load < $lowestLoad && $this->canAcceptTask($agent, $priority)) {
                    $bestAgent = $agent;
                    $lowestLoad = $load;
                }
            }
        }

        // 3. Se nenhuma especialidade match, pegar o menos ocupado
        if (!$bestAgent) {
            foreach ($this->agentCapacities as $agent => $config) {
                $load = $this->agentLoads[$agent] ?? 0;

                if ($load < $lowestLoad && $this->canAcceptTask($agent, $priority)) {
                    $bestAgent = $agent;
                    $lowestLoad = $load;
                }
            }
        }

        return $bestAgent;
    }

    private function canAcceptTask($agent, $priority) {
        $currentLoad = $this->agentLoads[$agent] ?? 0;
        $maxCapacity = $this->agentCapacities[$agent]['max_concurrent'] ?? 3;

        // CRITICAL tasks não têm limite
        if ($priority === 'CRITICAL') {
            return true;
        }

        return $currentLoad < $maxCapacity;
    }

    private function assignTask($task, $agent) {
        $task['assigned_to'] = $agent;
        $task['status'] = 'pending';
        $task['assigned_at'] = date('Y-m-d H:i:s');

        // Incrementar carga do agente
        $this->agentLoads[$agent] = ($this->agentLoads[$agent] ?? 0) + 1;

        // Salvar em file específico do agente
        $agentFile = ".agent-queue-{$agent}.json";
        $agentQueue = json_decode(file_get_contents($agentFile) ?: '[]', true);
        $agentQueue[] = $task;

        file_put_contents($agentFile, json_encode($agentQueue, JSON_PRETTY_PRINT));
    }

    private function loadAgentStatus() {
        // Carregar status dos agentes (quantas tasks estão processando)
        foreach (array_keys($this->agentCapacities) as $agent) {
            $file = ".agent-queue-{$agent}.json";
            if (file_exists($file)) {
                $queue = json_decode(file_get_contents($file), true);
                $this->agentLoads[$agent] = count(array_filter($queue, fn($t) => $t['status'] === 'pending'));
            }
        }

        echo "📊 Carga atual: Claude={$this->agentLoads['claude']}, " .
             "Gemini={$this->agentLoads['gemini']}, " .
             "GPT={$this->agentLoads['gpt']}\n";
    }

    private function readQueue() {
        if (!file_exists($this->queueFile)) {
            return [];
        }

        return json_decode(file_get_contents($this->queueFile), true) ?: [];
    }

    private function saveDistribution() {
        $distribution = [
            'timestamp' => date('Y-m-d H:i:s'),
            'agent_loads' => $this->agentLoads,
            'total_pending' => array_sum($this->agentLoads),
        ];

        file_put_contents('.task-distribution.json', json_encode($distribution, JSON_PRETTY_PRINT));
    }
}

// Executar
$engine = new TaskDistributionEngine();
exit($engine->distribute() ? 0 : 1);
