<?php
/**
 * Git Versioning para Layouts
 * Auto-commit de layouts via git local, histórico reversível
 */
declare(strict_types=1);

namespace Core;

class GitVersioning {
    private string $repoRoot;
    private string $gitDir;
    private bool $enabled = false;

    public function __construct(string $repoRoot = null) {
        $this->repoRoot = $repoRoot ?? dirname(__DIR__);
        $this->gitDir = $this->repoRoot . '/.git';
        $this->enabled = is_dir($this->gitDir);
    }

    /**
     * Verificar se git está disponível
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Fazer commit de um layout
     * Retorna hash do commit ou false se falhar
     */
    public function commitLayout(string $pageId, string $summary = '', array $files = []): ?string {
        if (!$this->enabled) {
            return null;
        }

        try {
            $layoutFile = "{$this->repoRoot}/layouts/{$pageId}-config.json";

            // Validar que arquivo existe
            if (!file_exists($layoutFile)) {
                error_log("GitVersioning: Layout file not found: $layoutFile");
                return null;
            }

            // Adicionar arquivo ao git
            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git add " . escapeshellarg("layouts/{$pageId}-config.json") . " 2>&1";
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            if ($code !== 0) {
                error_log("GitVersioning: git add failed: " . implode("\n", $output));
                return null;
            }

            // Preparar mensagem de commit
            $timestamp = date('Y-m-d H:i:s');
            $message = "layout: {$pageId} — {$summary} ({$timestamp})";
            if (strlen($message) > 72) {
                $message = "layout: {$pageId} — " . substr($summary, 0, 40) . "...";
            }

            // Fazer commit
            $commitCmd = "cd " . escapeshellarg($this->repoRoot) . " && git commit -m " . escapeshellarg($message) . " 2>&1";
            $output = [];
            $code = 0;
            exec($commitCmd, $output, $code);

            if ($code === 0) {
                // Extrair hash do commit
                $hash = $this->getLatestCommitHash("layouts/{$pageId}-config.json");
                error_log("GitVersioning: Committed layout {$pageId}: {$hash}");
                return $hash;
            } else {
                // Pode ser "nothing to commit" — não é erro
                if (strpos(implode(' ', $output), 'nothing to commit') !== false) {
                    return null;
                }
                error_log("GitVersioning: git commit failed: " . implode("\n", $output));
                return null;
            }
        } catch (\Throwable $e) {
            error_log("GitVersioning: Exception in commitLayout: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obter histórico de um layout
     * Retorna array de commits: [{hash, author, date, message}]
     */
    public function getHistory(string $pageId, int $limit = 20): array {
        if (!$this->enabled) {
            return [];
        }

        try {
            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git log --oneline -n {$limit} -- " . escapeshellarg("layouts/{$pageId}-config.json") . " 2>&1";
            $output = [];
            exec($cmd, $output);

            $history = [];
            foreach ($output as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                preg_match('/^([a-f0-9]+)\s+(.+)$/', $line, $matches);
                if ($matches) {
                    $history[] = [
                        'hash' => $matches[1],
                        'message' => $matches[2],
                        'date' => $this->getCommitDate($matches[1]),
                        'author' => $this->getCommitAuthor($matches[1])
                    ];
                }
            }

            return $history;
        } catch (\Throwable $e) {
            error_log("GitVersioning: Exception in getHistory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Carregar conteúdo de um commit específico
     */
    public function getCommitContent(string $hash, string $pageId): ?array {
        if (!$this->enabled) {
            return null;
        }

        try {
            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git show " . escapeshellarg("{$hash}:layouts/{$pageId}-config.json") . " 2>&1";
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            if ($code === 0) {
                $content = implode("\n", $output);
                return json_decode($content, true);
            }

            return null;
        } catch (\Throwable $e) {
            error_log("GitVersioning: Exception in getCommitContent: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Revert a um commit anterior
     * Carrega o conteúdo do arquivo naquele commit (não faz checkout automático)
     */
    public function revertToCommit(string $hash, string $pageId): ?array {
        return $this->getCommitContent($hash, $pageId);
    }

    /**
     * Fazer push (envia commits para GitHub)
     * Requer acesso remoto e autenticação válida
     */
    public function pushToRemote(string $remote = 'origin', string $branch = 'main'): bool {
        if (!$this->enabled) {
            return false;
        }

        try {
            $token = getenv('GITHUB_TOKEN');
            $remoteUrl = 'https://github.com/fredmourao-ai/site-shopvivaliz.git';

            if ($token) {
                $remoteUrl = "https://{$token}@github.com/fredmourao-ai/site-shopvivaliz.git";
            }

            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git push " . escapeshellarg($remoteUrl) . " " . escapeshellarg($branch) . " 2>&1";
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);

            $success = ($code === 0);
            error_log("GitVersioning: Push to {$remote}/{$branch}: " . ($success ? 'SUCCESS' : 'FAILED'));

            return $success;
        } catch (\Throwable $e) {
            error_log("GitVersioning: Exception in pushToRemote: " . $e->getMessage());
            return false;
        }
    }

    // ── Private helpers ──

    private function getLatestCommitHash(string $filePath): ?string {
        try {
            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git rev-parse HEAD -- " . escapeshellarg($filePath) . " 2>&1";
            $output = [];
            exec($cmd, $output);

            return trim($output[0] ?? '') ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getCommitDate(string $hash): string {
        try {
            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git log -1 --format=%aI " . escapeshellarg($hash) . " 2>&1";
            $output = [];
            exec($cmd, $output);

            if (!empty($output[0])) {
                return date('Y-m-d H:i', strtotime($output[0]));
            }
        } catch (\Throwable $e) {}

        return date('Y-m-d H:i');
    }

    private function getCommitAuthor(string $hash): string {
        try {
            $cmd = "cd " . escapeshellarg($this->repoRoot) . " && git log -1 --format=%an " . escapeshellarg($hash) . " 2>&1";
            $output = [];
            exec($cmd, $output);

            return trim($output[0] ?? 'Unknown') ?: 'Unknown';
        } catch (\Throwable $e) {
            return 'Unknown';
        }
    }
}
