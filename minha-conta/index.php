<?php
declare(strict_types=1);

// Incluir configuração e helpers
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth-helpers.php';

// Requer login
require_login();

// Obter dados do usuário
$user = get_current_user();

// Se não conseguir obter usuário, faz logout
if (!$user) {
    logout_user();
}

// Obter pedidos do usuário
$orders = [];
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('
        SELECT id, order_number, total, status, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ');
    if ($stmt) {
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Exception $e) {
    error_log('Error fetching orders: ' . $e->getMessage());
}

// Processar atualização de perfil
$profile_message = '';
$profile_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $update_data = [];
        if (!empty($_POST['name'])) {
            $update_data['name'] = $_POST['name'];
        }
        if (!empty($_POST['phone'])) {
            $update_data['phone'] = $_POST['phone'];
        }
        if (!empty($_POST['cpf'])) {
            $update_data['cpf'] = $_POST['cpf'];
        }

        $result = update_user_profile((int)$user['id'], $update_data);
        if ($result['success']) {
            $profile_message = $result['message'];
            // Recarregar dados do usuário
            $user = get_current_user();
        } else {
            $profile_error = $result['message'];
        }
    } elseif ($action === 'logout') {
        logout_user();
    }
}

function format_money($value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function format_date($date): string
{
    $dt = new DateTime($date);
    return $dt->format('d/m/Y');
}

function format_status($status): string
{
    $statuses = [
        'pending' => ['label' => 'Pendente', 'class' => 'status-pending'],
        'processing' => ['label' => 'Processando', 'class' => 'status-processing'],
        'shipped' => ['label' => 'Enviado', 'class' => 'status-shipped'],
        'delivered' => ['label' => 'Entregue', 'class' => 'status-delivered'],
        'cancelled' => ['label' => 'Cancelado', 'class' => 'status-cancelled'],
    ];
    return $statuses[$status]['label'] ?? 'Desconhecido';
}

$svNavCurrent = 'minha-conta';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Minha Conta - ShopVivaliz">
    <title>Minha Conta - ShopVivaliz</title>

    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/assets/css/visual-improvements-v2.css">
    <link rel="stylesheet" href="/assets/css/auth-style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <main class="account-page">
        <div class="account-container">
            <header class="account-header">
                <h1>Minha Conta</h1>
                <p>Gerencie seu perfil e pedidos</p>
            </header>

            <div class="account-layout">
                <div class="account-main">
                    <!-- Informações do Perfil -->
                    <section class="account-card">
                        <h2>Informações Pessoais</h2>

                        <?php if (!empty($profile_message)): ?>
                            <div class="alert alert-success" role="alert">
                                <span class="alert-icon">✓</span>
                                <div><?php echo htmlspecialchars($profile_message, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($profile_error)): ?>
                            <div class="alert alert-error" role="alert">
                                <span class="alert-icon">⚠️</span>
                                <div><?php echo htmlspecialchars($profile_error, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="account-field">
                            <div class="account-field-label">Nome</div>
                            <div class="account-field-value"><?php echo htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>

                        <div class="account-field">
                            <div class="account-field-label">Email</div>
                            <div class="account-field-value"><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>

                        <div class="account-field">
                            <div class="account-field-label">Telefone</div>
                            <div class="account-field-value"><?php echo !empty($user['phone']) ? htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') : 'Não informado'; ?></div>
                        </div>

                        <div class="account-field">
                            <div class="account-field-label">CPF</div>
                            <div class="account-field-value"><?php echo !empty($user['cpf']) ? htmlspecialchars($user['cpf'], ENT_QUOTES, 'UTF-8') : 'Não informado'; ?></div>
                        </div>

                        <div class="account-field">
                            <div class="account-field-label">Membro desde</div>
                            <div class="account-field-value"><?php echo format_date($user['created_at']); ?></div>
                        </div>

                        <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #E2E8F0;">
                            <button type="button" class="account-edit-btn" id="editProfileBtn">
                                ✎ Editar Perfil
                            </button>
                        </div>
                    </section>

                    <!-- Formulário de Edição (Oculto) -->
                    <section class="account-card" id="editProfileSection" style="display: none;">
                        <h2>Editar Perfil</h2>

                        <form method="POST" class="auth-form" id="editProfileForm" novalidate>
                            <input type="hidden" name="action" value="update_profile">

                            <div class="form-group">
                                <label for="edit_name">Nome Completo</label>
                                <input
                                    type="text"
                                    id="edit_name"
                                    name="name"
                                    value="<?php echo htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Seu nome completo"
                                    minlength="3"
                                >
                                <span class="form-error" id="editNameError"></span>
                            </div>

                            <div class="form-group">
                                <label for="edit_phone">Telefone</label>
                                <input
                                    type="tel"
                                    id="edit_phone"
                                    name="phone"
                                    value="<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="(11) 99999-9999"
                                >
                                <span class="form-error" id="editPhoneError"></span>
                            </div>

                            <div class="form-group">
                                <label for="edit_cpf">CPF</label>
                                <input
                                    type="text"
                                    id="edit_cpf"
                                    name="cpf"
                                    value="<?php echo htmlspecialchars($user['cpf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="000.000.000-00"
                                    maxlength="14"
                                >
                                <span class="form-error" id="editCpfError"></span>
                                <span class="form-hint">Formato: 000.000.000-00</span>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 24px;">
                                <button type="submit" class="btn-auth btn-auth-primary">
                                    Salvar Alterações
                                </button>
                                <button type="button" id="cancelEditBtn" class="btn-auth btn-auth-secondary">
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- Pedidos -->
                    <section class="account-card">
                        <h2>Meus Pedidos</h2>

                        <?php if (empty($orders)): ?>
                            <p style="color: #64748B; text-align: center; padding: 24px 0;">
                                Você ainda não realizou nenhum pedido.
                                <a href="/catalogo" style="color: #173B63; font-weight: 600;">Explorar catálogo</a>
                            </p>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table style="width: 100%; font-size: 13px;">
                                    <thead>
                                        <tr style="border-bottom: 2px solid #E2E8F0;">
                                            <th style="text-align: left; padding: 12px 0; color: #334155; font-weight: 600;">Pedido</th>
                                            <th style="text-align: center; padding: 12px 0; color: #334155; font-weight: 600;">Data</th>
                                            <th style="text-align: right; padding: 12px 0; color: #334155; font-weight: 600;">Total</th>
                                            <th style="text-align: center; padding: 12px 0; color: #334155; font-weight: 600;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): ?>
                                            <tr style="border-bottom: 1px solid #E2E8F0;">
                                                <td style="padding: 12px 0; color: #0F172A; font-weight: 500;">
                                                    #<?php echo htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td style="padding: 12px 0; text-align: center; color: #475569;">
                                                    <?php echo format_date($order['created_at']); ?>
                                                </td>
                                                <td style="padding: 12px 0; text-align: right; color: #0F172A; font-weight: 500;">
                                                    <?php echo format_money($order['total']); ?>
                                                </td>
                                                <td style="padding: 12px 0; text-align: center;">
                                                    <span style="
                                                        display: inline-block;
                                                        padding: 4px 10px;
                                                        border-radius: 6px;
                                                        font-size: 11px;
                                                        font-weight: 600;
                                                        text-transform: uppercase;
                                                        background: <?php
                                                            echo $order['status'] === 'delivered' ? '#DCFCE7' :
                                                                ($order['status'] === 'shipped' ? '#E0E7FF' :
                                                                ($order['status'] === 'processing' ? '#FEF3C7' :
                                                                ($order['status'] === 'cancelled' ? '#FEE2E2' : '#F0F4F8')));
                                                        ?>;
                                                        color: <?php
                                                            echo $order['status'] === 'delivered' ? '#166534' :
                                                                ($order['status'] === 'shipped' ? '#3730A3' :
                                                                ($order['status'] === 'processing' ? '#92400E' :
                                                                ($order['status'] === 'cancelled' ? '#991B1B' : '#334155')));
                                                        ?>;
                                                    ">
                                                        <?php echo format_status($order['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <!-- Sidebar -->
                <aside class="account-sidebar">
                    <div class="sidebar-card">
                        <h3>Pedidos</h3>
                        <div class="stat-value"><?php echo count($orders); ?></div>
                        <p>Total de pedidos realizados</p>
                    </div>

                    <div class="sidebar-card">
                        <h3>Conta</h3>
                        <p style="margin-top: 16px; font-size: 12px; color: #475569;">
                            Membro desde <?php echo format_date($user['created_at']); ?>
                        </p>
                    </div>

                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="account-logout-btn" style="width: 100%;">
                            🚪 Sair da Conta
                        </button>
                    </form>
                </aside>
            </div>
        </div>
    </main>

    <script>
    (function () {
        const editProfileBtn = document.getElementById('editProfileBtn');
        const editProfileSection = document.getElementById('editProfileSection');
        const cancelEditBtn = document.getElementById('cancelEditBtn');
        const editProfileForm = document.getElementById('editProfileForm');
        const editNameInput = document.getElementById('edit_name');
        const editNameError = document.getElementById('editNameError');

        editProfileBtn.addEventListener('click', function () {
            editProfileSection.style.display = 'block';
            editProfileBtn.parentElement.parentElement.style.display = 'none';
            editNameInput.focus();
        });

        cancelEditBtn.addEventListener('click', function () {
            editProfileSection.style.display = 'none';
            editProfileBtn.parentElement.parentElement.style.display = 'block';
        });

        editProfileForm.addEventListener('submit', function (e) {
            const name = editNameInput.value.trim();
            if (name && name.length < 3) {
                e.preventDefault();
                editNameInput.parentElement.classList.add('error');
                editNameError.textContent = 'Mínimo 3 caracteres';
            }
        });

        editNameInput.addEventListener('input', function () {
            if (this.parentElement.classList.contains('error')) {
                this.parentElement.classList.remove('error');
                editNameError.textContent = '';
            }
        });
    })();
    </script>
</body>
</html>
