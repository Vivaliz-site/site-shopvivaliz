<?php
/**
 * Configuração de Banco de Dados - ShopVivaliz
 */

// Singleton Database Connection
class Database {
    private static $instance = null;
    private $connection = null;

    private function __construct() {
        try {
            // Usar configurações de constants.php
            $this->connection = new mysqli(
                DB_HOST,
                DB_USER,
                DB_PASS,
                DB_NAME,
                DB_PORT
            );

            // Verificar conexão
            if ($this->connection->connect_error) {
                throw new Exception('Erro de conexão: ' . $this->connection->connect_error);
            }

            // Configurar charset
            $this->connection->set_charset(DB_CHARSET);

            // Definir timezone
            $this->connection->query("SET time_zone = '+00:00'");

            if (DEBUG_MODE) {
                error_log('Database connected successfully');
            }
        } catch (Exception $e) {
            log_error('Database connection failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function query($sql) {
        $result = $this->connection->query($sql);
        if (!$result) {
            log_error('Query error', ['error' => $this->connection->error, 'sql' => $sql]);
            return false;
        }
        return $result;
    }

    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }

    public function escape($str) {
        return $this->connection->real_escape_string($str);
    }

    public function lastInsertId() {
        return $this->connection->insert_id;
    }

    public function affectedRows() {
        return $this->connection->affected_rows;
    }

    public function beginTransaction() {
        return $this->connection->begin_transaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollback() {
        return $this->connection->rollback();
    }

    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    private function __clone() {}
    private function __wakeup() {}
}

// Criar tabelas se não existirem
function create_tables() {
    $db = Database::getInstance()->getConnection();

    $tables = [
        // Usuários
        'CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(20),
            cpf VARCHAR(14) UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_cpf (cpf)
        )',

        // Produtos
        'CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10, 2) NOT NULL,
            stock INT DEFAULT 0,
            category_id INT,
            image_url VARCHAR(500),
            active BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sku (sku),
            INDEX idx_category (category_id),
            INDEX idx_active (active)
        )',

        // Pedidos
        'CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_number VARCHAR(50) UNIQUE NOT NULL,
            total DECIMAL(10, 2) NOT NULL,
            status VARCHAR(50) DEFAULT "pending",
            payment_method VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        )',

        // Imagens Olist
        'CREATE TABLE IF NOT EXISTS olist_product_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            local_url VARCHAR(500) NOT NULL,
            original_url VARCHAR(500),
            format VARCHAR(10),
            size_kb INT,
            dimensions VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_id (product_id)
        )',

        // Logs de atividade
        'CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        )',
    ];

    foreach ($tables as $table_sql) {
        if (!$db->query($table_sql)) {
            log_error('Failed to create table', ['error' => $db->error]);
            return false;
        }
    }

    return true;
}

// Inicializar banco de dados
try {
    $db = Database::getInstance();
    create_tables();
} catch (Exception $e) {
    if (DEBUG_MODE) {
        echo "Erro ao inicializar banco de dados: " . $e->getMessage();
    }
}
