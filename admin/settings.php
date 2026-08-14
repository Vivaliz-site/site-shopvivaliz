<?php
declare(strict_types=1);

// SEGURANCA: esta pagina grava em site_settings (links de redes sociais,
// incluindo o WhatsApp usado pelo botao de atendimento do site) e estava
// acessivel sem autenticacao -- confirmado em producao com HTTP 200 anonimo.
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../includes/site-settings.php';
require_once __DIR__ . '/../includes/csrf.php';

$message = '';
$error = '';

$fields = [
    'instagram' => 'Instagram',
    'facebook' => 'Facebook',
    'tiktok' => 'TikTok',
    'youtube' => 'YouTube',
    'whatsapp' => 'WhatsApp',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sv_csrf_valid('admin-settings', $_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Sessao expirada ou token invalido. Recarregue a pagina e tente de novo.';
    } else {
    try {
        foreach ($fields as $key => $label) {
            $value = trim((string)($_POST[$key] ?? ''));
            sv_setting_set('social_' . $key, $value);
        }
        $message = 'Configurações salvas com sucesso.';
    } catch (Throwable $e) {
        error_log('[AdminSettings] save failed: ' . $e->getMessage());
        $error = 'Não foi possível salvar as configurações agora.';
    }
    }
}

$values = [];
foreach ($fields as $key => $label) {
    $values[$key] = (string)(sv_setting_get('social_' . $key, '') ?? '');
}

function sv_admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurações Gerais | ShopVivaliz Admin</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }
        .container {
            max-width: 760px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, .08);
            padding: 28px;
        }
        h1 { margin: 0 0 8px; font-size: 28px; }
        .subtitle { margin: 0 0 26px; color: #64748b; }
        .message, .error {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
        }
        .message { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-weight: 700; margin-bottom: 7px; }
        input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
        }
        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }
        .help { color: #64748b; font-size: 12px; margin-top: 5px; }
        .actions { display: flex; gap: 10px; margin-top: 26px; }
        button, .back {
            border: 0;
            border-radius: 8px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }
        button { background: #16a34a; color: #fff; flex: 1; }
        .back { background: #e2e8f0; color: #1e293b; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>Configurações Gerais</h1>
        <p class="subtitle">Preencha os endereços das redes sociais exibidas na loja.</p>

        <?php if ($message !== ''): ?>
            <div class="message"><?= sv_admin_h($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= sv_admin_h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?= sv_csrf_input('admin-settings') ?>
            <div class="form-group">
                <label for="instagram">Instagram</label>
                <input id="instagram" name="instagram" type="url" value="<?= sv_admin_h($values['instagram']) ?>" placeholder="https://instagram.com/shopvivaliz">
            </div>

            <div class="form-group">
                <label for="facebook">Facebook</label>
                <input id="facebook" name="facebook" type="url" value="<?= sv_admin_h($values['facebook']) ?>" placeholder="https://facebook.com/shopvivaliz">
            </div>

            <div class="form-group">
                <label for="tiktok">TikTok</label>
                <input id="tiktok" name="tiktok" type="url" value="<?= sv_admin_h($values['tiktok']) ?>" placeholder="https://tiktok.com/@shopvivaliz">
            </div>

            <div class="form-group">
                <label for="youtube">YouTube</label>
                <input id="youtube" name="youtube" type="url" value="<?= sv_admin_h($values['youtube']) ?>" placeholder="https://youtube.com/@shopvivaliz">
            </div>

            <div class="form-group">
                <label for="whatsapp">WhatsApp</label>
                <input id="whatsapp" name="whatsapp" type="text" value="<?= sv_admin_h($values['whatsapp']) ?>" placeholder="5537999999999">
                <div class="help">Informe somente números com DDI e DDD, ou uma URL completa do WhatsApp.</div>
            </div>

            <div class="actions">
                <a class="back" href="/admin/">Voltar</a>
                <button type="submit">Salvar configurações</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
