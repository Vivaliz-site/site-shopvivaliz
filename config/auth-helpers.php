<?php
/**
 * Auth Helpers - ShopVivaliz
 * Funções reutilizáveis para autenticação, login e gerenciamento de sessão
 */

declare(strict_types=1);

// Inicializar sessão se não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verifica se usuário está logado
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id'] ?? null) && !empty($_SESSION['user_email'] ?? null);
}

/**
 * Obtém dados do usuário logado
 */
function get_current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT id, email, name, phone, cpf, created_at FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        return $user;
    } catch (Exception $e) {
        error_log('Error getting current user: ' . $e->getMessage());
        return null;
    }
}

/**
 * Requer login - redireciona se não está logado
 */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login?redirect=' . urlencode($_SESSION['redirect_after_login']));
        exit;
    }
}

/**
 * Faz hash de senha com password_hash
 */
function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verifica senha contra hash
 */
function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Validar email
 */
function is_valid_email(string $email): bool
{
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validar força de senha
 * Requer: mínimo 8 caracteres, pelo menos 1 maiúscula, 1 minúscula, 1 número
 */
function is_strong_password(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }
    return true;
}

/**
 * Autentica usuário por email e senha
 * Retorna array com sucesso e mensagem
 */
function authenticate_user(string $email, string $password): array
{
    $email = trim($email);
    $password = trim($password);

    if (empty($email) || empty($password)) {
        return [
            'success' => false,
            'message' => 'Email e senha são obrigatórios.',
            'code' => 'empty_fields'
        ];
    }

    if (!is_valid_email($email)) {
        return [
            'success' => false,
            'message' => 'Email inválido.',
            'code' => 'invalid_email'
        ];
    }

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT id, email, password_hash, name FROM users WHERE email = ? LIMIT 1');
        if (!$stmt) {
            throw new Exception('Erro na consulta do banco de dados');
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            // Usar delay para evitar timing attacks
            sleep(1);
            return [
                'success' => false,
                'message' => 'Email ou senha incorretos.',
                'code' => 'invalid_credentials'
            ];
        }

        if (!verify_password($password, $user['password_hash'])) {
            sleep(1);
            return [
                'success' => false,
                'message' => 'Email ou senha incorretos.',
                'code' => 'invalid_credentials'
            ];
        }

        // Login bem-sucedido
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['login_time'] = time();

        // Log da atividade
        log_activity((int)$user['id'], 'user_login', 'Usuário fez login');

        return [
            'success' => true,
            'message' => 'Login realizado com sucesso!',
            'code' => 'login_success',
            'user' => $user
        ];
    } catch (Exception $e) {
        error_log('Authentication error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao autenticar. Tente novamente mais tarde.',
            'code' => 'auth_error'
        ];
    }
}

/**
 * Registra novo usuário
 */
function register_user(string $name, string $email, string $password, string $password_confirm): array
{
    $name = trim($name);
    $email = trim($email);
    $password = trim($password);
    $password_confirm = trim($password_confirm);

    // Validações
    if (empty($name) || empty($email) || empty($password)) {
        return [
            'success' => false,
            'message' => 'Nome, email e senha são obrigatórios.',
            'code' => 'empty_fields'
        ];
    }

    if (strlen($name) < 3) {
        return [
            'success' => false,
            'message' => 'Nome deve ter pelo menos 3 caracteres.',
            'code' => 'invalid_name'
        ];
    }

    if (!is_valid_email($email)) {
        return [
            'success' => false,
            'message' => 'Email inválido.',
            'code' => 'invalid_email'
        ];
    }

    if (!is_strong_password($password)) {
        return [
            'success' => false,
            'message' => 'Senha deve ter: mínimo 8 caracteres, 1 maiúscula, 1 minúscula, 1 número.',
            'code' => 'weak_password'
        ];
    }

    if ($password !== $password_confirm) {
        return [
            'success' => false,
            'message' => 'Senhas não correspondem.',
            'code' => 'password_mismatch'
        ];
    }

    try {
        $db = Database::getInstance()->getConnection();

        // Verificar se email já existe
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        if (!$stmt) {
            throw new Exception('Erro na consulta do banco de dados');
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt->close();
            return [
                'success' => false,
                'message' => 'Este email já está cadastrado.',
                'code' => 'email_exists'
            ];
        }
        $stmt->close();

        // Criar novo usuário
        $password_hash = hash_password($password);
        $stmt = $db->prepare(
            'INSERT INTO users (email, password_hash, name, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
        );
        if (!$stmt) {
            throw new Exception('Erro ao preparar inserção');
        }

        $stmt->bind_param('sss', $email, $password_hash, $name);
        if (!$stmt->execute()) {
            throw new Exception('Erro ao inserir usuário: ' . $stmt->error);
        }

        $user_id = $db->insert_id;
        $stmt->close();

        // Auto-login após cadastro
        $_SESSION['user_id'] = (int)$user_id;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $name;
        $_SESSION['login_time'] = time();

        // Log da atividade
        log_activity((int)$user_id, 'user_register', 'Usuário se cadastrou');

        return [
            'success' => true,
            'message' => 'Cadastro realizado com sucesso! Bem-vindo!',
            'code' => 'register_success',
            'user_id' => $user_id
        ];
    } catch (Exception $e) {
        error_log('Registration error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao cadastrar. Tente novamente mais tarde.',
            'code' => 'register_error'
        ];
    }
}

/**
 * Faz logout do usuário
 */
function logout_user(): void
{
    if (is_logged_in()) {
        $user_id = $_SESSION['user_id'];
        log_activity($user_id, 'user_logout', 'Usuário fez logout');
    }

    session_destroy();
    header('Location: /');
    exit;
}

/**
 * Registra atividade do usuário
 */
function log_activity(int $user_id, string $action, string $details = ''): void
{
    try {
        $db = Database::getInstance()->getConnection();
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $db->prepare(
            'INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        if ($stmt) {
            $stmt->bind_param('isss', $user_id, $action, $details, $ip_address);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log('Error logging activity: ' . $e->getMessage());
    }
}

/**
 * Atualiza perfil do usuário
 */
function update_user_profile(int $user_id, array $data): array
{
    try {
        $db = Database::getInstance()->getConnection();

        $updates = [];
        $params = [];
        $types = '';

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (strlen($name) < 3) {
                return [
                    'success' => false,
                    'message' => 'Nome deve ter pelo menos 3 caracteres.'
                ];
            }
            $updates[] = 'name = ?';
            $params[] = $name;
            $types .= 's';
        }

        if (isset($data['phone'])) {
            $phone = trim($data['phone']);
            $updates[] = 'phone = ?';
            $params[] = $phone;
            $types .= 's';
        }

        if (isset($data['cpf'])) {
            $cpf = preg_replace('/\D/', '', $data['cpf']);
            if (strlen($cpf) !== 11) {
                return [
                    'success' => false,
                    'message' => 'CPF inválido.'
                ];
            }
            $updates[] = 'cpf = ?';
            $params[] = $cpf;
            $types .= 's';
        }

        if (empty($updates)) {
            return [
                'success' => false,
                'message' => 'Nenhum dado para atualizar.'
            ];
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $user_id;
        $types .= 'i';

        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Erro ao preparar atualização');
        }

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception('Erro ao atualizar: ' . $stmt->error);
        }

        $stmt->close();

        log_activity($user_id, 'profile_update', 'Perfil atualizado');

        return [
            'success' => true,
            'message' => 'Perfil atualizado com sucesso!'
        ];
    } catch (Exception $e) {
        error_log('Profile update error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao atualizar perfil. Tente novamente mais tarde.'
        ];
    }
}
