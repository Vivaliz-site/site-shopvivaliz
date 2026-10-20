<?php
declare(strict_types=1);

/**
 * Scoped, human-operated Marina Pricing repair. No automatic deployment or unattended execution.
 * Requires an independently authorized interactive ROOT login on Marina (not an agent privilege bypass).
 * Never writes to source, marketplace providers, or production code/releases.
 */
final class MarinaPricingRecovery
{
    public const ENV_FILE = '/home/ubuntu/ml-pricing-api-deploy/shared/runtime.env';
    public const SERVICE = 'ml-pricing-worker.service';
    private const SCHEMA = 'ml_pricing_prod';

    public static function config(string $env): array
    {
        if (preg_match_all('/^DATABASE_URL=(.*)\r?$/m', $env, $m) !== 1) {
            throw new InvalidArgumentException('DATABASE_URL must occur once');
        }
        $url = trim($m[1][0]);
        if (strlen($url) >= 2 && ($url[0] === '"' || $url[0] === "'")) {
            if (substr($url, -1) !== $url[0]) {
                throw new InvalidArgumentException('Unmatched quote in DATABASE_URL');
            }
            $url = substr($url, 1, -1);
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'mysql'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || !in_array($parts['port'] ?? null, [13312, 3306], true)
            || ltrim($parts['path'] ?? '', '/') !== self::SCHEMA
        ) {
            throw new InvalidArgumentException('Unsafe Pricing DB target');
        }
        $username = rawurldecode($parts['user'] ?? '');
        $password = rawurldecode($parts['pass'] ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $username)
            || in_array(strtolower($username), ['root', 'mysql', 'admin'], true)
            || $password === '' || strlen($password) > 512) {
            throw new InvalidArgumentException('Unsafe application DB principal');
        }
        return [
            'hostname' => $parts['host'],
            'port' => $parts['port'],
            'database' => self::SCHEMA,
            'username' => $username,
            'password' => $password,
        ];
    }

    public static function rewritePort(string $env): string
    {
        $parsed = self::config($env);
        if ($parsed['port'] === 3306) {
            return $env;
        }
        $changed = 0;
        $output = preg_replace_callback('/^DATABASE_URL=(.*)\r?$/m', static function (array $match) use (&$changed): string {
            $original = $match[0];
            $rewritten = preg_replace('~127\\.0\\.0\\.1:13312/(?=ml_pricing_prod(?:[?"\']|$))~',
                '127.0.0.1:3306/', $original, 1, $count);
            $changed += $count;
            return (string) $rewritten;
        }, $env);
        if ($changed !== 1 || !is_string($output) || self::config($output)['port'] !== 3306) {
            throw new InvalidArgumentException('Unexpected DATABASE_URL representation');
        }
        return $output;
    }

    public static function summary(array $config): array
    {
        return [
            'db_target' => '127.0.0.1:' . (string) $config['port'],
            'schema' => self::SCHEMA,
            'app_account_present' => $config['username'] !== '',
            'app_password_present' => $config['password'] !== '',
        ];
    }

    public static function countFingerprint(array $counts): string
    {
        ksort($counts, SORT_STRING);
        return hash('sha256', json_encode($counts, JSON_THROW_ON_ERROR));
    }

    public static function assertGeneralLogOff(string $setting): void
    {
        if (!in_array(strtoupper(trim($setting)), ['0', 'OFF'], true)) {
            throw new InvalidArgumentException('General query logging must be disabled');
        }
    }

    public static function assertOriginalDbPortUnbound(bool $listenerPresent): void
    {
        if ($listenerPresent) {
            throw new InvalidArgumentException('Original port 13312 is already occupied; select DB owner first');
        }
    }

    private static function assertNoExistingPricingDbListener(): void
    {
        // Another agent may start the validation MySQL concurrently. Never repoint while active.
        $connection = @stream_socket_client('tcp://127.0.0.1:13312', $errorNumber, $errorString, 1);
        if (is_resource($connection)) {
            fclose($connection);
            self::assertOriginalDbPortUnbound(true);
        }
    }

    public static function tableCounts(PDO $db): array
    {
        $names = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA='ml_pricing_prod' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($names) || count($names) !== 27) {
            throw new RuntimeException('FAIL_TARGET_PRICING_SCHEMA_COUNT');
        }
        $counts = [];
        foreach ($names as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
                throw new RuntimeException('FAIL_INVALID_TABLE_NAME');
            }
            $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM `ml_pricing_prod`.`$table`")->fetchColumn();
        }
        if (($counts['doctrine_migration_versions'] ?? null) !== 16) {
            throw new RuntimeException('FAIL_MIGRATION_VERSION_COUNT');
        }
        return $counts;
    }

    public static function assertRecentSourceTimestamp(string $stamp, int $now): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $stamp)) {
            throw new InvalidArgumentException('Source proof must have UTC ISO timestamp');
        }
        $timestamp = strtotime($stamp);
        if ($timestamp === false || $timestamp > $now + 30 || $timestamp < $now - 600) {
            throw new InvalidArgumentException('Source proof is not current');
        }
    }

    private static function execSystemctl(string $action): void
    {
        $allowed = ['start', 'stop', 'is-active'];
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException('INVALID_UNIT_ACTION');
        }
        exec('/usr/bin/systemctl ' . $action . ' ' . self::SERVICE . ' >/dev/null 2>&1', $unused, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('FAIL_UNIT_' . strtoupper(str_replace('-', '_', $action)));
        }
    }

    public static function cli(array $argv): int
    {
        if (gethostname() !== 'marina-a1-4c24g') {
            throw new RuntimeException('FAIL_WRONG_HOST');
        }
        $mode = $argv[1] ?? '--check';
        if (!in_array($mode, ['--check', '--apply'], true)) {
            throw new RuntimeException('USAGE_CHECK_OR_APPLY');
        }
        $path = self::ENV_FILE;
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('FAIL_ENV_NOT_READABLE');
        }
        $data = self::config($contents);
        echo 'PRICING_MAINTENANCE_MODE=' . ($mode === '--apply' ? 'INTERACTIVE_APPLY' : 'READ_ONLY_CHECK') . "\n";
        echo 'PRICING_DB_LOCAL_PORT=' . $data['port'] . "\n";
        echo 'PRICING_DB_SCHEMA=' . $data['database'] . "\n";
        if ($mode === '--check') {
            echo "PRICING_CHECK_NO_MUTATIONS=true\n";
            return 0;
        }
        // Only a real operator's independent, interactive, root-level console may execute this.
        if (posix_geteuid() !== 0 || !stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            throw new RuntimeException('FAIL_REQUIRE_INTERACTIVE_OPERATOR_ROOT');
        }
        if (file_exists('/etc/shopvivaliz/migration/origin-workers-fenced')) {
            throw new RuntimeException('FAIL_SOURCE_HOST_FENCE_MARKER_PRESENT');
        }
        $confirm = in_array('--confirm=MARINA_PRICING_ONCE', $argv, true);
        $sourceFenced = in_array('--source-fence-verified', $argv, true);
        $sourceHash = null;
        $sourceCheckedAt = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--source-fingerprint=')) {
                $sourceHash = substr($arg, strlen('--source-fingerprint='));
            }
            if (str_starts_with($arg, '--source-checked-at=')) {
                $sourceCheckedAt = substr($arg, strlen('--source-checked-at='));
            }
        }
        if (!$confirm || !$sourceFenced || !is_string($sourceHash)
            || !preg_match('/^[0-9a-f]{64}$/D', $sourceHash)) {
            throw new RuntimeException('FAIL_SOURCE_PARITY_OR_CONFIRMATION_MISSING');
        }
        if (!is_string($sourceCheckedAt)) {
            throw new RuntimeException('FAIL_SOURCE_TIMESTAMP_MISSING');
        }
        try { self::assertRecentSourceTimestamp($sourceCheckedAt, time()); }
        catch (InvalidArgumentException $error) { throw new RuntimeException('FAIL_SOURCE_TIMESTAMP_STALE'); }
        if ($data['port'] !== 13312) {
            throw new RuntimeException('FAIL_UNEXPECTED_SOURCE_DB_PORT');
        }
        try { self::assertNoExistingPricingDbListener(); }
        catch (InvalidArgumentException $error) { throw new RuntimeException('FAIL_ORIGINAL_PRICING_DB_ALREADY_LISTENING'); }
        if (self::unitActive('shopvivaliz-amazon-returns.service')) {
            throw new RuntimeException('FAIL_ALTERNATE_AMAZON_WORKER_ACTIVE');
        }
        if (self::unitActive(self::SERVICE)) {
            throw new RuntimeException('FAIL_PRICING_ALREADY_ACTIVE');
        }
        $shared = dirname($path);
        $lockFile = fopen($shared . '/.marina-pricing-repair.lock', 'c');
        if (!is_resource($lockFile) || !flock($lockFile, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('FAIL_REPAIR_LOCK_BUSY');
        }
        $originalHash = hash_file('sha256', $path);
        $info = stat($path);
        if (!is_array($info) || ($info['mode'] & 0777) !== 0640) {
            throw new RuntimeException('FAIL_ENV_PERMISSION_BASELINE');
        }
        $admin = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=mysql;charset=utf8mb4',
            'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        try { self::assertGeneralLogOff((string) $admin->query('SELECT @@global.general_log')->fetchColumn()); }
        catch (InvalidArgumentException $error) { throw new RuntimeException('FAIL_DB_GENERAL_LOG_ENABLED'); }
        $counts = self::tableCounts($admin);
        $hash = self::countFingerprint($counts);
        if (!hash_equals($sourceHash, $hash)) {
            echo 'PRICING_TARGET_COUNT_FINGERPRINT=' . $hash . "\n";
            throw new RuntimeException('FAIL_TARGET_SOURCE_ROWCOUNTS_MISMATCH');
        }
        echo "PRICING_SOURCE_TARGET_TABLE_COUNTS=EQUAL\n";
        $principal = $admin->quote($data['username']) . "@'127.0.0.1'";
        $stmt = $admin->prepare("SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host='127.0.0.1'");
        $stmt->execute([$data['username']]);
        $exists = (int) $stmt->fetchColumn() !== 0;
        if (!$exists) {
            $admin->exec('CREATE USER ' . $principal . ' IDENTIFIED BY ' . $admin->quote($data['password']));
            echo "PRICING_DB_PRINCIPAL=CREATED_WITH_NARROW_SCOPE\n";
        } else {
            echo "PRICING_DB_PRINCIPAL=ALREADY_PRESENT_NO_ROTATION\n";
        }
        $admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `ml_pricing_prod`.* TO ' . $principal);
        // Verify the exact application credentials from the protected file, never print them.
        $app = new PDO('mysql:host=127.0.0.1;port=3306;dbname=ml_pricing_prod;charset=utf8mb4',
            $data['username'], $data['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        if ((int) $app->query('SELECT 1')->fetchColumn() !== 1) {
            throw new RuntimeException('FAIL_APPLICATION_DB_AUTH');
        }
        $app = null;
        echo "PRICING_DB_3306_AUTH=PASS\n";
        if (!hash_equals((string) $originalHash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('FAIL_ENV_CONCURRENT_MODIFICATION');
        }
        try { self::assertNoExistingPricingDbListener(); }
        catch (InvalidArgumentException $error) { throw new RuntimeException('FAIL_ORIGINAL_PORT_BECAME_ACTIVE'); }
        $rewritten = self::rewritePort($contents);
        // Backup is root-readable only and deliberately retained for approved rollback.
        umask(0077);
        $backup = $path . '.pre-repair-' . gmdate('YmdTHis') . 'Z';
        if (file_put_contents($backup, $contents, LOCK_EX) !== strlen($contents) || !chmod($backup, 0600)) {
            throw new RuntimeException('FAIL_RESTRICTED_BACKUP');
        }
        $temp = tempnam($shared, '.marina-pricing-');
        if ($temp === false || file_put_contents($temp, $rewritten, LOCK_EX) !== strlen($rewritten)
            || !chown($temp, (int) $info['uid']) || !chgrp($temp, (int) $info['gid'])
            || !chmod($temp, 0640) || !rename($temp, $path)) {
            throw new RuntimeException('FAIL_ATOMIC_CONFIG_UPDATE');
        }
        try {
            self::execSystemctl('start');
            sleep(8);
            self::execSystemctl('is-active');
            echo "PRICING_WORKER_ACTIVE=PASS\n";
            echo "PRICING_AUTOSTART=UNCHANGED_DISABLED\n";
            return 0;
        } catch (Throwable $error) {
            exec('/usr/bin/systemctl stop ' . self::SERVICE . ' >/dev/null 2>&1');
            if (hash_equals(hash('sha256', $rewritten), (string) hash_file('sha256', $path))) {
                copy($backup, $path);
                chown($path, (int) $info['uid']);
                chgrp($path, (int) $info['gid']);
                chmod($path, 0640);
            }
            throw new RuntimeException('FAIL_SERVICE_START_CONFIG_REVERTED');
        }
    }

    private static function unitActive(string $unit): bool
    {
        exec('/usr/bin/systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null', $lines, $rc);
        return $rc === 0;
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    try {
        exit(MarinaPricingRecovery::cli($argv));
    } catch (Throwable $error) {
        // Never emit PDO error, URL, user, password, or exception trace to the console/CI.
        $message = $error instanceof RuntimeException || $error instanceof InvalidArgumentException
            ? $error->getMessage() : 'UNEXPECTED_TYPE_' . get_class($error);
        if (!preg_match('/^(FAIL_|USAGE_)/', $message)) { $message = 'FAIL_' . get_class($error); }
        fwrite(STDERR, 'PRICING_MAINTENANCE=' . $message . "\n");
        exit(1);
    }
}
