<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: #1a1a2e; padding: 1rem; color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .page-title { font-size: 2rem; margin-bottom: 2rem; color: #333; }
        .btn { padding: 0.75rem 1.5rem; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .clients-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .clients-table th { background: #f8f9fa; padding: 1rem; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6; }
        .clients-table td { padding: 1rem; border-bottom: 1px solid #dee2e6; }
        .clients-table tr:hover { background: #f8f9fa; }
        .empty-state { text-align: center; padding: 3rem; color: #666; }
        .status { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>🛍️ ShopVivaliz Admin / Clientes</div>
                <a href="/admin/" style="color: white; text-decoration: none;">← Voltar</a>
            </div>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">Gestão de Clientes</h1>

        <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <table class="clients-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Telefone</th>
                        <th>Pedidos</th>
                        <th>Total Gasto</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="clients-body">
                    <tr><td colspan="6" class="empty-state">Carregando clientes... (dados vêm de pedidos realizados)</td></tr>
                </tbody>
            </table>
        </div>

        <p style="margin-top: 2rem; color: #666; font-size: 0.9rem;">
            <strong>ℹ️ Nota:</strong> Clientes são criados automaticamente quando fazem pedidos no checkout.
            Integração com CRM/banco de dados em breve.
        </p>
    </div>

    <script>
    // Placeholder - dados viriam do banco de dados
    document.getElementById('clients-body').innerHTML = `
        <tr>
            <td>João Silva</td>
            <td>joao@example.com</td>
            <td>11987654321</td>
            <td>3</td>
            <td>R$ 450,00</td>
            <td><button style="padding:0.5rem 1rem; background:#667eea; color:white; border:none; border-radius:4px; cursor:pointer;">Ver</button></td>
        </tr>
        <tr>
            <td>Maria Santos</td>
            <td>maria@example.com</td>
            <td>11987654322</td>
            <td>1</td>
            <td>R$ 120,00</td>
            <td><button style="padding:0.5rem 1rem; background:#667eea; color:white; border:none; border-radius:4px; cursor:pointer;">Ver</button></td>
        </tr>
    `;
    </script>
</body>
</html>
