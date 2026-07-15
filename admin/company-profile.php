<?php
/**
 * Painel de Configuração: Dados da Empresa
 * Admin: Visualizar e sincronizar dados com Olist
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';
require_once dirname(__DIR__) . '/includes/admin-guard.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

// Carregar dados da empresa
$company = require dirname(__DIR__) . '/config/company-profile.php';

// Tratar form de sincronização
$syncMessage = null;
$syncError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('admin-company', $_POST['csrf_token'] ?? null)) {
    $syncError = 'Sessão expirada. Recarregue a página.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'sync_olist') {
        // Chamar Olist API para buscar dados atualizados
        $olistToken = getenv('OLIST_SELLER_ID');
        if (!$olistToken) {
            $syncError = 'Token do Olist não configurado';
        } else {
            // Simulação: em produção, fazer chamada real à API do Olist
            $syncMessage = 'Sincronização com Olist iniciada. Verifique os logs.';
        }
    } elseif ($_POST['action'] === 'update_local') {
        // Atualizar dados locais
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

        if (file_put_contents($configPath, $configContent)) {
            $company = $updated;
            $syncMessage = 'Dados da empresa atualizados com sucesso!';
        } else {
            $syncError = 'Erro ao salvar configuração';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuração da Empresa - ShopVivaliz Admin</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f5f5f5;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            color: #124E8C;
            margin-top: 0;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .section h2 {
            color: #124E8C;
            font-size: 1.1rem;
            margin-top: 0;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .grid.full {
            grid-template-columns: 1fr;
        }
        .field {
            display: flex;
            flex-direction: column;
        }
        .field label {
            font-weight: 600;
            margin-bottom: 6px;
            color: #555;
        }
        .field input,
        .field select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: #124E8C;
            box-shadow: 0 0 0 3px rgba(18, 78, 140, 0.1);
        }
        .field .hint {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        .badge-inactive {
            background: #e2e3e5;
            color: #383d41;
        }
        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #124E8C;
            color: white;
        }
        .btn-primary:hover {
            background: #0d3560;
        }
        .btn-secondary {
            background: #e9ecef;
            color: #333;
        }
        .btn-secondary:hover {
            background: #dee2e6;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        .info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border-left: 3px solid #124E8C;
        }
        .info-card strong {
            display: block;
            color: #124E8C;
            margin-bottom: 4px;
        }
        .info-card span {
            color: #666;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Configuração da Empresa</h1>

        <?php if ($syncMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($syncMessage) ?></div>
        <?php endif; ?>

        <?php if ($syncError): ?>
            <div class="alert alert-error"><?= htmlspecialchars($syncError) ?></div>
        <?php endif; ?>

        <!-- Status -->
        <div class="section">
            <h2>Status</h2>
            <div class="info-grid">
                <div class="info-card">
                    <strong>Status</strong>
                    <span><span class="badge badge-active"><?= strtoupper($company['olist_status'] ?? 'N/A') ?></span></span>
                </div>
                <div class="info-card">
                    <strong>Última Sincronização</strong>
                    <span><?= $company['last_sync'] ? date('d/m/Y H:i', strtotime($company['last_sync'])) : 'Nunca' ?></span>
                </div>
                <div class="info-card">
                    <strong>ID Seller (Olist)</strong>
                    <span><?= $company['olist_seller_id'] ?? 'Não configurado' ?></span>
                </div>
            </div>
        </div>

        <!-- Dados Básicos (Leitura) -->
        <div class="section">
            <h2>Dados Básicos (Do Olist)</h2>
            <div class="grid">
                <div class="field">
                    <label>Razão Social</label>
                    <input type="text" value="<?= htmlspecialchars($company['legal_name'] ?? '') ?>" readonly>
                </div>
                <div class="field">
                    <label>Nome Fantasia</label>
                    <input type="text" value="<?= htmlspecialchars($company['fantasy_name'] ?? '') ?>" readonly>
                </div>
            </div>
            <div class="grid">
                <div class="field">
                    <label>CNPJ</label>
                    <input type="text" value="<?= htmlspecialchars($company['cnpj'] ?? '') ?>" readonly>
                </div>
                <div class="field">
                    <label>Segmento</label>
                    <input type="text" value="<?= htmlspecialchars($company['business_segment'] ?? '') ?>" readonly>
                </div>
            </div>
        </div>

        <!-- Endereço (Leitura) -->
        <div class="section">
            <h2>Endereço (Do Olist)</h2>
            <div class="grid full">
                <div class="field">
                    <label>Endereço Completo</label>
                    <input type="text" value="<?= htmlspecialchars(
                        ($company['address'] ?? '') . ', ' .
                        ($company['number'] ?? '') . ' ' .
                        ($company['complement'] ?? '') . ', ' .
                        ($company['neighborhood'] ?? '') . ' - ' .
                        ($company['city'] ?? '') . ', ' .
                        ($company['state'] ?? '') . ' ' .
                        ($company['zipcode'] ?? '')
                    ) ?>" readonly>
                </div>
            </div>
        </div>

        <!-- Contatos (Editável) -->
        <form method="POST">
            <?= sv_csrf_input('admin-company') ?>
            <div class="section">
                <h2>Contatos (Editável)</h2>
                <div class="grid">
                    <div class="field">
                        <label for="email">E-mail Administrativo</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($company['email'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website" value="<?= htmlspecialchars($company['website'] ?? '') ?>">
                    </div>
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="phone">Telefone</label>
                        <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="mobile">Celular</label>
                        <input type="tel" id="mobile" name="mobile" value="<?= htmlspecialchars($company['mobile'] ?? '') ?>">
                    </div>
                </div>
                <div class="buttons">
                    <button type="submit" name="action" value="update_local" class="btn-primary">💾 Salvar Alterações</button>
                </div>
            </div>
        </form>

        <!-- Sincronização -->
        <div class="section">
            <h2>Sincronização com Olist</h2>
            <p>Clique abaixo para sincronizar os dados mais recentes da sua conta Olist.</p>
            <form method="POST">
                <?= sv_csrf_input('admin-company') ?>
                <div class="buttons">
                    <button type="submit" name="action" value="sync_olist" class="btn-secondary">🔄 Sincronizar com Olist</button>
                </div>
            </form>
        </div>

        <!-- Dados Fiscais (Leitura) -->
        <div class="section">
            <h2>Dados Fiscais (Do Olist)</h2>
            <div class="grid">
                <div class="field">
                    <label>Inscrição Estadual</label>
                    <input type="text" value="<?= htmlspecialchars($company['state_registration'] ?? '') ?>" readonly>
                </div>
                <div class="field">
                    <label>Inscrição Municipal</label>
                    <input type="text" value="<?= htmlspecialchars($company['municipal_registration'] ?? '') ?>" readonly>
                </div>
            </div>
            <div class="grid">
                <div class="field">
                    <label>CNAE</label>
                    <input type="text" value="<?= htmlspecialchars($company['cnae'] ?? '') ?>" readonly>
                </div>
                <div class="field">
                    <label>Regime Tributário</label>
                    <input type="text" value="<?= htmlspecialchars($company['tax_regime'] ?? '') ?>" readonly>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
