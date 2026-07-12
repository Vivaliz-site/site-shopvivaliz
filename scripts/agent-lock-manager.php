<?php
/**
 * Agent Lock Manager - Previne race conditions entre agentes
 * Garante que apenas um agente trabalhe em um arquivo por vez
 */

declare(strict_types=1);

class AgentLockManager {
    private $lockDir = __DIR__ . '/../.agent-locks';
    private $agentId;
    private $lockTimeout = 300; // 5 minutos

    public function __construct(string $agentId = 'unknown') {
        $this->agentId = $agentId;
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }

    /**
     * Adquirir lock exclusivo para um arquivo
     */
    public function acquireLock(string $filePath): bool {
        $lockFile = $this->getLockPath($filePath);
        
        // Verificar se lock já existe
        if (file_exists($lockFile)) {
            $lockData = json_decode(file_get_contents($lockFile), true);
            $lockAge = time() - ($lockData['timestamp'] ?? 0);
            
            // Se lock expirou, remover
            if ($lockAge > $this->lockTimeout) {
                unlink($lockFile);
            } else {
                // Lock ainda está válido, negar acesso
                return false;
            }
        }

        // Criar novo lock
        $lockData = [
            'agent_id' => $this->agentId,
            'timestamp' => time(),
            'file_path' => $filePath,
            'pid' => getmypid()
        ];

        file_put_contents($lockFile, json_encode($lockData));
        return true;
    }

    /**
     * Liberar lock
     */
    public function releaseLock(string $filePath): void {
        $lockFile = $this->getLockPath($filePath);
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Verificar se arquivo está locked
     */
    public function isLocked(string $filePath): bool {
        $lockFile = $this->getLockPath($filePath);
        if (!file_exists($lockFile)) {
            return false;
        }

        $lockData = json_decode(file_get_contents($lockFile), true);
        $lockAge = time() - ($lockData['timestamp'] ?? 0);

        // Se lock expirou, remover
        if ($lockAge > $this->lockTimeout) {
            unlink($lockFile);
            return false;
        }

        return true;
    }

    /**
     * Obter informações do lock
     */
    public function getLockInfo(string $filePath): ?array {
        $lockFile = $this->getLockPath($filePath);
        if (!file_exists($lockFile)) {
            return null;
        }

        return json_decode(file_get_contents($lockFile), true);
    }

    /**
     * Caminho do arquivo de lock
     */
    private function getLockPath(string $filePath): string {
        $hash = md5($filePath);
        return $this->lockDir . '/' . $hash . '.lock';
    }

    /**
     * Aguardar até conseguir lock (com timeout)
     */
    public function waitForLock(string $filePath, int $maxWait = 30): bool {
        $startTime = time();
        $waitInterval = 1; // 1 segundo

        while ((time() - $startTime) < $maxWait) {
            if ($this->acquireLock($filePath)) {
                return true;
            }
            sleep($waitInterval);
        }

        return false;
    }
}

// Exemplo de uso
$lockManager = new AgentLockManager($_ENV['AGENT_ID'] ?? 'claude');

// Ao iniciar uma tarefa
$file = '/c/site-shopvivaliz/tasks-queue.json';
if ($lockManager->acquireLock($file)) {
    try {
        // Trabalhar no arquivo
        echo "✅ Lock adquirido para: $file\n";
        // ... fazer modificações ...
    } finally {
        $lockManager->releaseLock($file);
        echo "✅ Lock liberado para: $file\n";
    }
} else {
    $lockInfo = $lockManager->getLockInfo($file);
    echo "❌ Arquivo locked por: {$lockInfo['agent_id']} (PID: {$lockInfo['pid']})\n";
    echo "⏳ Aguardando...\n";

    if ($lockManager->waitForLock($file, 60)) {
        echo "✅ Lock obtido após espera\n";
    } else {
        echo "❌ Timeout ao aguardar lock\n";
    }
}
