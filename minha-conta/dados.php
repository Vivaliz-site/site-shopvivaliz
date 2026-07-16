<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/account-chrome.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/csrf.php';

$svAccountUser = sv_account_require_login();
$svAccountPageTitle = 'Meus Dados';
$svAccountActive = 'dados';

$profile = ['name' => $svAccountUser['name'], 'email' => $svAccountUser['email'], 'phone' => '', 'cpf' => ''];
try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare('SELECT name, email, phone, cpf FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $svAccountUser['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $profile = $row;
    }
} catch (Throwable $e) {
    error_log('[MinhaConta] dados query failed: ' . $e->getMessage());
}

require __DIR__ . '/../includes/account-chrome-top.php';
?>
<h1>Meus Dados</h1>
<p class="sv-subtitle">Atualize suas informações pessoais e senha de acesso.</p>

<form id="sv-profile-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; max-width:640px; margin-bottom:32px;">
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Nome</label>
        <input type="text" name="name" required value="<?php echo htmlspecialchars($profile['name'] ?? ''); ?>" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Telefone</label>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">CPF</label>
        <input type="text" name="cpf" value="<?php echo htmlspecialchars($profile['cpf'] ?? ''); ?>" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Email</label>
        <input type="email" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>" disabled style="width:100%; padding:10px; border:1px solid #eee; border-radius:6px; background:#f5f5f5; color:#999;">
    </div>
    <div style="grid-column: 1 / -1;">
        <button type="submit" class="sv-btn" id="sv-profile-submit">Salvar dados</button>
    </div>
</form>

<h2 style="font-size:18px; margin-bottom:12px;">Alterar senha</h2>
<form id="sv-password-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; max-width:640px;">
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Senha atual</label>
        <input type="password" name="current_password" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Nova senha</label>
        <input type="password" name="new_password" required minlength="8" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div style="grid-column: 1 / -1;">
        <button type="submit" class="sv-btn" id="sv-password-submit">Alterar senha</button>
    </div>
</form>

<script>
(function () {
    var csrfToken = <?php echo json_encode(sv_csrf_token('account-actions')); ?>;

    function submitJson(form, url, submitBtnId, extraPayload) {
        var submitBtn = document.getElementById(submitBtnId);
        var originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="sv-spinner"></span> Salvando...';

        var payload = Object.assign({ csrf_token: csrfToken }, extraPayload);
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            if (data.ok) {
                window.svToast('Salvo com sucesso!');
                if (form) form.reset();
            } else {
                window.svToast(data.error || 'Não foi possível salvar.', true);
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            window.svToast('Erro de conexão. Tente novamente.', true);
        });
    }

    document.getElementById('sv-profile-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = e.target;
        submitJson(null, '/api/account/profile-save.php', 'sv-profile-submit', {
            name: f.name.value,
            phone: f.phone.value,
            cpf: f.cpf.value
        });
    });

    document.getElementById('sv-password-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = e.target;
        submitJson(f, '/api/account/password-change.php', 'sv-password-submit', {
            current_password: f.current_password.value,
            new_password: f.new_password.value
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/account-chrome-bottom.php'; ?>
