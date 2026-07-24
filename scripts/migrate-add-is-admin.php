<?php
/**
 * Migração: Adicionar coluna is_admin à tabela users
 * Executar uma vez para completar a schema
 */

require_once __DIR__ . '/../config/database.php';

echo "🔄 Adicionando coluna is_admin à tabela users...\n";

try {
    $db = Database::getInstance()->getConnection();

    // Verificar se coluna já existe
    $result = $db->query("SHOW COLUMNS FROM users WHERE Field = 'is_admin'");
    if ($result && $result->num_rows > 0) {
        echo "✅ Coluna is_admin já existe\n";
        exit(0);
    }

    // Adicionar coluna
    $alterSql = "ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT 0 AFTER cpf";
    if ($db->query($alterSql)) {
        echo "✅ Coluna is_admin adicionada com sucesso\n";

        // Verificar se há algum usuário admin (primeiro usuário)
        $adminCheck = $db->query("SELECT COUNT(*) as c FROM users WHERE is_admin = 1")->fetch_assoc();
        if ($adminCheck['c'] == 0) {
            // Marcar primeiro usuário como admin
            $db->query("UPDATE users SET is_admin = 1 ORDER BY id ASC LIMIT 1");
            echo "✅ Primeiro usuário marcado como admin\n";
        }

        exit(0);
    } else {
        echo "❌ Erro ao adicionar coluna: " . $db->error . "\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
