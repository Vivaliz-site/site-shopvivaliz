<?php
declare(strict_types=1);

// Incluir configuração e helpers
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth-helpers.php';

// Inicializar variáveis
$email = '';
$password = '';
$error = '';
$success = '';
$redirect_url = '/minha-conta';

// Se já está logado, redireciona para conta
if (is_logged_in()) {
    header('Location: /minha-conta');
    exit;
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $result = authenticate_user($email, $password);

    if ($result['success']) {
        // Verificar se tem URL de redirecionamento
        $redirect_url = $_GET['redirect'] ?? $_SESSION['redirect_after_login'] ?? '/minha-conta';
        unset($_SESSION['redirect_after_login']);

        // Validar URL de redirecionamento para evitar open redirect
        if (strpos($redirect_url, 'http') === 0) {
            $redirect_url = '/minha-conta';
        }

        header('Location: ' . $redirect_url);
        exit;
    } else {
        $error = $result['message'];
    }
}

// Obter URL de redirecionamento se fornecida
$redirect_param = $_GET['redirect'] ?? '';
if (!empty($redirect_param) && strpos($redirect_param, 'http') !== 0) {
    $redirect_url = $redirect_param;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Faça login na sua conta ShopVivaliz">
    <title>Login - ShopVivaliz</title>

    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/assets/css/visual-improvements-v2.css">
    <link rel="stylesheet" href="/assets/css/auth-style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
</head>
<body class="auth-page">
    <nav class="navbar auth-page-navbar">
        <div class="container nav-inner">
            <a href="/" class="brand-link" aria-label="Ir para a home da Vivaliz">
                <img src="/images/logo-vivaliz.png" alt="Vivaliz" class="brand-logo-img" onerror="this.src='/images/logo-vivaliz-square.png'">
            </a>
        </div>
    </nav>

    <main class="auth-page-content">
        <div class="auth-container">
            <header class="auth-header">
                <h1>Entrar</h1>
                <p>Acesse sua conta ShopVivaliz</p>
            </header>

            <form method="POST" class="auth-form" id="loginForm" novalidate>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" role="alert">
                        <span class="alert-icon">⚠️</span>
                        <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="seu.email@exemplo.com"
                        required
                        autocomplete="email"
                    >
                    <span class="form-error" id="emailError"></span>
                </div>

                <div class="form-group">
                    <label for="password">Senha</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Sua senha"
                        required
                        autocomplete="current-password"
                    >
                    <span class="form-error" id="passwordError"></span>
                </div>

                <button type="submit" class="btn-auth btn-auth-primary" id="submitBtn">
                    Entrar
                </button>

                <a href="/cadastro" class="btn-auth btn-auth-secondary">
                    Criar Conta
                </a>
            </form>

            <footer class="auth-footer">
                <p>Não tem uma conta? <a href="/cadastro">Cadastre-se aqui</a></p>
            </footer>
        </div>
    </main>

    <script>
    (function () {
        const form = document.getElementById('loginForm');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const emailError = document.getElementById('emailError');
        const passwordError = document.getElementById('passwordError');
        const submitBtn = document.getElementById('submitBtn');

        function validateEmail(value) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(String(value).toLowerCase());
        }

        function clearError(field, errorElement) {
            field.classList.remove('error');
            errorElement.textContent = '';
        }

        function showError(field, errorElement, message) {
            field.parentElement.classList.add('error');
            errorElement.textContent = message;
        }

        emailInput.addEventListener('blur', function () {
            if (!this.value.trim()) {
                showError(this, emailError, 'Email é obrigatório');
            } else if (!validateEmail(this.value)) {
                showError(this, emailError, 'Email inválido');
            } else {
                clearError(this, emailError);
            }
        });

        emailInput.addEventListener('input', function () {
            if (this.parentElement.classList.contains('error')) {
                clearError(this, emailError);
            }
        });

        passwordInput.addEventListener('blur', function () {
            if (!this.value) {
                showError(this, passwordError, 'Senha é obrigatória');
            } else {
                clearError(this, passwordError);
            }
        });

        passwordInput.addEventListener('input', function () {
            if (this.parentElement.classList.contains('error')) {
                clearError(this, passwordError);
            }
        });

        form.addEventListener('submit', function (e) {
            let isValid = true;

            if (!emailInput.value.trim()) {
                showError(emailInput, emailError, 'Email é obrigatório');
                isValid = false;
            } else if (!validateEmail(emailInput.value)) {
                showError(emailInput, emailError, 'Email inválido');
                isValid = false;
            }

            if (!passwordInput.value) {
                showError(passwordInput, passwordError, 'Senha é obrigatória');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
            } else {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Entrando...';
            }
        });
    })();
    </script>
</body>
</html>
