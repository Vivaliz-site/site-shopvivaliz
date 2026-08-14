<?php
declare(strict_types=1);

// SEGURANCA: esta pagina liga/desliga o frete gratis e define o valor minimo.
// Estava acessivel sem autenticacao -- confirmado em producao com HTTP 200
// anonimo. Um POST anonimo podia habilitar frete gratis com limiar zero para
// toda a loja. A pagina irma admin/configuracoes-frete.php ja era protegida.
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../includes/site-settings.php';
require_once __DIR__ . '/../includes/csrf.php';

$message = '';

// POST: Salvar configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sv_csrf_valid('admin-settings-frete', $_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $message = '⚠️ Sessão expirada ou token inválido. Recarregue a página.';
    } else {
        $enabled = isset($_POST['free_shipping_enabled']) ? '1' : '0';
        $threshold = (float)($_POST['free_shipping_threshold'] ?? '0');

        if ($threshold < 0) $threshold = 0;

        sv_setting_set('free_shipping_enabled', $enabled);
        sv_setting_set('free_shipping_threshold', (string)$threshold);

        $message = '✅ Configurações atualizadas com sucesso!';
    }
}

// GET: Carregar configurações atuais
$config = sv_free_shipping_config();
$enabled = $config['enabled'] ? 'checked' : '';
$threshold = $config['threshold'] > 0 ? $config['threshold'] : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurações de Frete Grátis</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .message {
            padding: 12px 16px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 25px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        input[type="text"]:focus,
        input[type="number"]:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .checkbox-label {
            font-weight: normal;
            margin: 0;
            cursor: pointer;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
            color: #1565c0;
            margin-bottom: 20px;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save {
            background: #4CAF50;
            color: white;
            flex: 1;
        }
        .btn-save:hover {
            background: #45a049;
        }
        .btn-cancel {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
        }
        .btn-cancel:hover {
            background: #efefef;
        }
        .field-help {
            font-size: 12px;
            color: #999;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Configurações de Frete Grátis</h1>
        <p class="subtitle">Controle se o frete grátis está ativado no site e defina o valor mínimo de compra.</p>

        <?php if ($message !== ""): ?>
            <div class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="info-box">
            💡 <strong>Status atual:</strong> Frete grátis está <?= $config['enabled'] ? '<strong style="color: #4CAF50;">✅ ATIVADO</strong>' : '<strong style="color: #f44336;">❌ DESATIVADO</strong>' ?>
        </div>

        <form method="POST">
            <?= sv_csrf_input('admin-settings-frete') ?>
            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="free_shipping_enabled" name="free_shipping_enabled" value="1" <?= $enabled ?>>
                    <label for="free_shipping_enabled" class="checkbox-label">Ativar Frete Grátis</label>
                </div>
                <div class="field-help">Quando desativado, o frete grátis não será exibido na home e na navbar.</div>
            </div>

            <div class="form-group">
                <label for="free_shipping_threshold">Valor mínimo de compra (R$)</label>
                <input type="number" id="free_shipping_threshold" name="free_shipping_threshold" step="0.01" min="0" value="<?= htmlspecialchars($threshold, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 199.90">
                <div class="field-help">Clientes recebem frete grátis ao atingir este valor. Se zero, frete grátis em todas as compras.</div>
            </div>

            <div class="button-group">
                <button type="submit" class="btn-save">💾 Salvar Configurações</button>
                <button type="button" class="btn-cancel" onclick="window.history.back()">Cancelar</button>
            </div>
        </form>
    </div>
</body>
</html>
