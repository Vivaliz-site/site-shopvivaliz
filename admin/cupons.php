<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/account-schema.php';

sv_account_ensure_schema();

$pdo = sv_pdo();
$error = '';
$success = '';
$editingId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$query = trim((string)($_GET['q'] ?? ''));

function ac_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ac_dt(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return strpos($value, 'T') !== false ? str_replace('T', ' ', substr($value, 0, 19)) : substr($value, 0, 19);
}

$coupon = null;
if ($pdo instanceof PDO && $editingId > 0) {
    $st = $pdo->prepare('SELECT * FROM coupons WHERE id = :id LIMIT 1');
    $st->execute([':id' => $editingId]);
    $coupon = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sv_csrf_valid('admin-cupons', $_POST['csrf_token'] ?? '')) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    } elseif (!($pdo instanceof PDO)) {
        $error = 'Banco indisponível para gravar cupons.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE coupons SET is_active = IF(is_active = 1, 0, 1), updated_at = NOW() WHERE id = :id');
                $st->execute([':id' => $id]);
                $success = 'Status do cupom atualizado.';
            } else {
                $error = 'Cupom inválido.';
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $description = trim((string)($_POST['description'] ?? ''));
            $discountType = strtolower(trim((string)($_POST['discount_type'] ?? 'percent')));
            if (!in_array($discountType, ['percent', 'fixed', 'shipping'], true)) {
                $discountType = 'percent';
            }
            $discountValue = (float)($_POST['discount_value'] ?? 0);
            $minOrderValue = (float)($_POST['min_order_value'] ?? 0);
            $startsAt = trim((string)($_POST['starts_at'] ?? ''));
            $endsAt = trim((string)($_POST['ends_at'] ?? ''));
            $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
            $maxUses = max(0, (int)($_POST['max_uses'] ?? 0));
            $usedCount = max(0, (int)($_POST['used_count'] ?? 0));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($code === '') {
                $error = 'Informe o código do cupom.';
            } else {
                try {
                    if ($id > 0) {
                        $st = $pdo->prepare(
                            'UPDATE coupons SET
                                code = :code,
                                description = :description,
                                discount_type = :discount_type,
                                discount_value = :discount_value,
                                min_order_value = :min_order_value,
                                starts_at = :starts_at,
                                ends_at = :ends_at,
                                expires_at = :expires_at,
                                max_uses = :max_uses,
                                used_count = :used_count,
                                is_active = :is_active,
                                updated_at = NOW()
                             WHERE id = :id'
                        );
                        $st->execute([
                            ':id' => $id,
                            ':code' => $code,
                            ':description' => $description !== '' ? $description : null,
                            ':discount_type' => $discountType,
                            ':discount_value' => $discountValue,
                            ':min_order_value' => $minOrderValue,
                            ':starts_at' => $startsAt !== '' ? $startsAt : null,
                            ':ends_at' => $endsAt !== '' ? $endsAt : null,
                            ':expires_at' => $expiresAt !== '' ? $expiresAt : null,
                            ':max_uses' => $maxUses,
                            ':used_count' => $usedCount,
                            ':is_active' => $isActive,
                        ]);
                        $success = 'Cupom atualizado com sucesso.';
                        $editingId = $id;
                    } else {
                        $st = $pdo->prepare(
                            'INSERT INTO coupons
                                (code, description, discount_type, discount_value, min_order_value, starts_at, ends_at, expires_at, max_uses, used_count, is_active, created_at, updated_at)
                             VALUES
                                (:code, :description, :discount_type, :discount_value, :min_order_value, :starts_at, :ends_at, :expires_at, :max_uses, :used_count, :is_active, NOW(), NOW())'
                        );
                        $st->execute([
                            ':code' => $code,
                            ':description' => $description !== '' ? $description : null,
                            ':discount_type' => $discountType,
                            ':discount_value' => $discountValue,
                            ':min_order_value' => $minOrderValue,
                            ':starts_at' => $startsAt !== '' ? $startsAt : null,
                            ':ends_at' => $endsAt !== '' ? $endsAt : null,
                            ':expires_at' => $expiresAt !== '' ? $expiresAt : null,
                            ':max_uses' => $maxUses,
                            ':used_count' => $usedCount,
                            ':is_active' => $isActive,
                        ]);
                        $editingId = (int)$pdo->lastInsertId();
                        $success = 'Cupom criado com sucesso.';
                    }

                    $st = $pdo->prepare('SELECT * FROM coupons WHERE id = :id LIMIT 1');
                    $st->execute([':id' => $editingId]);
                    $coupon = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $error = 'Falha ao salvar cupom: ' . $e->getMessage();
                }
            }
        }
    }
}

$rows = [];
if ($pdo instanceof PDO) {
    $sql = 'SELECT * FROM coupons';
    $params = [];
    if ($query !== '') {
        $sql .= ' WHERE code LIKE :q OR description LIKE :q';
        $params[':q'] = '%' . $query . '%';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($coupon === null && $editingId > 0 && $rows !== []) {
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) === $editingId) {
            $coupon = $row;
            break;
        }
    }
}

$form = $coupon ?: [
    'id' => 0,
    'code' => '',
    'description' => '',
    'discount_type' => 'percent',
    'discount_value' => 0,
    'min_order_value' => 0,
    'starts_at' => null,
    'ends_at' => null,
    'expires_at' => null,
    'max_uses' => 0,
    'used_count' => 0,
    'is_active' => 1,
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cupons - Admin</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: #1a1a2e; padding: 1rem; color: white; }
        .container { max-width: 1280px; margin: 0 auto; padding: 2rem; }
        .page-title { font-size: 2rem; margin-bottom: 1rem; color: #333; }
        .card { background: white; padding: 1.5rem; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
        input, select, textarea { width: 100%; padding: 0.8rem 0.9rem; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
        textarea { min-height: 92px; resize: vertical; }
        .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn { padding: 0.75rem 1rem; border: 0; border-radius: 8px; color: #fff; background: #667eea; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .btn-secondary { background: #475569; }
        .btn-danger { background: #dc2626; }
        .btn-success { background: #16a34a; }
        .msg { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .msg-error { background: #fee2e2; color: #991b1b; }
        .msg-success { background: #dcfce7; color: #166534; }
        .pill { display: inline-flex; align-items: center; padding: 0.3rem 0.7rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; }
        .pill.active { background: #dcfce7; color: #166534; }
        .pill.inactive { background: #fee2e2; color: #991b1b; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 0.9rem; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        .table th { background: #f8fafc; }
        .searchbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
        .searchbar input { flex: 1 1 300px; }
        .muted { color: #64748b; font-size: 0.95rem; }
        .small { font-size: 0.88rem; color: #64748b; }
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
    <div class="navbar">
        <div class="container" style="display:flex; justify-content:space-between; align-items:center;">
            <div>🛍️ ShopVivaliz Admin / Cupons</div>
            <a href="/admin/" style="color:white; text-decoration:none;">← Voltar</a>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">Cadastro de Cupons</h1>
        <p class="muted">Aqui ficam os cupons usados no checkout e nas rotinas do carrinho abandonado.</p>

        <?php if ($error !== ''): ?>
            <div class="msg msg-error"><?= ac_esc($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="msg msg-success"><?= ac_esc($success) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="get" class="searchbar">
                <input type="search" name="q" value="<?= ac_esc($query) ?>" placeholder="Buscar por código ou descrição" autocomplete="off">
                <button class="btn btn-secondary" type="submit">Buscar</button>
                <a class="btn btn-secondary" href="/admin/cupons.php">Limpar</a>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom:1rem;"><?= $editingId > 0 ? 'Editar cupom' : 'Novo cupom' ?></h2>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
                <input type="hidden" name="action" value="save">
                <?= sv_csrf_input('admin-cupons') ?>

                <div class="grid">
                    <div>
                        <label for="code">Código</label>
                        <input type="text" id="code" name="code" value="<?= ac_esc((string)($form['code'] ?? '')) ?>" required>
                    </div>
                    <div>
                        <label for="discount_type">Tipo de desconto</label>
                        <select id="discount_type" name="discount_type">
                            <option value="percent" <?= (($form['discount_type'] ?? '') === 'percent') ? 'selected' : '' ?>>Percentual</option>
                            <option value="fixed" <?= (($form['discount_type'] ?? '') === 'fixed') ? 'selected' : '' ?>>Valor fixo</option>
                            <option value="shipping" <?= (($form['discount_type'] ?? '') === 'shipping') ? 'selected' : '' ?>>Frete grátis</option>
                        </select>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="discount_value">Valor do desconto</label>
                        <input type="number" step="0.01" min="0" id="discount_value" name="discount_value" value="<?= ac_esc((string)($form['discount_value'] ?? 0)) ?>">
                    </div>
                    <div>
                        <label for="min_order_value">Pedido mínimo</label>
                        <input type="number" step="0.01" min="0" id="min_order_value" name="min_order_value" value="<?= ac_esc((string)($form['min_order_value'] ?? 0)) ?>">
                    </div>
                </div>

                <div class="grid-3">
                    <div>
                        <label for="starts_at">Início</label>
                        <input type="datetime-local" id="starts_at" name="starts_at" value="<?= ac_esc(ac_dt((string)($form['starts_at'] ?? ''))) ?>">
                    </div>
                    <div>
                        <label for="ends_at">Fim</label>
                        <input type="datetime-local" id="ends_at" name="ends_at" value="<?= ac_esc(ac_dt((string)($form['ends_at'] ?? ''))) ?>">
                    </div>
                    <div>
                        <label for="expires_at">Expira em</label>
                        <input type="datetime-local" id="expires_at" name="expires_at" value="<?= ac_esc(ac_dt((string)($form['expires_at'] ?? ''))) ?>">
                    </div>
                </div>

                <div class="grid-3">
                    <div>
                        <label for="max_uses">Uso máximo</label>
                        <input type="number" min="0" id="max_uses" name="max_uses" value="<?= ac_esc((string)($form['max_uses'] ?? 0)) ?>">
                    </div>
                    <div>
                        <label for="used_count">Usos realizados</label>
                        <input type="number" min="0" id="used_count" name="used_count" value="<?= ac_esc((string)($form['used_count'] ?? 0)) ?>">
                    </div>
                    <div style="display:flex; align-items:end;">
                        <label style="display:flex; align-items:center; gap:0.45rem; margin:0; font-weight:600;">
                            <input type="checkbox" name="is_active" <?= !empty($form['is_active']) ? 'checked' : '' ?>>
                            Ativo
                        </label>
                    </div>
                </div>

                <div style="margin-top:1rem;">
                    <label for="description">Descrição</label>
                    <textarea id="description" name="description"><?= ac_esc((string)($form['description'] ?? '')) ?></textarea>
                </div>

                <div class="actions" style="margin-top:1rem;">
                    <button type="submit" class="btn">Salvar cupom</button>
                    <?php if ($editingId > 0): ?>
                        <a href="/admin/cupons.php" class="btn btn-secondary">Novo cupom</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-bottom:1rem;">Cupons cadastrados (<?= count($rows) ?>)</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <th>Validade</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="6" class="small">Nenhum cupom cadastrado ainda.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><strong><?= ac_esc((string)($row['code'] ?? '')) ?></strong><br><span class="small"><?= ac_esc((string)($row['description'] ?? '')) ?></span></td>
                                <td><?= ac_esc((string)($row['discount_type'] ?? 'percent')) ?></td>
                                <td>
                                    <?php if (($row['discount_type'] ?? 'percent') === 'percent'): ?>
                                        <?= number_format((float)($row['discount_value'] ?? 0), 2, ',', '.') ?>%
                                    <?php elseif (($row['discount_type'] ?? '') === 'fixed'): ?>
                                        R$ <?= number_format((float)($row['discount_value'] ?? 0), 2, ',', '.') ?>
                                    <?php else: ?>
                                        Frete grátis
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?= ac_esc((string)($row['starts_at'] ?? '')) ?><br>
                                    <?= ac_esc((string)($row['ends_at'] ?? ($row['expires_at'] ?? ''))) ?>
                                </td>
                                <td><span class="pill <?= !empty($row['is_active']) ? 'active' : 'inactive' ?>"><?= !empty($row['is_active']) ? 'Ativo' : 'Inativo' ?></span></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn btn-secondary" href="/admin/cupons.php?id=<?= (int)($row['id'] ?? 0) ?>">Editar</a>
                                        <form method="post" style="display:inline;">
                                            <?= sv_csrf_input('admin-cupons') ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
                                            <button type="submit" class="btn <?= !empty($row['is_active']) ? 'btn-danger' : 'btn-success' ?>">
                                                <?= !empty($row['is_active']) ? 'Desativar' : 'Ativar' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
