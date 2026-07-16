<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: #1a1a2e; padding: 1rem; color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .page-title { font-size: 2rem; margin-bottom: 2rem; color: #333; }
        .orders-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; }
        .orders-table th { background: #f8f9fa; padding: 1rem; text-align: left; font-weight: 600; border-bottom: 2px solid #dee2e6; }
        .orders-table td { padding: 1rem; border-bottom: 1px solid #dee2e6; }
        .orders-table tr:hover { background: #f8f9fa; }
        .status { padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-shipped { background: #cfe2ff; color: #084298; }
        .empty-state { text-align: center; padding: 3rem; color: #666; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container" style="display: flex; justify-content: space-between;">
            <div style="color: white;">Admin / Pedidos</div>
            <a href="/admin/" style="color: white; text-decoration: none;">← Voltar</a>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">Gestão de Pedidos</h1>

        <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>#ID</th>
                        <th>Cliente</th>
                        <th>Data</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>#001</strong></td>
                        <td>João Silva</td>
                        <td>2026-07-14</td>
                        <td>R$ 150,00</td>
                        <td><span class="status status-confirmed">Confirmado</span></td>
                        <td><button style="padding:0.5rem 1rem; background:#667eea; color:white; border:none; border-radius:4px; cursor:pointer;">Ver</button></td>
                    </tr>
                    <tr>
                        <td><strong>#002</strong></td>
                        <td>Maria Santos</td>
                        <td>2026-07-13</td>
                        <td>R$ 89,90</td>
                        <td><span class="status status-shipped">Enviado</span></td>
                        <td><button style="padding:0.5rem 1rem; background:#667eea; color:white; border:none; border-radius:4px; cursor:pointer;">Ver</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
