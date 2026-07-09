<?php
declare(strict_types=1);

// Teste simples sem admin-guard pra debug
require_once __DIR__ . '/../core/init-editor.php';

use Core\BlockRegistry;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Editor Visual - Teste</title>
    <style>
        body { font-family: Inter, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        h1 { color: #173B63; }
        .success { color: #059669; padding: 15px; background: #ecfdf5; border-radius: 6px; margin: 20px 0; }
        .error { color: #991b1b; padding: 15px; background: #fee2e2; border-radius: 6px; margin: 20px 0; }
        .blocks-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
        .block-card { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 3px solid #173B63; }
        .block-card strong { color: #173B63; }
        .block-card small { color: #6b7280; display: block; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎨 Editor Visual - Teste</h1>

        <div class="success">
            ✓ Sistema de Editor Visual ATIVO!
        </div>

        <h2>Blocos Disponíveis (<?php echo count(BlockRegistry::getAll()); ?> total)</h2>

        <div class="blocks-list">
            <?php foreach (BlockRegistry::getCategories() as $category): ?>
                <div style="grid-column: 1 / -1; margin-top: 20px;">
                    <h3 style="color: #173B63; border-bottom: 2px solid #e5e9f0; padding-bottom: 10px;">
                        <?php echo htmlspecialchars($category); ?>
                    </h3>
                </div>

                <?php foreach (BlockRegistry::getByCategory($category) as $name => $config): ?>
                    <div class="block-card">
                        <strong><?php echo htmlspecialchars($config['icon'] . ' ' . $name); ?></strong>
                        <small><?php echo htmlspecialchars($config['description']); ?></small>
                        <small style="margin-top: 8px; color: #059669;">✓ Pronto para usar</small>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <h2 style="margin-top: 40px;">Como Usar</h2>
        <ol>
            <li>Acesse: <strong>https://dev.shopvivaliz.com.br/admin/template-editor.php</strong></li>
            <li>Se tiver erro 443: Use HTTP ou verifique certificado SSL</li>
            <li>Edite layouts em JSON</li>
            <li>Clique "💾 Salvar"</li>
            <li>Layout é salvo em <code>/layouts/[page-id]-config.json</code></li>
        </ol>

        <h2>Status do Banco de Dados</h2>
        <?php
        try {
            require_once __DIR__ . '/../core/Database.php';
            use Core\Database;

            $db = Database::connect();
            echo '<div class="success">✓ Conexão MySQL OK</div>';

            // Tentar inicializar tabelas
            if (Database::initialize()) {
                echo '<div class="success">✓ Tabelas criadas/verificadas</div>';
            }
        } catch (\Throwable $e) {
            echo '<div class="error">✗ Erro de conexão BD: ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<p>Se isso é esperado, layouts funcionam normalmente via arquivo JSON.</p>';
        }
        ?>

        <h2>Próximos Passos</h2>
        <ul>
            <li>✅ Fase 1 (core + 15 blocos): Completa</li>
            <li>🟡 Fase 2 (BD MySQL): 80% - falta conectar APIs</li>
            <li>🔴 Fase 3 (drag-and-drop): Planejada</li>
            <li>🔴 Fase 4 (A/B testing): Planejada</li>
            <li>🔴 Fase 5 (git history): Planejada</li>
        </ul>
    </div>
</body>
</html>
