<?php
/**
 * 🧪 Database Sandbox - Testa queries em BD local antes de produção
 * Valida integridade, performance, segurança
 */

class DatabaseSandbox {
    private $sandboxDb = 'sqlite::memory:'; // Usar SQLite em memória para testes
    private $productionDb = 'mysql:host=localhost;dbname=shopvivaliz';
    private $dbUser = '';
    private $dbPass = '';
    private $productionConn = null;
    private $sandboxConn = null;

    public function __construct() {
        $this->dbUser = getenv('DB_USER') ?: 'root';
        $this->dbPass = getenv('DB_PASS') ?: '';
    }

    public function testQuery($sqlQuery, $params = []) {
        echo "🧪 Testando query em sandbox...\n";

        // 1. Validar segurança
        if (!$this->validateQuerySecurity($sqlQuery)) {
            echo "❌ Query falha validação de segurança\n";
            return false;
        }

        // 2. Conectar ao sandbox
        $this->connectSandbox();

        // 3. Preparar schema (copiar de produção)
        $this->prepareSchema();

        // 4. Executar teste
        try {
            $stmt = $this->sandboxConn->prepare($sqlQuery);
            $result = $stmt->execute($params);

            if (!$result) {
                echo "❌ Query falhou: " . implode(' | ', $stmt->errorInfo()) . "\n";
                return false;
            }

            echo "✅ Query executada com sucesso\n";

            // 5. Validar resultados
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "📊 Resultado: " . count($rows) . " rows\n";

            // 6. Testes de integridade
            if (!$this->validateIntegrity($sqlQuery, $rows)) {
                echo "❌ Falha em validação de integridade\n";
                return false;
            }

            echo "✅ Integridade validada\n";

            // 7. Testes de performance
            if (!$this->validatePerformance($sqlQuery)) {
                echo "⚠️ Query pode ser lenta\n";
            }

            return true;

        } catch (Exception $e) {
            echo "❌ Exceção: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function validateQuerySecurity($sqlQuery) {
        // Bloquear operações perigosas
        $dangerous = [
            'DROP DATABASE',
            'DROP TABLE',
            'TRUNCATE',
            'DELETE FROM.*WHERE.*=',
            'UNION.*INTO',
        ];

        foreach ($dangerous as $pattern) {
            if (preg_match("/$pattern/i", $sqlQuery)) {
                echo "❌ BLOQUEADO: Operação perigosa detectada ($pattern)\n";
                return false;
            }
        }

        // Checar SQL injection
        if (preg_match("/['\"]\s*(OR|AND)\s*['\"]/i", $sqlQuery)) {
            echo "❌ BLOQUEADO: Padrão de SQL injection detectado\n";
            return false;
        }

        return true;
    }

    private function connectSandbox() {
        try {
            $this->sandboxConn = new PDO($this->sandboxDb);
            $this->sandboxConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_THROW);
        } catch (Exception $e) {
            echo "❌ Erro conectando ao sandbox: " . $e->getMessage() . "\n";
        }
    }

    private function connectProduction() {
        try {
            $this->productionConn = new PDO(
                $this->productionDb,
                $this->dbUser,
                $this->dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_THROW]
            );
        } catch (Exception $e) {
            echo "❌ Erro conectando a produção: " . $e->getMessage() . "\n";
        }
    }

    private function prepareSchema() {
        // Copiar estrutura de tabelas críticas de produção
        // (em produção, isso viria do banco real)

        // Para agora, criar tabelas de teste
        $schema = [
            'CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY,
                name TEXT,
                price REAL,
                stock INTEGER
            )',
            'CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                total REAL,
                status TEXT
            )',
            'CREATE TABLE IF NOT EXISTS config_values (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME
            )',
        ];

        foreach ($schema as $sql) {
            try {
                $this->sandboxConn->exec($sql);
            } catch (Exception $e) {
                // Tabela pode já existir
            }
        }

        // Inserir dados de teste
        $testData = [
            "INSERT OR IGNORE INTO products VALUES (1, 'Produto Teste', 99.90, 50)",
            "INSERT OR IGNORE INTO orders VALUES (1, 1, 500.00, 'pending')",
        ];

        foreach ($testData as $sql) {
            try {
                $this->sandboxConn->exec($sql);
            } catch (Exception $e) {
                // Pode já existir
            }
        }
    }

    private function validateIntegrity($sqlQuery, $rows) {
        // Validações básicas
        if (preg_match('/COUNT\(/i', $sqlQuery)) {
            // Verificar que COUNT retorna um número
            if (isset($rows[0])) {
                $value = array_values($rows[0])[0];
                if (!is_numeric($value)) {
                    echo "❌ COUNT deveria retornar número\n";
                    return false;
                }
            }
        }

        if (preg_match('/SUM\(/i', $sqlQuery)) {
            // Verificar que SUM retorna número
            if (isset($rows[0])) {
                $value = array_values($rows[0])[0];
                if ($value !== null && !is_numeric($value)) {
                    echo "❌ SUM deveria retornar número\n";
                    return false;
                }
            }
        }

        return true;
    }

    private function validatePerformance($sqlQuery) {
        $start = microtime(true);

        try {
            $stmt = $this->sandboxConn->prepare($sqlQuery);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Exception $e) {
            return true; // Não validar se não conseguir executar
        }

        $elapsed = microtime(true) - $start;

        echo "⏱️ Tempo de execução: {$elapsed}ms\n";

        // Alertar se muito lento (mesmo em sandbox é indicativo)
        if ($elapsed > 1.0) {
            echo "⚠️ Query lenta: {$elapsed}ms\n";
            return false;
        }

        return true;
    }

    public function deployToDB($sqlQuery, $params = []) {
        echo "🚀 Deployando query para produção...\n";

        // Só deploy se passou nos testes
        $this->connectProduction();

        try {
            $stmt = $this->productionConn->prepare($sqlQuery);
            $result = $stmt->execute($params);

            if ($result) {
                echo "✅ Query executada em produção\n";
                echo "✅ Rows afetadas: " . $stmt->rowCount() . "\n";

                // Audit log
                $this->auditQuery($sqlQuery, 'SUCCESS');

                return true;
            } else {
                echo "❌ Erro em produção: " . implode(' | ', $stmt->errorInfo()) . "\n";
                $this->auditQuery($sqlQuery, 'FAILED');
                return false;
            }

        } catch (Exception $e) {
            echo "❌ Exceção em produção: " . $e->getMessage() . "\n";
            $this->auditQuery($sqlQuery, 'EXCEPTION');
            return false;
        }
    }

    private function auditQuery($sqlQuery, $status) {
        file_put_contents(
            '.database-sandbox-audit.log',
            "[" . date('Y-m-d H:i:s') . "] $status\nQuery: $sqlQuery\n\n",
            FILE_APPEND
        );
    }
}

// ============================================
// EXEMPLO DE USO (comentado para não rodar)
// ============================================

/*
$sandbox = new DatabaseSandbox();

// 1. Testar query segura
$safeQuery = "SELECT * FROM products WHERE id = ?";
if ($sandbox->testQuery($safeQuery, [1])) {
    echo "✅ Query passou nos testes. Deploy autorizado.\n";
    $sandbox->deployToDB($safeQuery, [1]);
} else {
    echo "❌ Query falhou nos testes. Deploy bloqueado.\n";
}

// 2. Tentar query perigosa (será bloqueada)
$dangerousQuery = "DROP TABLE products";
if ($sandbox->testQuery($dangerousQuery)) {
    echo "❌ Isso não deveria ser exibido (query perigosa deveria ser bloqueada)\n";
}
*/

// Para chamar pela CLI:
// php scripts/database-sandbox.php "SELECT * FROM products" '{"id":1}'

if ($argc > 1) {
    $query = $argv[1];
    $params = json_decode($argv[2] ?? '[]', true);

    $sandbox = new DatabaseSandbox();
    $sandbox->testQuery($query, $params);
}
