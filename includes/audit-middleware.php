<?php
/**
 * Middleware de Auditoria
 * Registra todas as requisições, acessos e mudanças
 */

declare(strict_types=1);

class AuditLogger
{
    private static $logDir = __DIR__ . '/../logs/audit';
    private static $accessLogFile = null;
    private static $apiLogFile = null;
    private static $eventId = null;

    public static function initialize(): void
    {
        // Criar diretório de logs se não existir
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }

        self::$eventId = self::generateEventId();
        self::$accessLogFile = self::$logDir . '/access-' . date('Y-m-d') . '.log';
        self::$apiLogFile = self::$logDir . '/api-' . date('Y-m-d') . '.log';

        // Registrar acesso
        self::logAccess();

        // Registrar mudanças de request
        self::registerRequestInterception();
    }

    private static function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function logAccess(): void
    {
        $entry = [
            'timestamp' => date('c'),
            'event_id' => self::$eventId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'path' => $_SERVER['REQUEST_URI'] ?? '/',
            'ip_address' => self::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'user_id' => $_SESSION['user_id'] ?? null,
            'status' => http_response_code() ?: 200,
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'Direct',
        ];

        error_log(json_encode($entry) . "\n", 3, self::$accessLogFile);
    }

    private static function getClientIP(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function registerRequestInterception(): void
    {
        // Registrar mudanças via $_POST
        if (!empty($_POST)) {
            self::logAPICall('POST', $_POST);
        }
    }

    public static function logAPICall(string $method, array $data = [], int $statusCode = 200): void
    {
        // Sanitizar dados sensíveis
        $sanitized = self::sanitizeSensitiveData($data);

        $entry = [
            'timestamp' => date('c'),
            'event_id' => self::$eventId,
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '/',
            'method' => $method,
            'ip_address' => self::getClientIP(),
            'user_id' => $_SESSION['user_id'] ?? null,
            'status_code' => $statusCode,
            'request_data' => $sanitized,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        ];

        error_log(json_encode($entry) . "\n", 3, self::$apiLogFile);
    }

    public static function logDatabaseChange(string $table, string $action, array $before = [], array $after = []): void
    {
        $entry = [
            'timestamp' => date('c'),
            'event_id' => self::$eventId,
            'table' => $table,
            'action' => $action, // INSERT, UPDATE, DELETE
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip_address' => self::getClientIP(),
            'before' => $before,
            'after' => $after,
            'changes' => self::computeChanges($before, $after),
        ];

        $dbLogFile = self::$logDir . '/database-' . date('Y-m-d') . '.log';
        error_log(json_encode($entry) . "\n", 3, $dbLogFile);

        // Se for mudança sensível, registrar alerta
        if (self::isSensitiveChange($table, $action)) {
            self::logSecurityAlert($table, $action, $entry);
        }
    }

    public static function logConfigChange(string $configKey, $oldValue, $newValue): void
    {
        $entry = [
            'timestamp' => date('c'),
            'event_id' => self::$eventId,
            'config_key' => $configKey,
            'old_value' => (is_string($oldValue) && strlen($oldValue) > 100) ? substr($oldValue, 0, 100) . '...' : $oldValue,
            'new_value' => (is_string($newValue) && strlen($newValue) > 100) ? substr($newValue, 0, 100) . '...' : $newValue,
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip_address' => self::getClientIP(),
        ];

        $configLogFile = self::$logDir . '/config-' . date('Y-m-d') . '.log';
        error_log(json_encode($entry) . "\n", 3, $configLogFile);
    }

    public static function logSecurityAlert(string $type, string $message, array $context = []): void
    {
        $severity = self::calculateSeverity($type);

        $entry = [
            'timestamp' => date('c'),
            'event_id' => self::$eventId,
            'alert_type' => $type,
            'message' => $message,
            'severity' => $severity,
            'ip_address' => self::getClientIP(),
            'user_id' => $_SESSION['user_id'] ?? null,
            'context' => $context,
        ];

        $alertLogFile = self::$logDir . '/security-alerts-' . date('Y-m-d') . '.log';
        error_log(json_encode($entry) . "\n", 3, $alertLogFile);

        // Enviar notificação se for crítico
        if ($severity === 'critical') {
            self::sendCriticalAlert($entry);
        }
    }

    private static function sanitizeSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'credit_card', 'ssn'];
        $result = [];

        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);
            if (in_array($keyLower, $sensitiveKeys)) {
                $result[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $result[$key] = self::sanitizeSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function computeChanges(array $before, array $after): array
    {
        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($allKeys as $key) {
            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key] ?? null;

            if ($oldVal !== $newVal) {
                $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        return $changes;
    }

    private static function isSensitiveChange(string $table, string $action): bool
    {
        $sensitiveTables = ['usuarios', 'config', 'secrets', 'api_keys', 'orders'];
        return in_array($table, $sensitiveTables);
    }

    private static function calculateSeverity(string $type): string
    {
        $critical = ['unauthorized_access', 'config_modified', 'admin_access', 'data_export'];
        $high = ['failed_login', 'api_error', 'database_change'];
        $medium = ['api_call', 'user_action', 'file_access'];

        if (in_array($type, $critical)) return 'critical';
        if (in_array($type, $high)) return 'high';
        if (in_array($type, $medium)) return 'medium';

        return 'low';
    }

    private static function sendCriticalAlert(array $alert): void
    {
        // Enviar email ou Slack notification
        // Implementar conforme necessário
        error_log("CRITICAL ALERT: " . json_encode($alert));
    }

    public static function getEventId(): string
    {
        return self::$eventId;
    }

    public static function getLogs(string $type = 'access', int $lines = 100): array
    {
        $logFile = self::$logDir . '/' . $type . '-' . date('Y-m-d') . '.log';

        if (!file_exists($logFile)) {
            return [];
        }

        $logs = array_filter(
            array_map('trim', array_slice(file($logFile), -$lines)),
            'strlen'
        );

        return array_map(fn($line) => json_decode($line, true), $logs);
    }
}

// Inicializar auditoria automaticamente
AuditLogger::initialize();
