<?php
declare(strict_types=1);

/**
 * Backup & Restore System
 * Requirement 45: Backup queue, memory, metrics, restore safely
 */

class BackupManager
{
    private const BACKUP_DIR = __DIR__ . '/../../backups/autonomous';
    private const BACKUP_MANIFEST = self::BACKUP_DIR . '/manifest.json';

    /**
     * Create full backup
     */
    public static function createFullBackup(): array
    {
        $backupId = date('Y-m-d-H-i-s');
        $backupPath = self::BACKUP_DIR . '/' . $backupId;
        if (!self::ensureDirectory($backupPath)) {
            return [
                'success' => false,
                'reason' => 'backup_target_unavailable',
                'backup_id' => $backupId,
                'items' => []
            ];
        }

        $backup = [
            'success' => true,
            'backup_id' => $backupId,
            'timestamp' => date('c'),
            'items' => []
        ];

        // Backup queue
        $queueFile = __DIR__ . '/../../tasks-queue.json';
        if (file_exists($queueFile)) {
            if (copy($queueFile, "$backupPath/tasks-queue.json")) {
                $backup['items'][] = 'tasks-queue';
            }
        }

        // Backup memories
        $logsDir = __DIR__ . '/../../logs/autonomous';
        foreach (glob("$logsDir/*.jsonl") as $file) {
            $name = basename($file);
            if (copy($file, "$backupPath/$name")) {
                $backup['items'][] = $name;
            }
        }

        // Backup metrics
        $agentsDir = __DIR__ . '/../../logs/agents';
        foreach (glob("$agentsDir/*.json") as $file) {
            $name = basename($file);
            if (copy($file, "$backupPath/$name")) {
                $backup['items'][] = $name;
            }
        }

        // Save manifest
        $backup['manifest_updated'] = self::appendManifest($backup);

        return $backup;
    }

    /**
     * Restore from backup
     */
    public static function restore(string $backupId): array
    {
        $backupPath = self::BACKUP_DIR . '/' . $backupId;
        if (!is_dir($backupPath)) {
            return ['success' => false, 'reason' => 'Backup not found'];
        }

        $restored = ['timestamp' => date('c'), 'items' => []];

        // Restore queue
        $queueBackup = "$backupPath/tasks-queue.json";
        if (file_exists($queueBackup)) {
            $queueTarget = __DIR__ . '/../../tasks-queue.json';
            if (self::isWritableParent($queueTarget) && copy($queueBackup, $queueTarget)) {
                $restored['items'][] = 'tasks-queue';
            }
        }

        // Restore memories
        $destDir = __DIR__ . '/../../logs/autonomous';
        foreach (glob("$backupPath/*.jsonl") as $file) {
            if (self::ensureDirectory($destDir) && copy($file, "$destDir/" . basename($file))) {
                $restored['items'][] = basename($file);
            }
        }

        // Restore metrics
        $metricsDir = __DIR__ . '/../../logs/agents';
        foreach (glob("$backupPath/*-productivity.json") as $file) {
            if (self::ensureDirectory($metricsDir) && copy($file, "$metricsDir/" . basename($file))) {
                $restored['items'][] = basename($file);
            }
        }

        $restored['success'] = true;
        return $restored;
    }

    /**
     * Validate backup (test restore)
     */
    public static function validateBackup(string $backupId): bool
    {
        $backupPath = self::BACKUP_DIR . '/' . $backupId;
        if (!is_dir($backupPath)) {
            return false;
        }

        // Check queue is valid JSON
        $queueFile = "$backupPath/tasks-queue.json";
        if (file_exists($queueFile)) {
            $data = json_decode(file_get_contents($queueFile), true);
            if (!is_array($data)) {
                return false;
            }
        }

        // Check JSONL files are valid
        foreach (glob("$backupPath/*.jsonl") as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (!json_decode($line, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * List backups
     */
    public static function listBackups(): array
    {
        $backups = [];
        foreach (glob(self::BACKUP_DIR . '/20*') as $dir) {
            $backupId = basename($dir);
            $backups[] = [
                'backup_id' => $backupId,
                'size_mb' => self::getDirSize($dir) / 1024 / 1024,
                'valid' => self::validateBackup($backupId)
            ];
        }

        return array_reverse($backups); // Newest first
    }

    /**
     * Append to manifest
     */
    private static function appendManifest(array $backup): bool
    {
        if (!self::ensureDirectory(self::BACKUP_DIR) || !is_writable(self::BACKUP_DIR)) {
            return false;
        }

        $manifest = [];
        if (file_exists(self::BACKUP_MANIFEST)) {
            $manifest = json_decode(file_get_contents(self::BACKUP_MANIFEST), true) ?? [];
        }

        $manifest['backups'][] = $backup;
        $manifest['last_backup'] = $backup['timestamp'];

        return file_put_contents(
            self::BACKUP_MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        ) !== false;
    }

    /**
     * Helper: get directory size
     */
    private static function getDirSize(string $dir): int
    {
        $size = 0;
        foreach (glob("$dir/*") as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            } elseif (is_dir($file)) {
                $size += self::getDirSize($file);
            }
        }
        return $size;
    }

    /**
     * Cleanup old backups (keep last 7 days)
     */
    public static function cleanupOldBackups(int $daysToKeep = 7): int
    {
        $cutoff = time() - ($daysToKeep * 86400);
        $deleted = 0;

        foreach (glob(self::BACKUP_DIR . '/20*', GLOB_ONLYDIR) as $dir) {
            if (filemtime($dir) < $cutoff) {
                self::deleteDir($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Helper: recursively delete directory
     */
    private static function deleteDir(string $dir): void
    {
        foreach (glob("$dir/*") as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                self::deleteDir($file);
            }
        }
        rmdir($dir);
    }

    private static function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            return false;
        }

        if (!self::ensureDirectory($parent)) {
            return false;
        }

        return mkdir($dir, 0755, true) || is_dir($dir);
    }

    private static function isWritableParent(string $path): bool
    {
        $parent = dirname($path);
        return is_dir($parent) && is_writable($parent);
    }
}
