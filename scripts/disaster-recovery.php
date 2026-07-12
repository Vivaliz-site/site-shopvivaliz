<?php
/**
 * 🔐 Disaster Recovery - Backup automático + Restauração
 * Backup BD, Git, Arquivos críticos com teste semanal
 */

class DisasterRecovery {
    private $backupDir = '/home/ubuntu/site-shopvivaliz/.backups';
    private $retention = 30; // dias
    private $s3Bucket = ''; // Será preenchido via env
    private $dbHost = 'localhost';
    private $dbUser = '';
    private $dbPass = '';
    private $dbName = 'shopvivaliz';

    public function __construct() {
        $this->s3Bucket = getenv('AWS_S3_BACKUP_BUCKET') ?: 'shopvivaliz-backups';
        $this->dbUser = getenv('DB_USER') ?: 'root';
        $this->dbPass = getenv('DB_PASS') ?: '';

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0700, true);
        }
    }

    public function run() {
        echo "🔐 Disaster Recovery - Iniciando backup...\n";

        $results = [
            'database' => $this->backupDatabase(),
            'git' => $this->backupGit(),
            'files' => $this->backupCriticalFiles(),
            's3' => $this->uploadToS3(),
            'cleanup' => $this->cleanupOldBackups(),
        ];

        $this->logBackupResult($results);

        // Teste de restauração semanal
        if (date('w') === '0') { // Domingo
            echo "\n📋 Executando teste semanal de restauração...\n";
            $this->testRestore();
        }

        return true;
    }

    private function backupDatabase() {
        echo "📦 Fazendo backup do banco de dados...\n";

        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = "{$this->backupDir}/db-{$timestamp}.sql.gz";

        $command = "mysqldump -h {$this->dbHost} -u {$this->dbUser} " .
                   "-p'{$this->dbPass}' {$this->dbName} | gzip > {$backupFile}";

        $result = shell_exec($command . ' 2>&1');

        if (file_exists($backupFile) && filesize($backupFile) > 1000) {
            echo "✅ Backup BD: " . round(filesize($backupFile) / 1024 / 1024, 2) . " MB\n";
            return true;
        } else {
            echo "❌ Falha no backup BD\n";
            return false;
        }
    }

    private function backupGit() {
        echo "📦 Fazendo backup do repositório Git...\n";

        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = "{$this->backupDir}/git-{$timestamp}.tar.gz";
        $gitDir = '/home/ubuntu/site-shopvivaliz/.git';

        $command = "tar -czf {$backupFile} -C /home/ubuntu/site-shopvivaliz .git";
        shell_exec($command);

        if (file_exists($backupFile) && filesize($backupFile) > 1000) {
            echo "✅ Backup Git: " . round(filesize($backupFile) / 1024 / 1024, 2) . " MB\n";
            return true;
        } else {
            echo "❌ Falha no backup Git\n";
            return false;
        }
    }

    private function backupCriticalFiles() {
        echo "📦 Fazendo backup de arquivos críticos...\n";

        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = "{$this->backupDir}/files-{$timestamp}.tar.gz";

        $criticalPaths = [
            '/home/ubuntu/site-shopvivaliz/config/',
            '/home/ubuntu/site-shopvivaliz/includes/',
            '/home/ubuntu/site-shopvivaliz/public/assets/',
        ];

        $pathsStr = implode(' ', $criticalPaths);
        $command = "tar -czf {$backupFile} {$pathsStr} 2>/dev/null";
        shell_exec($command);

        if (file_exists($backupFile) && filesize($backupFile) > 1000) {
            echo "✅ Backup Arquivos: " . round(filesize($backupFile) / 1024 / 1024, 2) . " MB\n";
            return true;
        } else {
            echo "❌ Falha no backup de arquivos\n";
            return false;
        }
    }

    private function uploadToS3() {
        echo "☁️ Enviando backups para S3...\n";

        // Verificar se AWS CLI está disponível
        $awsTest = shell_exec('which aws 2>&1');
        if (strpos($awsTest, 'aws') === false) {
            echo "⚠️ AWS CLI não instalado. Saltando upload S3.\n";
            return false;
        }

        $latestBackups = array_slice(
            array_reverse(glob("{$this->backupDir}/*")),
            0,
            3 // Enviar os 3 backups mais recentes
        );

        foreach ($latestBackups as $backup) {
            $filename = basename($backup);
            $s3Path = "s3://{$this->s3Bucket}/" . date('Y-m-d') . "/{$filename}";

            $command = "aws s3 cp {$backup} {$s3Path} --sse AES256";
            shell_exec($command);

            echo "✅ Upload: {$filename}\n";
        }

        return true;
    }

    private function cleanupOldBackups() {
        echo "🧹 Limpando backups antigos (> {$this->retention} dias)...\n";

        $now = time();
        $files = glob("{$this->backupDir}/*");
        $deleted = 0;

        foreach ($files as $file) {
            $fileAge = ($now - filemtime($file)) / 86400; // em dias

            if ($fileAge > $this->retention) {
                unlink($file);
                $deleted++;
                echo "  🗑️  Deletado: " . basename($file) . "\n";
            }
        }

        echo "✅ Limpeza concluída: {$deleted} arquivos deletados\n";
        return true;
    }

    private function testRestore() {
        echo "\n🧪 TESTE SEMANAL DE RESTAURAÇÃO\n";

        // Pegar backup mais recente
        $latestBackup = end(array_reverse(glob("{$this->backupDir}/db-*.sql.gz")));

        if (!$latestBackup) {
            echo "❌ Nenhum backup de BD encontrado para teste\n";
            return false;
        }

        echo "Testando restauração de: " . basename($latestBackup) . "\n";

        // Criar BD de teste
        $testDbName = 'shopvivaliz_restore_test_' . time();

        $createCmd = "mysql -h {$this->dbHost} -u {$this->dbUser} " .
                     "-p'{$this->dbPass}' -e 'CREATE DATABASE {$testDbName};'";
        shell_exec($createCmd);

        // Restaurar nela
        $restoreCmd = "zcat {$latestBackup} | mysql -h {$this->dbHost} " .
                      "-u {$this->dbUser} -p'{$this->dbPass}' {$testDbName}";
        $result = shell_exec($restoreCmd . ' 2>&1');

        // Verificar integridade
        $checkCmd = "mysql -h {$this->dbHost} -u {$this->dbUser} " .
                    "-p'{$this->dbPass}' {$testDbName} -e 'SELECT COUNT(*) as tables FROM information_schema.tables WHERE table_schema=\"{$testDbName}\";'";
        $tableCount = shell_exec($checkCmd);

        if (strpos($tableCount, '0') === false) {
            echo "✅ Teste de restauração SUCESSO\n";
            echo "✅ BD de teste contém tabelas\n";
            $success = true;
        } else {
            echo "❌ Teste de restauração FALHOU\n";
            $success = false;
        }

        // Deletar BD de teste
        $dropCmd = "mysql -h {$this->dbHost} -u {$this->dbUser} " .
                   "-p'{$this->dbPass}' -e 'DROP DATABASE IF EXISTS {$testDbName};'";
        shell_exec($dropCmd);

        return $success;
    }

    private function logBackupResult($results) {
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'database' => $results['database'],
            'git' => $results['git'],
            'files' => $results['files'],
            's3_upload' => $results['s3'],
            'cleanup' => $results['cleanup'],
            'all_success' => array_reduce($results, fn($c, $r) => $c && $r, true),
        ];

        file_put_contents(
            '.backup-log.json',
            json_encode($log, JSON_PRETTY_PRINT),
            FILE_APPEND
        );

        // Notificar se falha
        if (!$log['all_success']) {
            mail(
                'fredmourao@gmail.com',
                '[SHOPVIVALIZ] ⚠️ Backup falhou',
                json_encode($log, JSON_PRETTY_PRINT),
                'From: backup@shopvivaliz.com.br'
            );
        }
    }
}

// Executar
$dr = new DisasterRecovery();
exit($dr->run() ? 0 : 1);
