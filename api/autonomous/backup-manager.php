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
        @mkdir(self::BACKUP_DIR, 0755, true);

        $backupId = date('Y-m-d-H-i-s');
        $backupPath = self::BACKUP_DIR . '/' . $backupId;
        @mkdir($backupPath, 0755, true);

        $backup = [
            'backup_id' => $backupId,
            'timestamp' => date('c'),
            'items' => []
        ];

        // Backup queue
        $queueFile = __DIR__ . '/../../tasks-queue.json';
        if (file_exists($queueFile)) {
            copy($queueFile, "$backupPath/tasks-queue.json");
            $backup['items'][] = 'tasks-queue';
        }

        // Backup memories
        $logsDir = __DIR__ . '/../../logs/autonomous';
        foreach (glob("$logsDir/*.jsonl") as $file) {
            $name = basename($file);
            copy($file, "$backupPath/$name");
            $backup['items'][] = $name;
        }

        // Backup metrics
        $agentsDir = __DIR__ . '/../../logs/agents';
        foreach (glob("$agentsDir/*.json") as $file) {
            $name = basename($file);
            copy($file, "$backupPath/$name");
            $backup['items'][] = $name;
        }

        // Save manifest
        self::appendManifest($backup);

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
            copy($queueBackup, __DIR__ . '/../../tasks-queue.json');
            $restored['items'][] = 'tasks-queue';
        }

        // Restore memories
        foreach (glob("$backupPath/*.jsonl") as $file) {
            $destDir = __DIR__ . '/../../logs/autonomous';
            @mkdir($destDir, 0755, true);
            copy($file, "$destDir/" . basename($file));
            $restored['items'][] = basename($file);
        }

        // Restore metrics
        foreach (glob("$backupPath/*-productivity.json") as $file) {
            $destDir = __DIR__ . '/../../logs/agents';
            @mkdir($destDir, 0755, true);
            copy($file, "$destDir/" . basename($file));
            $restored['items'][] = basename($file);
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
    private static function appendManifest(array $backup): void
    {
        $manifest = [];
        if (file_exists(self::BACKUP_MANIFEST)) {
            $manifest = json_decode(file_get_contents(self::BACKUP_MANIFEST), true) ?? [];
        }

        $manifest['backups'][] = $backup;
        $manifest['last_backup'] = $backup['timestamp'];

        file_put_contents(
            self::BACKUP_MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
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
}
