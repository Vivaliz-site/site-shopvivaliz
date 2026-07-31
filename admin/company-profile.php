<?php
/**
 * Painel de Configuração: Dados da Empresa
 * Admin: Visualizar e sincronizar dados com Olist
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';
require_once dirname(__DIR__) . '/includes/admin-guard.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

$company = require dirname(__DIR__) . '/config/company-profile.php';
$syncMessage = null;
$syncError = null;

function sv_normalize_social_url(string $value, bool $isWhatsapp = false): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if ($isWhatsapp) {
        $digits = preg_replace('/\D+/', '', $value);
        return $digits !== '' ? $digits : null;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Informe uma URL válida, começando com http:// ou https://.');
    }

    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Apenas URLs http e https são permitidas.');
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('admin-company', $_POST['csrf_token'] ?? null)) {
    $syncError = 'Sessão expirada. Recarregue a página.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'sync_olist') {
        $olistToken = getenv('OLIST_SELLER_ID');
        if (!$olistToken) {
            $syncError = 'Token do Olist não configurado';
        } else {
            $syncMessage = 'Sincronização com Olist iniciada. Verifique os logs.';
        }
    } elseif ($_POST['action'] === 'update_local') {
        $updates = [
            'fantasy_name' => $_POST['fantasy_name'] ?? $company['fantasy_name'],
            'email'        => $_POST['email'] ?? $company['email'],
            'phone'        => $_POST['phone'] ?? $company['phone'],
            'mobile'       => $_POST['mobile'] ?? $company['mobile'],
            'website'      => $_POST['website'] ?? $company['website'],
        ];

        $updated = array_merge($company, $updates);
        $updated['last_sync'] = date('c');

        $configPath = dirname(__DIR__) . '/config/company-profile.php';
        $configContent = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($updated, true) . ";\n";

        if (file_put_contents($configPath, $configContent, LOCK_EX)) {
            $company = $updated;
            $syncMessage = 'Dados da empresa atualizados com sucesso!';
        } else {
            $syncError = 'Erro ao salvar configuração';
        }
    } elseif ($_POST['action'] === 'update_social_media') {
        try {
            $socialMedia = [
                'facebook'  => sv_normalize_social_url((string)($_POST['facebook'] ?? '')),
                'instagram' => sv_normalize_social_url((string)($_POST['instagram'] ?? '')),
                'whatsapp'  => sv_normalize_social_url((string)($_POST['whatsapp'] ?? ''), true),
                'tiktok'    => sv_normalize_social_url((string)($_POST['tiktok'] ?? '')),
                'youtube'   => sv_normalize_social_url((string)($_POST['youtube'] ?? '')),
                'linkedin'  => sv_normalize_social_url((string)($_POST['linkedin'] ?? '')),
                'twitter'   => sv_normalize_social_url((string)($_POST['twitter'] ?? '')),
                'pinterest' => sv_normalize_social_url((string)($_POST['pinterest'] ?? '')),
                'telegram'  => sv_normalize_social_url((string)($_POST['telegram'] ?? '')),
            ];

            $updated = $company;
            $updated['social_media'] = $socialMedia;
            $updated['last_sync'] = date('c');

            $configPath = dirname(__DIR__) . '/config/company-profile.php';
            $configContent = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($updated, true) . ";\n";

            if (file_put_contents($configPath, $configContent, LOCK_EX)) {
                $company = $updated;
                $syncMessage = 'Links das redes sociais atualizados com sucesso!';
            } else {
                $syncError = 'Erro ao salvar os links das redes sociais.';
            }
        } catch (InvalidArgumentException $e) {
            $syncError = $e->getMessage();
        }
    }
}

$socialMedia = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuração da Empresa - ShopVivaliz Admin</title>
    <style>
        body {font-family: system-ui, -apple-system, sans-serif;background:#f5f5f5;color:#333;margin:0;padding:20px}
        .container {max-width:900px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);padding:30px}
        h1 {color:#124E8C;margin-top:0}.alert{padding:12px 16px;border-radius:4px;margin-bottom:20px}.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}.section{margin-bottom:30px;padding-bottom:20px;border-bottom:1px solid #eee}.section h2{color:#124E8C;font-size:1.1rem;margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}.grid.full{grid-template-columns:1fr}.field{display:flex;flex-direction:column}.field label{font-weight:600;margin-bottom:6px;color:#555}.field input,.field select{padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px}.field input:focus,.field select:focus{outline:none;border-color:#124E8C;box-shadow:0 0 0 3px rgba(18,78,140,.1)}.field .hint{font-size:12px;color:#777;margin-top:4px}.buttons{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}button,.btn-link{padding:10px 20px;border:none;border-radius:4px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}.btn-primary{background:#124E8C;color:#fff}.btn-secondary{background:#e9ecef;color:#333}.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-top:15px}.info-card{background:#f8f9fa;padding:15px;border-radius:4px;border-left:3px solid #124E8C}.info-card strong{display:block;color:#124E8C;margin-bottom:4px}.badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600}.badge-active{background:#d4edda;color:#155724}.back-link{margin-bottom:20px;display:inline-block;color:#124E8C;text-decoration:none;font-weight:600}@media(max-width:700px){.grid,.info-grid{grid-template-columns:1fr}}
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
<div class="container">
    <a class="back-link" href="/admin/menu-dashboard.php">← Voltar ao painel</a>
    <h1>⚙️ Configuração da Empresa</h1>

    <?php if ($syncMessage): ?><div class="alert alert-success"><?= htmlspecialchars($syncMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($syncError): ?><div class="alert alert-error"><?= htmlspecialchars($syncError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="section">
        <h2>Status</h2>
        <div class="info-grid">
            <div class="info-card"><strong>Status</strong><span class="badge badge-active"><?= strtoupper(htmlspecialchars((string)($company['olist_status'] ?? 'N/A'), ENT_QUOTES, 'UTF-8')) ?></span></div>
            <div class="info-card"><strong>Última Sincronização</strong><span><?= !empty($company['last_sync']) ? date('d/m/Y H:i', strtotime((string)$company['last_sync'])) : 'Nunca' ?></span></div>
            <div class="info-card"><strong>ID Seller (Olist)</strong><span><?= htmlspecialchars((string)($company['olist_seller_id'] ?? 'Não configurado'), ENT_QUOTES, 'UTF-8') ?></span></div>
        </div>
    </div>

    <div class="section">
        <h2>Dados Básicos</h2>
        <div class="grid">
            <div class="field"><label>Razão Social</label><input type="text" value="<?= htmlspecialchars((string)($company['legal_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
            <div class="field"><label>Nome Fantasia</label><input type="text" value="<?= htmlspecialchars((string)($company['fantasy_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
        </div>
    </div>

    <form method="POST">
        <?= sv_csrf_input('admin-company') ?>
        <div class="section">
            <h2>Contatos</h2>
            <div class="grid">
                <div class="field"><label for="email">E-mail Administrativo</label><input type="email" id="email" name="email" value="<?= htmlspecialchars((string)($company['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="field"><label for="website">Website</label><input type="text" id="website" name="website" value="<?= htmlspecialchars((string)($company['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="grid">
                <div class="field"><label for="phone">Telefone</label><input type="tel" id="phone" name="phone" value="<?= htmlspecialchars((string)($company['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="field"><label for="mobile">Celular</label><input type="tel" id="mobile" name="mobile" value="<?= htmlspecialchars((string)($company['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="buttons"><button type="submit" name="action" value="update_local" class="btn-primary">💾 Salvar Contatos</button></div>
        </div>
    </form>

    <form method="POST">
        <?= sv_csrf_input('admin-company') ?>
        <div class="section">
            <h2>Redes Sociais</h2>
            <p>Somente as redes com link preenchido aparecerão na home. Deixe o campo vazio para ocultar a respectiva logo.</p>
            <div class="grid">
                <div class="field"><label for="facebook">Facebook</label><input type="url" id="facebook" name="facebook" placeholder="https://facebook.com/sua-pagina" value="<?= htmlspecialchars((string)($socialMedia['facebook'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="field"><label for="instagram">Instagram</label><input type="url" id="instagram" name="instagram" placeholder="https://instagram.com/seu-perfil" value="<?= htmlspecialchars((string)($socialMedia['instagram'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="grid">
                <div class="field"><label for="whatsapp">WhatsApp</label><input type="text" id="whatsapp" name="whatsapp" placeholder="5537999999999" value="<?= htmlspecialchars((string)($socialMedia['whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><span class="hint">Use DDI + DDD + número, somente dígitos.</span></div>
                <div class="field"><label for="tiktok">TikTok</label><input type="url" id="tiktok" name="tiktok" placeholder="https://tiktok.com/@seu-perfil" value="<?= htmlspecialchars((string)($socialMedia['tiktok'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="grid">
                <div class="field"><label for="youtube">YouTube</label><input type="url" id="youtube" name="youtube" placeholder="https://youtube.com/@seu-canal" value="<?= htmlspecialchars((string)($socialMedia['youtube'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="field"><label for="linkedin">LinkedIn</label><input type="url" id="linkedin" name="linkedin" placeholder="https://linkedin.com/company/sua-empresa" value="<?= htmlspecialchars((string)($socialMedia['linkedin'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="grid">
                <div class="field"><label for="twitter">X (Twitter)</label><input type="url" id="twitter" name="twitter" placeholder="https://x.com/seu-perfil" value="<?= htmlspecialchars((string)($socialMedia['twitter'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="field"><label for="pinterest">Pinterest</label><input type="url" id="pinterest" name="pinterest" placeholder="https://pinterest.com/seu-perfil" value="<?= htmlspecialchars((string)($socialMedia['pinterest'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="grid full">
                <div class="field"><label for="telegram">Telegram</label><input type="url" id="telegram" name="telegram" placeholder="https://t.me/seu-canal" value="<?= htmlspecialchars((string)($socialMedia['telegram'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>
            <div class="buttons"><button type="submit" name="action" value="update_social_media" class="btn-primary">💾 Salvar Redes Sociais</button></div>
        </div>
    </form>

    <div class="section">
        <h2>Sincronização com Olist</h2>
        <form method="POST">
            <?= sv_csrf_input('admin-company') ?>
            <div class="buttons"><button type="submit" name="action" value="sync_olist" class="btn-secondary">🔄 Sincronizar com Olist</button></div>
        </form>
    </div>
</div>
</body>
</html>
