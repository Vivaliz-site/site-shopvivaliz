<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../includes/csrf.php';

function svi_env_upsert(string $path, string $key, string $value): bool {
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || str_contains($value, "\n") || str_contains($value, "\r")) return false;
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    if (!is_array($lines)) $lines = [];
    $replacement = $key . '=' . $value;
    $found = false;
    foreach ($lines as &$line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', (string)$line)) {
            $line = $replacement;
            $found = true;
        }
    }
    unset($line);
    if (!$found) $lines[] = $replacement;
    return file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) !== false;
}

$integrations = [
    'facebook' => [
        'name' => 'Facebook',
        'fields' => [
            'page_id' => ['label' => 'Page ID', 'env' => 'FACEBOOK_PAGE_ID', 'value' => getenv('FACEBOOK_PAGE_ID') ?: '919743089739419'],
            'access_token' => ['label' => 'Access Token', 'env' => 'FACEBOOK_ACCESS_TOKEN', 'value' => getenv('FACEBOOK_ACCESS_TOKEN') ? '***REDACTED***' : '', 'type' => 'password'],
        ]
    ],
    'google' => [
        'name' => 'Google',
        'fields' => [
            'analytics_id' => ['label' => 'Analytics ID', 'env' => 'GOOGLE_ANALYTICS_ID', 'value' => getenv('GOOGLE_ANALYTICS_ID') ?: 'G-1H55K1TZ5D'],
            'merchant_id' => ['label' => 'Merchant ID', 'env' => 'GOOGLE_MERCHANT_ID', 'value' => getenv('GOOGLE_MERCHANT_ID') ?: '5381803710'],
            'tag_manager_id' => ['label' => 'Tag Manager ID', 'env' => 'GOOGLE_TAG_MANAGER_ID', 'value' => getenv('GOOGLE_TAG_MANAGER_ID') ?: 'GTM-PHZ55CP3'],
        ]
    ],
    'communication' => [
        'name' => 'Comunicação',
        'fields' => [
            'whatsapp' => ['label' => 'WhatsApp', 'env' => 'WHATSAPP_NUMBER', 'value' => getenv('WHATSAPP_NUMBER') ?: '+5537999374112'],
        ]
    ],
];

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('admin-integrations', $_POST['csrf_token'] ?? null)) {
    $message = 'Sessão expirada. Recarregue a página.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_integration'])) {
    $integration = $_POST['integration'] ?? '';
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';

    if (isset($integrations[$integration]['fields'][$field])) {
        $envVar = $integrations[$integration]['fields'][$field]['env'];
        $value = trim((string)$value);
        if ($value === '***REDACTED***') {
            $message = 'Nenhuma alteração: informe um novo valor para substituir o segredo atual.';
        } elseif (!svi_env_upsert(__DIR__ . '/../.env', $envVar, $value)) {
            $message = 'Não foi possível salvar a integração com segurança.';
        } else {
            $message = "✅ Integração '$integration' atualizada!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrações - Admin ShopVivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        header {
            background: white;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { font-size: 24px; margin-bottom: 10px; }
        .breadcrumb { font-size: 12px; color: #666; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #0066cc;
            border-bottom: 2px solid #0066cc;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input[type="text"],
        input[type="password"],
        input[type="email"],
        input[type="tel"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Courier New', monospace;
        }
        input:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        button {
            width: 100%;
            padding: 12px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        button:hover {
            background: #0052a3;
        }
        .message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .status {
            font-size: 12px;
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            background: #f0f0f0;
        }
        .status.active { background: #d4edda; color: #155724; }
        .status.inactive { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔗 Integrações e Credenciais</h1>
            <div class="breadcrumb">Admin → Integrações</div>
        </header>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="grid">
            <?php foreach ($integrations as $key => $integration): ?>
                <div class="card">
                    <div class="card-title"><?= htmlspecialchars($integration['name']) ?></div>
                    <form method="POST">
                        <?= sv_csrf_input('admin-integrations') ?>
                        <?php foreach ($integration['fields'] as $fieldKey => $field): ?>
                            <div class="form-group">
                                <label><?= htmlspecialchars($field['label']) ?></label>
                                <input
                                    type="<?= $field['type'] ?? 'text' ?>"
                                    name="value"
                                    value="<?= htmlspecialchars($field['value']) ?>"
                                    <?= ($field['type'] === 'password' && $field['value'] === '***REDACTED***') ? 'readonly' : '' ?>
                                    placeholder="<?= htmlspecialchars($field['label']) ?>"
                                >
                                <div class="status <?= (!empty($field['value']) && $field['value'] !== '***REDACTED***') ? 'active' : 'inactive' ?>">
                                    <?= (!empty($field['value']) && $field['value'] !== '***REDACTED***') ? '✅ Configurado' : '⚠️ Vazio' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <input type="hidden" name="integration" value="<?= htmlspecialchars($key) ?>">
                        <input type="hidden" name="field" value="<?= array_key_first($integration['fields']) ?>">
                        <input type="hidden" name="save_integration" value="1">
                        <button type="submit">💾 Salvar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; margin-top: 30px;">
            <h2 style="margin-bottom: 15px;">📋 Resumo de Integrações</h2>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                        <th style="padding: 10px; text-align: left; font-weight: 600;">Integração</th>
                        <th style="padding: 10px; text-align: left; font-weight: 600;">Status</th>
                        <th style="padding: 10px; text-align: left; font-weight: 600;">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($integrations as $intKey => $int): ?>
                        <?php foreach ($int['fields'] as $fKey => $f): ?>
                            <tr style="border-bottom: 1px solid #ddd;">
                                <td style="padding: 10px;"><?= htmlspecialchars($f['label']) ?></td>
                                <td style="padding: 10px;">
                                    <span style="<?= (!empty($f['value']) && $f['value'] !== '***REDACTED***') ? 'color: green;' : 'color: orange;' ?>">
                                        <?= (!empty($f['value']) && $f['value'] !== '***REDACTED***') ? '✅ OK' : '⚠️ Vazio' ?>
                                    </span>
                                </td>
                                <td style="padding: 10px; font-family: 'Courier New', monospace; font-size: 12px;">
                                    <?= $f['value'] === '***REDACTED***' ? '***REDACTED***' : htmlspecialchars($f['value']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
