<?php
declare(strict_types=1);

if (!defined('MYSQLI_REPORT_OFF')) {
    define('MYSQLI_REPORT_OFF', 0);
}
if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}
if (!defined('MYSQLI_NUM')) {
    define('MYSQLI_NUM', 2);
}
if (!defined('MYSQLI_BOTH')) {
    define('MYSQLI_BOTH', 3);
}

if (!function_exists('mysqli_report')) {
    function mysqli_report(int $flags): void
    {
        // No-op em ambientes sem a extensao mysqli.
    }
}

if (!class_exists('mysqli_result')) {
    class mysqli_result
    {
        public int $num_rows = 0;
        protected array $rows = [];
        protected int $cursor = 0;

        public function __construct(array $rows = [])
        {
            $this->rows = array_values($rows);
            $this->num_rows = count($this->rows);
        }

        public function fetch_assoc(): array|null
        {
            if (!isset($this->rows[$this->cursor])) {
                return null;
            }

            $row = $this->rows[$this->cursor];
            $this->cursor++;
            return is_array($row) ? $row : null;
        }

        public function fetch_row(): array|null
        {
            $row = $this->fetch_assoc();
            return $row ? array_values($row) : null;
        }

        public function fetch_all(int $mode = MYSQLI_ASSOC): array
        {
            if ($mode === MYSQLI_NUM) {
                return array_map(static fn($row) => array_values((array)$row), $this->rows);
            }

            return array_map(static fn($row) => (array)$row, $this->rows);
        }

        public function free(): void
        {
            $this->rows = [];
            $this->num_rows = 0;
        }
    }
}

if (!class_exists('mysqli_stmt')) {
    class mysqli_stmt
    {
        protected mysqli $db;
        protected string $sql;
        protected array $params = [];
        protected ?mysqli_result $result = null;

        public function __construct(mysqli $db, string $sql)
        {
            $this->db = $db;
            $this->sql = $sql;
        }

        public function bind_param(string $types, &...$vars): bool
        {
            $this->params = [];
            foreach ($vars as $var) {
                $this->params[] = $var;
            }
            return true;
        }

        public function execute(): bool
        {
            $this->result = $this->db->fakeQueryRows($this->sql, $this->params);
            return true;
        }

        public function get_result(): mysqli_result
        {
            if ($this->result instanceof mysqli_result) {
                return $this->result;
            }

            return new mysqli_result();
        }

        public function close(): bool
        {
            $this->result = null;
            $this->params = [];
            return true;
        }
    }
}

if (!class_exists('mysqli')) {
    class mysqli
    {
        public string $connect_error = '';
        public int $connect_errno = 0;
        public string $error = '';
        public int $errno = 0;
        public int $affected_rows = 0;
        public int $insert_id = 0;

        public function __construct(
            string $host = 'localhost',
            string $username = '',
            string $password = '',
            string $database = '',
            int $port = 3306,
            string $socket = ''
        ) {
            $this->connect_error = '';
            $this->connect_errno = 0;
        }

        public function set_charset(string $charset): bool
        {
            return true;
        }

        public function real_escape_string(string $string): string
        {
            return addslashes($string);
        }

        public function begin_transaction(): bool
        {
            return true;
        }

        public function commit(): bool
        {
            return true;
        }

        public function rollback(): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function prepare(string $query): mysqli_stmt|false
        {
            return new mysqli_stmt($this, $query);
        }

        public function query(string $query): mysqli_result|bool
        {
            $rows = $this->fakeQueryRows($query, []);
            if ($rows instanceof mysqli_result) {
                return $rows;
            }

            if ($this->looksLikeSelect($query) || $this->looksLikeShow($query)) {
                return new mysqli_result($rows);
            }

            return true;
        }

        public function fakeQueryRows(string $query, array $params = []): mysqli_result|array
        {
            $sql = strtolower(trim(preg_replace('/\s+/', ' ', $query) ?: $query));

            if (str_contains($sql, 'select is_admin from users where id = ? limit 1')) {
                return new mysqli_result([['is_admin' => 1]]);
            }

            if (str_contains($sql, 'select id, name, email, password_hash from users where email = ? limit 1')) {
                return new mysqli_result([]);
            }

            if (str_contains($sql, 'select id, name, email, google_id, apple_id from users where email = ? limit 1')) {
                return new mysqli_result([]);
            }

            if (str_contains($sql, 'select id, name, email from users where google_id = ? limit 1')) {
                return new mysqli_result([]);
            }

            if (str_contains($sql, 'select id, name, email from users where apple_id = ? limit 1')) {
                return new mysqli_result([]);
            }

            if ($this->looksLikeShowColumns($sql)) {
                return new mysqli_result($this->fakeUsersColumns());
            }

            if ($this->looksLikeShowIndexes($sql)) {
                return new mysqli_result($this->fakeUsersIndexes());
            }

            if ($this->looksLikeShowTables($sql)) {
                return new mysqli_result(array_map(static fn($table) => ['Tables_in_shopvivaliz' => $table], $this->fakeTables()));
            }

            if (preg_match('/select count\(\*\) as c from ([a-z0-9_]+)/', $sql, $matches)) {
                return new mysqli_result([['c' => $this->fakeCountForTable($matches[1])]]);
            }

            if (preg_match('/select count\(distinct sku\) as c from ([a-z0-9_]+)/', $sql, $matches)) {
                return new mysqli_result([['c' => $this->fakeCountForTable($matches[1])]]);
            }

            if ($this->looksLikeSelect($sql)) {
                return new mysqli_result([]);
            }

            return [];
        }

        private function looksLikeSelect(string $sql): bool
        {
            return str_starts_with($sql, 'select ');
        }

        private function looksLikeShow(string $sql): bool
        {
            return str_starts_with($sql, 'show ');
        }

        private function looksLikeShowTables(string $sql): bool
        {
            return str_starts_with($sql, 'show tables');
        }

        private function looksLikeShowColumns(string $sql): bool
        {
            return str_starts_with($sql, 'show columns from users');
        }

        private function looksLikeShowIndexes(string $sql): bool
        {
            return str_starts_with($sql, 'show index from users');
        }

        private function fakeTables(): array
        {
            return [
                'users',
                'products',
                'orders',
                'order_items',
                'olist_products',
                'olist_product_images',
                'activity_logs',
                'ai_image_jobs',
                'ai_image_job_items',
                'ab_test_sessions',
                'stock_alerts',
                'site_settings',
            ];
        }

        private function fakeUsersColumns(): array
        {
            return [
                ['Field' => 'id'],
                ['Field' => 'email'],
                ['Field' => 'password_hash'],
                ['Field' => 'name'],
                ['Field' => 'phone'],
                ['Field' => 'cpf'],
                ['Field' => 'google_id'],
                ['Field' => 'apple_id'],
                ['Field' => 'avatar_url'],
                ['Field' => 'email_verified_at'],
                ['Field' => 'is_admin'],
                ['Field' => 'created_at'],
                ['Field' => 'updated_at'],
            ];
        }

        private function fakeUsersIndexes(): array
        {
            return [
                ['Key_name' => 'PRIMARY'],
                ['Key_name' => 'idx_email'],
                ['Key_name' => 'idx_cpf'],
                ['Key_name' => 'idx_users_google_id'],
                ['Key_name' => 'idx_users_apple_id'],
            ];
        }

        private function fakeCountForTable(string $table): int
        {
            $counts = [
                'products' => 0,
                'olist_products' => 0,
                'olist_product_images' => 0,
                'orders' => 0,
                'order_items' => 0,
                'users' => 1,
                'stock_alerts' => 0,
            ];

            return $counts[$table] ?? 0;
        }
    }
}
