<?php
declare(strict_types=1);

/**
 * Centralized Logging System
 *
 * Provides structured logging for errors, warnings, info, and debug messages
 * with automatic log rotation and formatting.
 */

class Logger
{
    private string $logsDir;
    private string $currentLog;
    private int $maxFileSize; // bytes
    private string $timezone;

    // Log levels
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    private static ?Logger $instance = null;

    public function __construct(?string $logsDir = null, int $maxFileSize = 10485760)
    {
        $this->logsDir = $logsDir ?: dirname(__DIR__) . '/logs';
        $this->maxFileSize = $maxFileSize; // 10MB default
        $this->timezone = date_default_timezone_get();
        $this->currentLog = $this->logsDir . '/application.log';

        if (!is_dir($this->logsDir)) {
            @mkdir($this->logsDir, 0755, true);
        }

        // Verify directory is writable
        if (!is_writable($this->logsDir)) {
            error_log("Logger: directory {$this->logsDir} is not writable");
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(?string $logsDir = null): Logger
    {
        if (self::$instance === null) {
            self::$instance = new self($logsDir);
        }

        return self::$instance;
    }

    /**
     * Log a message
     *
     * @param string $level Log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
     * @param string $message Message to log
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function log(string $level, string $message, array $context = []): bool
    {
        // Rotate log if needed
        $this->rotateLogIfNeeded();

        $timestamp = $this->getTimestamp();
        $requestId = $this->getRequestId();

        // Build log entry
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' | ' . json_encode($context);
        }

        $logEntry = sprintf(
            '[%s] [%s] [%s] %s%s',
            $timestamp,
            $level,
            $requestId,
            $message,
            $contextStr
        );

        // Write to file
        $result = @file_put_contents(
            $this->currentLog,
            $logEntry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return $result !== false;
    }

    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): bool
    {
        return $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Log info message
     */
    public function info(string $message, array $context = []): bool
    {
        return $this->log(self::INFO, $message, $context);
    }

    /**
     * Log warning message
     */
    public function warning(string $message, array $context = []): bool
    {
        return $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log error message
     */
    public function error(string $message, array $context = []): bool
    {
        return $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log critical message
     */
    public function critical(string $message, array $context = []): bool
    {
        return $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log exception
     */
    public function exception(Throwable $exception, array $context = []): bool
    {
        $exceptionContext = array_merge([
            'exception_class' => get_class($exception),
            'exception_code' => $exception->getCode(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $exception->getTraceAsString(),
        ], $context);

        return $this->error($exception->getMessage(), $exceptionContext);
    }

    /**
     * Get request ID (for tracing)
     */
    private function getRequestId(): string
    {
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }

        if (!isset($GLOBALS['_request_id'])) {
            // Generate unique request ID
            $GLOBALS['_request_id'] = bin2hex(random_bytes(8));
        }

        return $GLOBALS['_request_id'];
    }

    /**
     * Get formatted timestamp
     */
    private function getTimestamp(): string
    {
        return date('Y-m-d H:i:s', time());
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private function rotateLogIfNeeded(): void
    {
        if (!is_file($this->currentLog)) {
            return;
        }

        $filesize = filesize($this->currentLog);
        if ($filesize === false || $filesize < $this->maxFileSize) {
            return;
        }

        // Archive current log
        $timestamp = date('Y-m-d_H-i-s');
        $archiveName = $this->logsDir . '/application-' . $timestamp . '.log.gz';

        // Compress and move
        $this->compressFile($this->currentLog, $archiveName);

        // Clear log file
        @file_put_contents($this->currentLog, '');
    }

    /**
     * Compress file with gzip
     */
    private function compressFile(string $source, string $destination): bool
    {
        if (!is_file($source) || !is_readable($source)) {
            return false;
        }

        $fp = fopen($source, 'rb');
        if ($fp === false) {
            return false;
        }

        $gz = gzopen($destination, 'wb');
        if ($gz === false) {
            fclose($fp);
            return false;
        }

        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk !== false) {
                gzwrite($gz, $chunk);
            }
        }

        fclose($fp);
        gzclose($gz);

        return true;
    }

    /**
     * Get log file contents
     *
     * @param int $lines Number of lines to retrieve
     * @return array Log lines
     */
    public function getTail(int $lines = 100): array
    {
        if (!is_file($this->currentLog)) {
            return [];
        }

        $file = file($this->currentLog);
        if ($file === false) {
            return [];
        }

        return array_slice($file, -$lines);
    }

    /**
     * Clear log file
     */
    public function clear(): bool
    {
        return @file_put_contents($this->currentLog, '') !== false;
    }

    /**
     * Get log file path
     */
    public function getLogPath(): string
    {
        return $this->currentLog;
    }

    /**
     * Set log file name (for context-specific logging)
     */
    public function setLogFile(string $filename): void
    {
        $this->currentLog = $this->logsDir . '/' . basename($filename);
    }
}

/**
 * Global logging function
 *
 * @param string $message Message to log
 * @param string $level Log level
 * @param array $context Context data
 * @return bool Success
 */
function sv_log(string $message, string $level = 'INFO', array $context = []): bool
{
    return Logger::getInstance()->log($level, $message, $context);
}

/**
 * Monitor and track performance metrics
 */
class PerformanceMonitor
{
    private static array $timers = [];
    private static array $counters = [];

    /**
     * Start a timer
     */
    public static function start(string $name): void
    {
        self::$timers[$name] = microtime(true);
    }

    /**
     * Stop timer and get elapsed time
     */
    public static function stop(string $name): float
    {
        if (!isset(self::$timers[$name])) {
            return 0.0;
        }

        $elapsed = microtime(true) - self::$timers[$name];
        unset(self::$timers[$name]);

        return $elapsed;
    }

    /**
     * Increment a counter
     */
    public static function increment(string $name, int $amount = 1): void
    {
        self::$counters[$name] = (self::$counters[$name] ?? 0) + $amount;
    }

    /**
     * Get counter value
     */
    public static function getCounter(string $name): int
    {
        return self::$counters[$name] ?? 0;
    }

    /**
     * Get all metrics
     */
    public static function getMetrics(): array
    {
        return [
            'timers' => self::$timers,
            'counters' => self::$counters,
            'memory' => [
                'current' => memory_get_usage(),
                'peak' => memory_get_peak_usage(),
            ],
        ];
    }

    /**
     * Reset all metrics
     */
    public static function reset(): void
    {
        self::$timers = [];
        self::$counters = [];
    }
}
