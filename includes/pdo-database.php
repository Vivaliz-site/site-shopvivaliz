<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

final class SvFallbackPdoStatement
{
    private SvFallbackPdo $pdo;
    private string $sql;
    private array $rows = [];
    private int $rowCount = 0;

    public function __construct(SvFallbackPdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $this->rows = $this->pdo->handleStatement($this->sql, is_array($params) ? $params : [], $this->rowCount);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetch($mode = null)
    {
        return array_shift($this->rows) ?: false;
    }

    #[\ReturnTypeWillChange]
    public function fetchAll($mode = null)
    {
        return $this->rows;
    }

    #[\ReturnTypeWillChange]
    public function fetchColumn($column = 0)
    {
        if (!$this->rows) {
            return false;
        }

        $row = $this->rows[0];
        if (!is_array($row)) {
            return false;
        }

        $values = array_values($row);
        return $values[$column] ?? false;
    }

    #[\ReturnTypeWillChange]
    public function rowCount()
    {
        return $this->rowCount;
    }

    #[\ReturnTypeWillChange]
    public function closeCursor()
    {
        $this->rows = [];
        return true;
    }
}

final class SvFallbackPdo extends PDO
{
    private string $settingsFile;
    private array $settings = [];

    public function __construct()
    {
        $runtimeDir = STORAGE_PATH . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0775, true);
        }
        $this->settingsFile = $runtimeDir . '/site-settings.json';
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        if (!is_file($this->settingsFile)) {
            $this->settings = [];
            return;
        }

        $decoded = json_decode((string)@file_get_contents($this->settingsFile), true);
        $this->settings = is_array($decoded) ? $decoded : [];
    }

    private function saveSettings(): void
    {
        @file_put_contents(
            $this->settingsFile,
            json_encode($this->settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function statementParam(array $params, int|string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $params)) {
            return $params[$key];
        }

        if (is_int($key) && array_key_exists($key, array_values($params))) {
            return array_values($params)[$key];
        }

        if (is_string($key) && $key !== '' && array_key_exists(':' . ltrim($key, ':'), $params)) {
            return $params[':' . ltrim($key, ':')];
        }

        $values = array_values($params);
        return $values[0] ?? $default;
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return new SvFallbackPdoStatement($this, (string)$query);
    }

    #[\ReturnTypeWillChange]
    public function query($query, ...$fetchModeArgs)
    {
        $stmt = $this->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $sql = trim((string)$statement);
        if ($sql === '') {
            return 0;
        }

        if (stripos($sql, 'site_settings') !== false && stripos($sql, 'insert') !== false) {
            return 1;
        }

        return 0;
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        return '0';
    }

    #[\ReturnTypeWillChange]
    public function beginTransaction()
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function commit()
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function rollBack()
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function inTransaction()
    {
        return false;
    }

    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value)
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function getAttribute($attribute)
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'fallback';
        }

        return null;
    }

    #[\ReturnTypeWillChange]
    public function quote($string, $type = PDO::PARAM_STR)
    {
        return "'" . str_replace("'", "''", (string)$string) . "'";
    }

    public function handleStatement(string $sql, array $params, int &$rowCount): array
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower($sql)) ?: strtolower($sql);
        $rowCount = 0;

        if (str_contains($normalized, 'from site_settings') && str_starts_with($normalized, 'select')) {
            $key = $this->statementParam($params, ':k', $this->statementParam($params, 'k'));
            if (is_string($key) && $key !== '') {
                $value = $this->settings[$key] ?? null;
                $rowCount = $value !== null ? 1 : 0;
                return $value !== null ? [['setting_value' => $value]] : [];
            }

            $rows = [];
            foreach ($this->settings as $settingKey => $settingValue) {
                $rows[] = [
                    'setting_key' => $settingKey,
                    'setting_value' => $settingValue,
                ];
            }
            $rowCount = count($rows);
            return $rows;
        }

        if (str_contains($normalized, 'insert into site_settings')) {
            $key = (string)$this->statementParam($params, ':k', $this->statementParam($params, 'k', ''));
            $value = (string)$this->statementParam($params, ':v', $this->statementParam($params, 'v', ''));
            if ($key !== '') {
                $this->settings[$key] = $value;
                $this->saveSettings();
                $rowCount = 1;
            }
            return [];
        }

        if (str_contains($normalized, 'update site_settings')) {
            $key = (string)$this->statementParam($params, ':k', $this->statementParam($params, 'k', ''));
            $value = (string)$this->statementParam($params, ':v', $this->statementParam($params, 'v', ''));
            if ($key !== '' && array_key_exists($key, $this->settings)) {
                $this->settings[$key] = $value;
                $this->saveSettings();
                $rowCount = 1;
            }
            return [];
        }

        return [];
    }
}

function sv_pdo_driver_available(): bool
{
    return in_array('mysql', PDO::getAvailableDrivers(), true);
}

function sv_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (sv_pdo_driver_available()) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        } catch (Throwable $e) {
            error_log('[PDO] Fallback database ativo: ' . $e->getMessage());
        }
    } else {
        error_log('[PDO] Fallback database ativo: driver mysql ausente');
    }

    $pdo = new SvFallbackPdo();

    return $pdo;
}
