<?php
declare(strict_types=1);

namespace Core;

/**
 * Gerenciador de conexão com banco de dados
 */
class Database {
    private static ?\PDO $connection = null;

    /**
     * Obter conexão PDO singleton
     */
    public static function connect(): \PDO {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_NAME') ?: 'shopvivaliz';
        $user = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";

            self::$connection = new \PDO(
                $dsn,
                $user,
                $password,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return self::$connection;
        } catch (\PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new \RuntimeException("Failed to connect to database: " . $e->getMessage());
        }
    }

    /**
     * Executar query simples
     */
    public static function query(string $sql, array $params = []): array {
        $pdo = self::connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Executar e retornar um resultado
     */
    public static function queryOne(string $sql, array $params = []): ?array {
        $results = self::query($sql, $params);
        return $results[0] ?? null;
    }

    /**
     * Executar sem retorno (INSERT, UPDATE, DELETE)
     */
    public static function execute(string $sql, array $params = []): int {
        $pdo = self::connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Inicializar banco de dados (criar tabelas se não existirem)
     */
    public static function initialize(): bool {
        try {
            $pdo = self::connect();

            // Verificar se tabela existe
            $tables = $pdo->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (!in_array('page_layouts', $tables)) {
                // Criar tabelas
                $schema = file_get_contents(__DIR__ . '/../database/schema-layouts.sql');
                $statements = array_filter(array_map('trim', explode(';', $schema)));

                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        $pdo->exec($statement);
                    }
                }

                error_log("Database tables created successfully");
                return true;
            }

            error_log("Database tables already exist");
            return true;
        } catch (\Throwable $e) {
            error_log("Database initialization error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fechar conexão
     */
    public static function close(): void {
        self::$connection = null;
    }
}
