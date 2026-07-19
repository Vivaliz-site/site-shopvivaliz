<?php
/**
 * Constantes Globais - ShopVivaliz
 */

$runtimeSecretsFile = __DIR__ . '/runtime-secrets.php';
if (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile)) {
    $runtimeSecrets = require $runtimeSecretsFile;
    if (is_array($runtimeSecrets)) {
        foreach ($runtimeSecrets as $key => $value) {
            if (!is_string($key) || $key === '' || (getenv($key) !== false && trim((string)getenv($key)) !== '')) {
                continue;
            }
            $stringValue = is_scalar($value) ? (string)$value : '';
            putenv($key . '=' . $stringValue);
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
    }
}

// Producao (VM Oracle) usa .env real em vez de runtime-secrets.php
// (mecanismo antigo do deploy FTP do HostGator). Mesmo parser usado
// em varios pontos do projeto (includes/melhorenvio-oauth.php etc.).
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key === '' || (getenv($key) !== false && trim((string)getenv($key)) !== '')) {
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Ambiente
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development');
define('DEBUG_MODE', ENVIRONMENT === 'development');

// Caminhos
define('BASE_PATH', dirname(dirname(__FILE__)));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('LOGS_PATH', STORAGE_PATH . '/logs');
define('UPLOADS_PATH', STORAGE_PATH . '/uploads');

// URLs
if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') ?: 'https://shopvivaliz.com.br');
}
if (!defined('API_URL')) {
    define('API_URL', BASE_URL . '/api');
}
if (!defined('ADMIN_URL')) {
    define('ADMIN_URL', BASE_URL . '/admin');
}

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'shopvivaliz'));
define('DB_USER', getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root'));
define('DB_PASS', getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: ''));
define('DB_CHARSET', 'utf8mb4');

// APIs de IA
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: null);
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: null);
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: null);

// Segurança
define('SESSION_NAME', 'SHOPVIVALIZ_SESSION');
define('COOKIE_DOMAIN', '.shopvivaliz.com.br');
define('COOKIE_SECURE', true);
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Strict');

// Paginação
define('ITEMS_PER_PAGE', 20);
define('MAX_ITEMS_PER_PAGE', 100);

// Upload
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']);
define('UPLOAD_TIMEOUT', 300);

// Rate Limiting
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600); // 1 hora

// Timeouts
define('DB_TIMEOUT', 30);
define('API_TIMEOUT', 10);
define('EXECUTION_TIMEOUT', 300);

// Versão
define('APP_VERSION', '9.2.104');
define('DB_VERSION', '1.0');

// Agentes
define('AGENTS_ACTIVE', true);
define('AGENTS_CONCURRENT', 3);
define('AGENTS_TIMEOUT', 120);
define('AGENTS_RETRY_COUNT', 3);

// Logs
define('LOG_LEVEL', DEBUG_MODE ? 'DEBUG' : 'WARNING');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('LOG_MAX_FILES', 30);

// Cache
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hora
define('CACHE_DRIVER', 'file'); // file, redis, memcached

// Email
define('MAIL_HOST', getenv('SMTP_HOST') ?: getenv('EMAIL_SMTP_HOST') ?: getenv('MAIL_HOST') ?: 'smtp.titan.email');
define('MAIL_PORT', getenv('SMTP_PORT') ?: getenv('EMAIL_SMTP_PORT') ?: getenv('MAIL_PORT') ?: 465);
define('MAIL_USER', getenv('SMTP_USER') ?: getenv('EMAIL_USER') ?: getenv('MAIL_USER') ?: 'agentes@shopvivaliz.com.br');
define('MAIL_PASS', getenv('SMTP_PASS') ?: getenv('EMAIL_PASSWORD') ?: getenv('MAIL_PASS') ?: '');
define('MAIL_FROM', getenv('EMAIL_FROM') ?: 'ShopVivaliz <noreply@shopvivaliz.com.br>');
define('MAIL_REPLY_TO', getenv('EMAIL_REPLY_TO') ?: getenv('EMAIL_FROM') ?: 'support@shopvivaliz.com.br');

// Integrações
define('OLIST_ENABLED', true);
define('OLIST_API_URL', 'https://api.tiny.com.br');
define('SHOPEE_ENABLED', true);
define('SHOPEE_API_URL', 'https://partner.shopeemall.com/api');
define('MELHORENVIO_ENABLED', true);

// FTP Deploy
define('FTP_ENABLED', true);
define('FTP_HOST', getenv('FTP_SERVER') ?: '');
define('FTP_USER', getenv('FTP_USERNAME') ?: '');
define('FTP_PASS', getenv('FTP_PASSWORD') ?: getenv('FTP_PASS') ?: '');
define('FTP_PORT', getenv('FTP_PORT') ?: 21);
define('FTP_DIR', getenv('FTP_REMOTE_DIR') ?: '/');

// Frete
define('FREE_SHIPPING_THRESHOLD', (float)(getenv('FREE_SHIPPING_THRESHOLD') ?: 299));

// Features flags
define('FEATURE_CART_PERSISTENCE', true);
define('FEATURE_OAUTH_LOGIN', true);
define('FEATURE_WISHLIST', true);
define('FEATURE_LIVE_CHAT', true);
define('FEATURE_RECOMMENDATIONS', true);
define('FEATURE_DYNAMIC_PRICING', false);

// Segurança
define('CSRF_ENABLED', true);
define('XSS_PROTECTION', true);
define('SQL_INJECTION_PROTECTION', true);
define('RATE_LIMITING', true);

// Resposta JSON padrão
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Log de erro
function log_error($message, $context = []) {
    error_log(json_encode([
        'timestamp' => date('c'),
        'message' => $message,
        'context' => $context
    ]) . PHP_EOL, 3, LOGS_PATH . '/app.log');
}
