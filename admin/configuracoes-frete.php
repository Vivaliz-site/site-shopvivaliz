<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/site-settings.php';
require_once __DIR__ . '/../includes/csrf.php';

$config = sv_free_shipping_config();
$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (sv_csrf_valid('admin-frete', $_POST['csrf_token'] ?? null)) {
        $enabled = !empty($_POST['enabled']);
        $threshold = max(0.0, (float)str_replace(',', '.', (string)($_POST['threshold'] ?? '0')));
        sv_setting_set('free_shipping_enabled', $enabled ? '1' : '0');
        sv_setting_set('free_shipping_threshold', (string)$threshold);
        $config = sv_free_shipping_config();
        $saved = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Configurações de Frete - Admin</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 30px; }
        h1 { color: #173B63; }
        .card { background: white; border-radius: 12px; padding: 24px; max-width: 480px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        label { display: block; margin-bottom: 14px; font-size: 14px; }
        input[type="number"] { padding: 10px; border: 1px solid #ddd; border-radius: 6px; width: 100%; box-sizing: border-box; margin-top: 4px; }
        .toggle-row { display: flex; align-items: center; gap: 10px; }
        button { background: #173B63; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .saved { background: #dcfce7; color: #166534; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
    <h1>Configurações de Frete Grátis</h1>
    <p><a href="/admin/">← Admin</a></p>
    <div class="card">
        <?php if ($saved): ?><div class="saved">Configurações salvas com sucesso.</div><?php endif; ?>
        <form method="POST">
            <?= sv_csrf_input('admin-frete') ?>
            <label class="toggle-row">
                <input type="checkbox" name="enabled" value="1" <?= $config['enabled'] ? 'checked' : '' ?>>
                Habilitar frete grátis no site
            </label>
            <label>
                Valor mínimo para frete grátis (R$)
                <input type="number" name="threshold" step="0.01" min="0" value="<?= htmlspecialchars((string)$config['threshold']) ?>">
            </label>
            <p style="font-size:13px;color:#666;">Enquanto desabilitado, nenhuma mensagem de frete grátis é exibida no site (carrinho, barra de progresso, banners).</p>
            <button type="submit">Salvar</button>
        </form>
    </div>
</body>
</html>
