<?php
declare(strict_types=1);

/**
 * One-time production bootstrap for an administrator account.
 * Credentials are supplied only through base64-encoded environment variables.
 * The plaintext password exists only in process memory and is hashed with bcrypt
 * before any database write.
 */
require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';

function sv_bootstrap_admin_decode(string $key): string
{
    $encoded = trim((string)getenv($key));
    if ($encoded === '') {
        throw new RuntimeException("Missing environment variable: {$key}");
    }
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        throw new RuntimeException("Invalid base64 value for: {$key}");
    }
    return $decoded;
}

$email = strtolower(trim(sv_bootstrap_admin_decode('ADMIN_EMAIL_B64')));
$name = trim(sv_bootstrap_admin_decode('ADMIN_NAME_B64'));
$password = sv_bootstrap_admin_decode('ADMIN_PASSWORD_B64');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Invalid administrator email.');
}
if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
    throw new RuntimeException('Invalid administrator name.');
}
if (strlen($password) < 20) {
    throw new RuntimeException('Administrator password must have at least 20 characters.');
}
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
if (!is_string($passwordHash) || $passwordHash === '') {
    throw new RuntimeException('Unable to hash administrator password.');
}
unset($password);

$db = sv_pdo();
if (!$db instanceof PDO) {
    throw new RuntimeException('Database unavailable.');
}

$db->beginTransaction();
try {
    $find = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
    $find->execute([$email]);
    $userId = (int)$find->fetchColumn();

    if ($userId > 0) {
        $update = $db->prepare(
            'UPDATE users SET name = ?, password_hash = ?, is_admin = 1, updated_at = NOW() WHERE id = ?'
        );
        $update->execute([$name, $passwordHash, $userId]);
        $operation = 'updated';
    } else {
        $insert = $db->prepare(
            'INSERT INTO users (name, email, password_hash, phone, cpf, is_admin, created_at, updated_at) '
            . "VALUES (?, ?, ?, '', NULL, 1, NOW(), NOW())"
        );
        $insert->execute([$name, $email, $passwordHash]);
        $userId = (int)$db->lastInsertId();
        $operation = 'created';
    }

    $verify = $db->prepare('SELECT id, email, is_admin, password_hash FROM users WHERE id = ? LIMIT 1');
    $verify->execute([$userId]);
    $row = $verify->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)
        || (string)$row['email'] !== $email
        || (int)$row['is_admin'] !== 1
        || !hash_equals($passwordHash, (string)$row['password_hash'])) {
        throw new RuntimeException('Administrator read-back verification failed.');
    }

    $db->commit();
    echo json_encode([
        'ok' => true,
        'operation' => $operation,
        'user_id' => $userId,
        'email' => $email,
        'is_admin' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
