<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/account-chrome.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
require_once __DIR__ . '/../includes/csrf.php';

$svAccountUser = sv_account_require_login();
sv_account_ensure_schema();

$svAccountPageTitle = 'Endereços';
$svAccountActive = 'enderecos';

$addresses = [];
try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare('SELECT * FROM addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC');
    $stmt->execute([':uid' => $svAccountUser['id']]);
    $addresses = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[MinhaConta] enderecos query failed: ' . $e->getMessage());
}

require __DIR__ . '/../includes/account-chrome-top.php';
?>
<h1>Endereços</h1>
<p class="sv-subtitle">Cadastre e gerencie os endereços de entrega da sua conta.</p>

<div id="sv-address-list" style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;">
    <?php foreach ($addresses as $addr): ?>
        <div class="sv-address-card" data-id="<?php echo (int)$addr['id']; ?>" style="border:1px solid #eee; border-radius:8px; padding:16px; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <div style="font-weight:600;">
                    <?php echo htmlspecialchars($addr['label']); ?>
                    <?php if ($addr['is_default']): ?><span style="font-size:11px; background:#173b63; color:white; padding:2px 8px; border-radius:10px; margin-left:6px;">Padrão</span><?php endif; ?>
                </div>
                <div style="font-size:14px; color:#555; margin-top:4px;">
                    <?php echo htmlspecialchars($addr['street'] . ', ' . $addr['number'] . ($addr['complement'] ? ' - ' . $addr['complement'] : '')); ?><br>
                    <?php echo htmlspecialchars($addr['neighborhood'] . ' - ' . $addr['city'] . '/' . $addr['state']); ?> · CEP <?php echo htmlspecialchars($addr['cep']); ?>
                </div>
            </div>
            <button class="sv-btn danger sv-address-delete" data-id="<?php echo (int)$addr['id']; ?>">Excluir</button>
        </div>
    <?php endforeach; ?>
    <?php if (empty($addresses)): ?>
        <p style="color:#999;">Nenhum endereço cadastrado ainda.</p>
    <?php endif; ?>
</div>

<h2 style="font-size:18px; margin-bottom:12px;">Novo endereço</h2>
<form id="sv-address-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px; max-width:640px;">
    <div style="grid-column: 1 / -1;">
        <label style="font-size:13px; display:block; margin-bottom:4px;">Nome do endereço</label>
        <input type="text" name="label" placeholder="Ex: Casa, Trabalho" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">CEP</label>
        <input type="text" name="cep" id="sv-cep-input" required maxlength="9" placeholder="00000-000" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div id="sv-cep-status" style="grid-column: 1 / -1; font-size:12px; color:#666; min-height:16px;"></div>
    <div style="grid-column: 1 / -1;">
        <label style="font-size:13px; display:block; margin-bottom:4px;">Rua</label>
        <input type="text" name="street" id="sv-street-input" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Número</label>
        <input type="text" name="number" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Complemento</label>
        <input type="text" name="complement" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Bairro</label>
        <input type="text" name="neighborhood" id="sv-neighborhood-input" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">Cidade</label>
        <input type="text" name="city" id="sv-city-input" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
    </div>
    <div>
        <label style="font-size:13px; display:block; margin-bottom:4px;">UF</label>
        <input type="text" name="state" id="sv-state-input" required maxlength="2" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; text-transform:uppercase;">
    </div>
    <div style="grid-column: 1 / -1;">
        <label style="font-size:13px; display:flex; align-items:center; gap:6px;">
            <input type="checkbox" name="is_default"> Definir como endereço padrão
        </label>
    </div>
    <div style="grid-column: 1 / -1;">
        <button type="submit" class="sv-btn" id="sv-address-submit">Salvar endereço</button>
    </div>
</form>

<script>
(function () {
    var csrfToken = <?php echo json_encode(sv_csrf_token('account-actions')); ?>;
    var cepInput = document.getElementById('sv-cep-input');
    var cepStatus = document.getElementById('sv-cep-status');

    cepInput.addEventListener('blur', function () {
        var cep = cepInput.value.replace(/\D/g, '');
        if (cep.length !== 8) return;
        cepStatus.textContent = 'Buscando endereço...';
        fetch('https://viacep.com.br/ws/' + cep + '/json/')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    cepStatus.textContent = 'CEP não encontrado.';
                    return;
                }
                document.getElementById('sv-street-input').value = data.logradouro || '';
                document.getElementById('sv-neighborhood-input').value = data.bairro || '';
                document.getElementById('sv-city-input').value = data.localidade || '';
                document.getElementById('sv-state-input').value = data.uf || '';
                cepStatus.textContent = 'Endereço preenchido automaticamente.';
            })
            .catch(function () {
                cepStatus.textContent = 'Não foi possível buscar o CEP agora. Preencha manualmente.';
            });
    });

    document.getElementById('sv-address-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = e.target;
        var submitBtn = document.getElementById('sv-address-submit');
        var originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="sv-spinner"></span> Salvando...';

        var payload = {
            csrf_token: csrfToken,
            label: form.label.value,
            cep: form.cep.value,
            street: form.street.value,
            number: form.number.value,
            complement: form.complement.value,
            neighborhood: form.neighborhood.value,
            city: form.city.value,
            state: form.state.value,
            is_default: form.is_default.checked
        };

        fetch('/api/account/address-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            if (data.ok) {
                window.svToast('Endereço salvo!');
                setTimeout(function () { window.location.reload(); }, 700);
            } else {
                window.svToast(data.error || 'Não foi possível salvar o endereço.', true);
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            window.svToast('Erro de conexão. Tente novamente.', true);
        });
    });

    document.querySelectorAll('.sv-address-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Excluir este endereço?')) return;
            btn.disabled = true;
            fetch('/api/account/address-delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken, id: btn.dataset.id })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    window.svToast('Endereço excluído.');
                    btn.closest('.sv-address-card').remove();
                } else {
                    btn.disabled = false;
                    window.svToast(data.error || 'Não foi possível excluir.', true);
                }
            })
            .catch(function () {
                btn.disabled = false;
                window.svToast('Erro de conexão. Tente novamente.', true);
            });
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/account-chrome-bottom.php'; ?>
