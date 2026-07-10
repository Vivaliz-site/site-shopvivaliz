<?php
declare(strict_types=1);

// Incluir configuração e helpers
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth-helpers.php';

// Inicializar variáveis
$name = '';
$email = '';
$password = '';
$password_confirm = '';
$error = '';
$success = '';

// Se já está logado, redireciona para conta
if (is_logged_in()) {
    header('Location: /minha-conta');
    exit;
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    $result = register_user($name, $email, $password, $password_confirm);

    if ($result['success']) {
        $success = $result['message'];
        // Redirecionar para conta após 2 segundos
        header('Refresh: 2; url=/minha-conta');
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Cadastre-se na ShopVivaliz">
    <title>Cadastro - ShopVivaliz</title>

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
                <h1>Criar Conta</h1>
                <p>Junte-se à comunidade ShopVivaliz</p>
            </header>

            <form method="POST" class="auth-form" id="registerForm" novalidate>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" role="alert">
                        <span class="alert-icon">⚠️</span>
                        <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success" role="alert">
                        <span class="alert-icon">✓</span>
                        <div><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="name">Nome Completo</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="Seu nome completo"
                        required
                        autocomplete="name"
                        minlength="3"
                    >
                    <span class="form-error" id="nameError"></span>
                    <span class="form-hint">Mínimo 3 caracteres</span>
                </div>

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
                        placeholder="Crie uma senha forte"
                        required
                        autocomplete="new-password"
                        minlength="8"
                    >
                    <span class="form-error" id="passwordError"></span>
                    <div class="password-strength" id="passwordStrength" style="display: none;">
                        <div class="strength-bar">
                            <div class="strength-bar-fill" id="strengthBarFill"></div>
                        </div>
                        <span class="strength-text" id="strengthText"></span>
                    </div>
                    <span class="form-hint">
                        Mínimo 8 caracteres, incluindo maiúsculas, minúsculas e números
                    </span>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirmar Senha</label>
                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        placeholder="Confirme sua senha"
                        required
                        autocomplete="new-password"
                        minlength="8"
                    >
                    <span class="form-error" id="passwordConfirmError"></span>
                </div>

                <button type="submit" class="btn-auth btn-auth-primary" id="submitBtn">
                    Cadastrar
                </button>

                <a href="/login" class="btn-auth btn-auth-secondary">
                    Já tenho uma conta
                </a>
            </form>

            <footer class="auth-footer">
                <p>Já tem uma conta? <a href="/login">Faça login aqui</a></p>
            </footer>
        </div>
    </main>

    <script>
    (function () {
        const form = document.getElementById('registerForm');
        const nameInput = document.getElementById('name');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const passwordConfirmInput = document.getElementById('password_confirm');
        const submitBtn = document.getElementById('submitBtn');

        const nameError = document.getElementById('nameError');
        const emailError = document.getElementById('emailError');
        const passwordError = document.getElementById('passwordError');
        const passwordConfirmError = document.getElementById('passwordConfirmError');

        const strengthBox = document.getElementById('passwordStrength');
        const strengthBarFill = document.getElementById('strengthBarFill');
        const strengthText = document.getElementById('strengthText');

        function validateEmail(value) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(String(value).toLowerCase());
        }

        function validatePassword(value) {
            if (value.length < 8) return false;
            if (!/[A-Z]/.test(value)) return false;
            if (!/[a-z]/.test(value)) return false;
            if (!/[0-9]/.test(value)) return false;
            return true;
        }

        function getPasswordStrength(value) {
            if (value.length < 8) return 'weak';
            let strength = 0;
            if (/[A-Z]/.test(value)) strength++;
            if (/[a-z]/.test(value)) strength++;
            if (/[0-9]/.test(value)) strength++;
            if (/[!@#$%^&*]/.test(value)) strength++;
            if (value.length >= 12) strength++;

            if (strength <= 2) return 'weak';
            if (strength <= 3) return 'medium';
            return 'strong';
        }

        function clearError(field, errorElement) {
            field.parentElement.classList.remove('error');
            errorElement.textContent = '';
        }

        function showError(field, errorElement, message) {
            field.parentElement.classList.add('error');
            errorElement.textContent = message;
        }

        nameInput.addEventListener('blur', function () {
            if (!this.value.trim()) {
                showError(this, nameError, 'Nome é obrigatório');
            } else if (this.value.trim().length < 3) {
                showError(this, nameError, 'Mínimo 3 caracteres');
            } else {
                clearError(this, nameError);
            }
        });

        nameInput.addEventListener('input', function () {
            if (this.parentElement.classList.contains('error')) {
                clearError(this, nameError);
            }
        });

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

        passwordInput.addEventListener('input', function () {
            const strength = getPasswordStrength(this.value);
            if (this.value) {
                strengthBox.style.display = 'block';
                strengthBarFill.className = 'strength-bar-fill ' + strength;
                strengthText.className = 'strength-text ' + strength;
                strengthText.textContent = strength === 'weak' ? 'Fraca' : strength === 'medium' ? 'Média' : 'Forte';
            } else {
                strengthBox.style.display = 'none';
            }

            if (this.parentElement.classList.contains('error')) {
                clearError(this, passwordError);
            }
        });

        passwordInput.addEventListener('blur', function () {
            if (!this.value) {
                showError(this, passwordError, 'Senha é obrigatória');
            } else if (!validatePassword(this.value)) {
                showError(this, passwordError, 'Senha fraca. Use maiúscula, minúscula e número');
            } else {
                clearError(this, passwordError);
            }
        });

        passwordConfirmInput.addEventListener('blur', function () {
            if (!this.value) {
                showError(this, passwordConfirmError, 'Confirme sua senha');
            } else if (this.value !== passwordInput.value) {
                showError(this, passwordConfirmError, 'Senhas não correspondem');
            } else {
                clearError(this, passwordConfirmError);
            }
        });

        passwordConfirmInput.addEventListener('input', function () {
            if (this.parentElement.classList.contains('error')) {
                clearError(this, passwordConfirmError);
            }
        });

        form.addEventListener('submit', function (e) {
            let isValid = true;

            if (!nameInput.value.trim()) {
                showError(nameInput, nameError, 'Nome é obrigatório');
                isValid = false;
            } else if (nameInput.value.trim().length < 3) {
                showError(nameInput, nameError, 'Mínimo 3 caracteres');
                isValid = false;
            }

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
            } else if (!validatePassword(passwordInput.value)) {
                showError(passwordInput, passwordError, 'Senha fraca. Use maiúscula, minúscula e número');
                isValid = false;
            }

            if (!passwordConfirmInput.value) {
                showError(passwordConfirmInput, passwordConfirmError, 'Confirme sua senha');
                isValid = false;
            } else if (passwordConfirmInput.value !== passwordInput.value) {
                showError(passwordConfirmInput, passwordConfirmError, 'Senhas não correspondem');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
            } else {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Cadastrando...';
            }
        });
    })();
    </script>
</body>
</html>
