<?php
declare(strict_types=1);

/**
 * scripts/create-admin-test-user.php
 *
 * Cria (de forma idempotente) um usuario admin de USO INTERNO, destinado
 * exclusivamente a automacao/testes de agentes (Claude, GPT, etc.) que
 * precisam acessar /admin/ sem depender do Fred logar manualmente toda vez.
 *
 * NAO E PARA USO POR CLIENTES NEM PARA SUBSTITUIR A CONTA REAL DO FRED.
 * Ver docs/admin-test-user.md para o proposito completo e o procedimento
 * de revogacao.
 *
 * SEGURANCA:
 * - So pode ser executado via linha de comando (php-cli) OU via HTTP com um
 *   token secreto correto em ?token=... (comparado com hash_equals, timing
 *   safe). Sem o token certo, HTTP retorna 403 e nao faz nada.
 * - Gera uma senha aleatoria forte (32 bytes -> base64), nunca reaproveita
 *   uma senha fixa/hardcoded.
 * - Se o usuario de teste ja existir (mesmo email), atualiza a senha (nao
 *   duplica linha) e ainda assim imprime a nova senha uma unica vez.
 * - A senha SO aparece na saida desta execucao (stdout/HTTP response) e no
 *   arquivo de doc gerado localmente por quem rodou -- nunca e gravada em
 *   log persistente, nem no banco (so o hash vai pro banco).
 * - Depois de usar, ideal apagar este arquivo do servidor (nao do repo, ja
 *   que se quiser rodar de novo/rotacionar, precisa dele) ou pelo menos
 *   trocar SV_ADMIN_BOOTSTRAP_TOKEN.
 *
 * USO:
 *   php scripts/create-admin-test-user.php
 *   -- ou --
 *   https://shopvivaliz.com.br/scripts/create-admin-test-user.php?token=<SV_ADMIN_BOOTSTRAP_TOKEN>
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    $expectedToken = getenv('SV_ADMIN_BOOTSTRAP_TOKEN') ?: ($_ENV['SV_ADMIN_BOOTSTRAP_TOKEN'] ?? '');
    $providedToken = (string)($_GET['token'] ?? '');
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Acesso negado.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

const SV_TEST_ADMIN_EMAIL = 'agente-teste-interno@shopvivaliz.com.br';
const SV_TEST_ADMIN_NAME  = 'Agente de Automacao (uso interno)';

function sv_generate_strong_password(): string
{
    // 32 bytes de entropia, url-safe, sem caracteres ambiguos.
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

try {
    $db = Database::getInstance()->getConnection();
    if (!$db instanceof mysqli) {
        throw new RuntimeException('Conexao com o banco indisponivel.');
    }

    $plainPassword = sv_generate_strong_password();
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    // Idempotente: verifica se ja existe pelo email.
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $emailParam);
    $emailParam = SV_TEST_ADMIN_EMAIL;
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (is_array($existing)) {
        $userId = (int)$existing['id'];
        $update = $db->prepare('UPDATE users SET password_hash = ?, is_admin = 1, name = ?, updated_at = NOW() WHERE id = ?');
        $name = SV_TEST_ADMIN_NAME;
        $update->bind_param('ssi', $passwordHash, $name, $userId);
        $update->execute();
        $update->close();
        $action = 'atualizado (senha rotacionada)';
    } else {
        $insert = $db->prepare('INSERT INTO users (name, email, password_hash, is_admin, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())');
        $name = SV_TEST_ADMIN_NAME;
        $email = SV_TEST_ADMIN_EMAIL;
        $insert->bind_param('sss', $name, $email, $passwordHash);
        $insert->execute();
        $userId = (int)$insert->insert_id;
        $insert->close();
        $action = 'criado';
    }

    $output = [
        'status' => 'ok',
        'action' => $action,
        'user_id' => $userId,
        'email' => SV_TEST_ADMIN_EMAIL,
        'password' => $plainPassword,
        'warning' => 'Guarde esta senha agora. Ela nao sera exibida novamente. Documente em docs/admin-test-user.md (fora do repo publico se aplicavel) e nunca em .env versionado.',
    ];

    if ($isCli) {
        echo "Usuario de teste {$action} com sucesso.\n";
        echo "  ID:     {$userId}\n";
        echo "  Email:  " . SV_TEST_ADMIN_EMAIL . "\n";
        echo "  Senha:  {$plainPassword}\n";
        echo "\nGuarde esta senha agora -- nao sera exibida novamente nesta execucao.\n";
    } else {
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
} catch (Throwable $e) {
    $message = 'Erro ao criar/atualizar usuario de teste: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $message]) . "\n";
}
