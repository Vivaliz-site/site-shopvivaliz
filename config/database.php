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
            // Não expor detalhes da exceção ao usuário
            throw new Exception('Banco de dados indisponível. Contate o suporte.');
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
    public function __wakeup() {}
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
            product_id BIGINT UNSIGNED NULL,
            product_local_id BIGINT UNSIGNED NULL,
            olist_product_id VARCHAR(64) NULL,
            olist_id VARCHAR(64) NULL,
            sku VARCHAR(191) NULL,
            image_url VARCHAR(1000) NULL,
            site_url VARCHAR(1000) NULL,
            local_url VARCHAR(1000) NULL,
            original_url VARCHAR(1000),
            original_url_olist VARCHAR(1000) NULL,
            local_file VARCHAR(1000) NULL,
            position INT NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            source VARCHAR(80) NOT NULL DEFAULT "olist_api",
            status VARCHAR(40) NOT NULL DEFAULT "active",
            url_hash CHAR(64) NULL,
            file_hash CHAR(64) NULL,
            dedupe_key VARCHAR(191) NULL,
            uploaded TINYINT(1) NOT NULL DEFAULT 0,
            linked TINYINT(1) NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            format VARCHAR(10),
            size_kb INT,
            dimensions VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_product_id (product_id),
            INDEX idx_olist_images_product_status (product_local_id, status),
            INDEX idx_olist_images_sku_status (sku, status),
            INDEX idx_olist_images_dedupe (dedupe_key)
        )',

        // Produtos Olist
        'CREATE TABLE IF NOT EXISTS olist_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(191) NULL,
            olist_product_id VARCHAR(64) NULL,
            olist_id VARCHAR(64) NULL,
            idProduto VARCHAR(64) NULL,
            name VARCHAR(255) NULL,
            primary_image_url VARCHAR(1000) NULL,
            images_count INT NOT NULL DEFAULT 0,
            image_sync_status VARCHAR(40) NOT NULL DEFAULT "pending",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_olist_products_sku (sku),
            INDEX idx_olist_products_olist_id (olist_id),
            INDEX idx_olist_products_olist_product_id (olist_product_id)
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

        // Jobs de geração de imagens IA
        'CREATE TABLE IF NOT EXISTS ai_image_jobs (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku           VARCHAR(191)  NULL,
            olist_id      VARCHAR(64)   NULL,
            nome_produto  VARCHAR(255)  NULL,
            original_url  VARCHAR(1000) NULL,
            status        VARCHAR(40)   NOT NULL DEFAULT "pending",
            vision_model  VARCHAR(80)   NULL,
            image_model   VARCHAR(80)   NULL,
            generated_at  DATETIME      NULL,
            error_message TEXT          NULL,
            created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME      NULL,
            PRIMARY KEY (id),
            INDEX idx_aij_sku (sku),
            INDEX idx_aij_status (status)
        )',

        // Itens de cada job (uma linha por tipo de imagem)
        'CREATE TABLE IF NOT EXISTS ai_image_job_items (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id      BIGINT UNSIGNED NOT NULL,
            image_type  VARCHAR(40)   NOT NULL,
            prompt      TEXT          NULL,
            site_url    VARCHAR(1000) NULL,
            local_file  VARCHAR(1000) NULL,
            status      VARCHAR(40)   NOT NULL DEFAULT "pending",
            error       VARCHAR(500)  NULL,
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_aiji_job (job_id),
            INDEX idx_aiji_type (image_type)
        )',

        // Sessões de A/B test de imagens
        'CREATE TABLE IF NOT EXISTS ab_test_sessions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      VARCHAR(64)   NOT NULL,
            sku             VARCHAR(191)  NULL,
            olist_id        VARCHAR(64)   NULL,
            variant_a_type  VARCHAR(40)   NOT NULL,
            variant_a_url   VARCHAR(1000) NOT NULL,
            variant_b_type  VARCHAR(40)   NOT NULL,
            variant_b_url   VARCHAR(1000) NOT NULL,
            clicks_a        INT           NOT NULL DEFAULT 0,
            clicks_b        INT           NOT NULL DEFAULT 0,
            sales_a         INT           NOT NULL DEFAULT 0,
            sales_b         INT           NOT NULL DEFAULT 0,
            impressions     INT           NOT NULL DEFAULT 0,
            winner_type     VARCHAR(40)   NULL,
            winner_url      VARCHAR(1000) NULL,
            status          VARCHAR(40)   NOT NULL DEFAULT "running",
            started_at      DATETIME      NULL,
            decided_at      DATETIME      NULL,
            created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME      NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_abs_session (session_id),
            INDEX idx_abs_sku (sku),
            INDEX idx_abs_status (status)
        )',

        // Alertas de estoque (Task-033)
        'CREATE TABLE IF NOT EXISTS stock_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(191) NOT NULL,
            email VARCHAR(255) NOT NULL,
            unsubscribe_token VARCHAR(64) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT "pending",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stock_alert_sku_email (sku, email),
            INDEX idx_stock_alert_sku_status (sku, status),
            INDEX idx_stock_alert_token (unsubscribe_token)
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
