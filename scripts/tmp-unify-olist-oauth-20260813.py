from __future__ import annotations

from pathlib import Path
import re


def exact(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected one exact match, found {count}")
    p.write_text(text.replace(old, new), encoding="utf-8")


def regex(path: str, pattern: str, replacement: str) -> None:
    p = Path(path)
    text = p.read_text(encoding="utf-8")
    updated, count = re.subn(pattern, lambda _: replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f"{path}: expected one regex match, found {count}")
    p.write_text(updated, encoding="utf-8")


# Monitors are read-only. Only the daemon rotates Olist/Tiny refresh tokens.
exact(
    "api/agent/integrations-health.php",
    "$fix = !in_array(strtolower((string)($_GET['fix'] ?? '1')), ['0', 'false', 'no'], true);\n$result = svih_check_all($fix);",
    "// O monitor nunca rotaciona OAuth. O daemon e o unico escritor do token store.\n$result = svih_check_all(false);",
)
exact(
    "api/admin/integrations-status.php",
    "    // O clique em \"Atualizar agora\" executa de fato as rotinas seguras de\n    // renovacao. A leitura automatica apenas valida o estado atual.\n    $state = svih_check_all($refreshRequested);",
    "    // Atualizar agora apenas refaz as verificacoes. A rotacao OAuth pertence\n    // exclusivamente ao daemon shopvivaliz-token-renewer.\n    $state = svih_check_all(false);",
)
exact(
    ".github/workflows/integrations-hourly.yml",
    "$result = svih_check_all(true);",
    "$result = svih_check_all(false);",
)

# Tiny v3 runtime is a consumer only. It can retry after the daemon publishes a
# newer access token, but it never calls the OAuth token endpoint itself.
Path("includes/marketplace/TinyV3Runtime.php").write_text(
    r'''<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tiny-order-push.php';

/**
 * Tiny/Olist v3 runtime consumer.
 *
 * OAuth refresh-token rotation is exclusively owned by daemon-token-renewer.py.
 * This runtime reads the canonical rotating store and never exchanges a refresh
 * token, avoiding concurrent rotation and stale-refresh persistence.
 */
const SV_MARKET_TINY_REFRESH_MARGIN_SECONDS = 1800;

function sv_market_tiny_token_path(): string
{
    $configured = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        return dirname(__DIR__, 2) . '/storage/private/olist-tokens.json';
    }
    return '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}

/** @return array<string,mixed> */
function sv_market_tiny_token_store(): array
{
    $path = sv_market_tiny_token_path();
    clearstatcache(true, $path);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function sv_market_tiny_env(string $key): string
{
    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    if (isset($_ENV[$key]) && trim((string)$_ENV[$key]) !== '') {
        return trim((string)$_ENV[$key]);
    }
    if (function_exists('svtop_env')) {
        return trim((string)svtop_env($key));
    }
    return '';
}

function sv_market_tiny_access_token(): string
{
    $tokens = sv_market_tiny_token_store();
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN'] as $key) {
        $value = trim((string)($tokens[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN', 'TOKEN_API_OLIST'] as $key) {
        $value = sv_market_tiny_env($key);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function sv_market_tiny_jwt_expiry_epoch(string $token): int
{
    $parts = explode('.', $token);
    if (count($parts) < 2 || trim($parts[1]) === '') {
        return 0;
    }
    $segment = strtr($parts[1], '-_', '+/');
    $padding = strlen($segment) % 4;
    if ($padding !== 0) {
        $segment .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($segment, true);
    if (!is_string($decoded) || $decoded === '') {
        return 0;
    }
    $json = json_decode($decoded, true);
    $expiry = is_array($json) ? (int)($json['exp'] ?? 0) : 0;
    return $expiry > 0 ? $expiry : 0;
}

function sv_market_tiny_expiry_epoch(string $token): int
{
    $tokens = sv_market_tiny_token_store();
    $stored = trim((string)($tokens['OLIST_ACCESS_TOKEN'] ?? $tokens['TINY_ACCESS_TOKEN'] ?? ''));
    if ($stored !== '' && hash_equals($stored, $token)) {
        $epoch = (int)($tokens['expires_at_epoch'] ?? 0);
        if ($epoch > 0) {
            return $epoch;
        }
    }
    return sv_market_tiny_jwt_expiry_epoch($token);
}

function sv_market_tiny_token_requires_refresh(string $token, ?int $now = null): bool
{
    if ($token === '') {
        return true;
    }
    $expiry = sv_market_tiny_expiry_epoch($token);
    if ($expiry <= 0) {
        return false;
    }
    return ($expiry - ($now ?? time())) <= SV_MARKET_TINY_REFRESH_MARGIN_SECONDS;
}

function sv_market_tiny_ensure_access_token(): string
{
    $token = sv_market_tiny_access_token();
    if ($token === '') {
        throw new RuntimeException('Token Tiny/Olist indisponivel no token store canonico.');
    }
    $expiry = sv_market_tiny_expiry_epoch($token);
    if ($expiry > 0 && $expiry <= time()) {
        throw new RuntimeException('Token Tiny/Olist expirado; aguarde o renovador canonico ou reautorize em /olist/connect.php.');
    }
    return $token;
}

/**
 * @param array<string,mixed>|null $payload
 * @return array{status:int,body:string,json:array<string,mixed>}
 */
function sv_market_tiny_request_v3(string $method, string $path, ?array $payload = null): array
{
    $before = sv_market_tiny_ensure_access_token();
    $response = svtop_tiny_request($method, $path, $before, $payload);
    if ((int)$response['status'] !== 401) {
        return $response;
    }

    // The daemon may have published a new token between request and 401.
    $after = sv_market_tiny_access_token();
    if ($after !== '' && !hash_equals($before, $after)) {
        return svtop_tiny_request($method, $path, $after, $payload);
    }
    return $response;
}
''',
    encoding="utf-8",
)

# Order push can no longer mint an access token and accidentally discard a
# rotated refresh token. It reads the same canonical store as every consumer.
regex(
    "includes/tiny-order-push.php",
    r"function svtop_tiny_credentials_configured\(\): bool\n\{.*?\n\}\n\nfunction svtop_tiny_get_token\(\): string\n\{.*?\n\}\n\nfunction svtop_tiny_request",
    r'''function svtop_olist_token_store_path(): string
{
    $configured = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        return svtop_root() . '/storage/private/olist-tokens.json';
    }
    return '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}

/** @return array<string,mixed> */
function svtop_olist_token_store(): array
{
    $path = svtop_olist_token_store_path();
    clearstatcache(true, $path);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function svtop_tiny_credentials_configured(): bool
{
    return svtop_tiny_get_token() !== '';
}

function svtop_tiny_get_token(): string
{
    $store = svtop_olist_token_store();
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN'] as $key) {
        $value = trim((string)($store[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return svtop_env('OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN', 'TOKEN_API_OLIST');
}

function svtop_tiny_request''',
)

# Product sync consumes canonical tokens and does not rotate OAuth.
regex(
    "olist/sync-products.php",
    r"function svs_save_tokens\(string \$access, string \$refresh\): void \{.*?\n\}\n\n",
    r'''function svs_token_store_path(): string {
    $configured = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
    if (is_string($configured) && trim($configured) !== '') return trim($configured);
    if (PHP_OS_FAMILY === 'Windows') return svs_root() . '/storage/private/olist-tokens.json';
    return '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}

function svs_read_token_store(): array {
    $path = svs_token_store_path();
    clearstatcache(true, $path);
    if (!is_file($path) || !is_readable($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

''',
)
regex(
    "olist/sync-products.php",
    r"function svs_http_post\(string \$url, array \$fields, array \$extraHeaders = \[\]\): array \{.*?\n\}\n\n",
    "",
)
regex(
    "olist/sync-products.php",
    r"/\* ── OAuth: obter access_token via refresh ── \*/\nfunction svs_get_access_token\(\): string \{.*?\n\}\n\n",
    r'''/* ── OAuth: access token gerenciado exclusivamente pelo daemon ── */
function svs_get_access_token(): string {
    $store = svs_read_token_store();
    foreach (['OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN'] as $key) {
        $value = trim((string)($store[$key] ?? ''));
        if ($value !== '') return $value;
    }
    $token = svs_env('OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN', 'TOKEN_API_OLIST');
    if ($token === '') {
        throw new RuntimeException('access_token_missing: aguarde shopvivaliz-token-renewer ou reautorize em /olist/connect.php');
    }
    return $token;
}

''',
)

# Single cross-process lock for all canonical rotations.
daemon = Path("daemon-token-renewer.py")
text = daemon.read_text(encoding="utf-8")
if "import contextlib\n" not in text:
    text = text.replace("import argparse\n", "import argparse\nimport contextlib\n")
if "import fcntl" not in text:
    text = text.replace(
        "from typing import Any\n",
        "from typing import Any\n\ntry:\n    import fcntl\nexcept ImportError:  # pragma: no cover - Windows local development\n    fcntl = None\n",
    )
lock_fn = '''@contextlib.contextmanager
def oauth_rotation_lock():
    """Serialize production refresh-token rotation across processes."""
    ensure_token_store_parent()
    path = current_token_store_path().parent / "olist-token-rotation.lock"
    with path.open("a+", encoding="utf-8") as handle:
        if os.name != "nt":
            os.chmod(path, 0o660)
        if fcntl is not None:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            if fcntl is not None:
                fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


'''
if "def oauth_rotation_lock()" not in text:
    marker = "def _read_env() -> dict[str, str]:\n"
    if marker not in text:
        raise SystemExit("daemon lock insertion marker missing")
    text = text.replace(marker, lock_fn + marker)
pattern = r"def renew_once\(\) -> dict\[str, Any\] \| None:\n.*?\n\ndef check_and_renew"
replacement = '''def renew_once() -> dict[str, Any] | None:
    with oauth_rotation_lock():
        config = get_config()
        result = renew_token(config)
        access_token = result.get("access_token") if isinstance(result, dict) else None
        if not isinstance(access_token, str) or not access_token:
            return None
        refresh_token = result.get("refresh_token") or result.get("_sv_refresh_token_fallback")
        if not isinstance(refresh_token, str) or not refresh_token:
            return None
        credential_alias = str(result.get("_sv_credential_alias") or "")
        refresh_alias = str(result.get("_sv_refresh_alias") or "")

        # Canonical rotating store is authoritative. Persist it before the
        # compatibility .env so a successful provider rotation is never lost.
        update_token_store(access_token, refresh_token, result)
        try:
            update_env(access_token, refresh_token)
        except Exception as exc:
            print(f"[!] Token store atualizado; sincronizacao .env pendente: {type(exc).__name__}")

        if credential_alias and refresh_alias:
            print(
                f"[+] Credencial OAuth aceita: credential_alias={credential_alias} "
                f"refresh_alias={refresh_alias}"
            )
        print(f"[+] Token Olist renovado preventivamente em {datetime.now(timezone.utc).isoformat()}")
        return result


def check_and_renew'''
text, count = re.subn(pattern, lambda _: replacement, text, count=1, flags=re.S)
if count != 1:
    raise SystemExit(f"daemon renew_once replacement count={count}")
daemon.write_text(text, encoding="utf-8")

# Canonical authorization-code callback. It holds the same lock before the
# provider exchange and atomically publishes access+refresh+expiry metadata.
Path("olist/callback.php").write_text(
    r'''<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function svo_load_env(string $path): array
{
    $env = [];
    foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
    return $env;
}

function svo_json_exit(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit;
}

function svo_redirect_uri(array $env): string
{
    $candidate = trim((string)($env['OLIST_REDIRECT_URI'] ?? $env['URL_REDIRCT_OLIST'] ?? $env['TINY_REDIRECT_URI'] ?? ''));
    $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
    $path = (string)(parse_url($candidate, PHP_URL_PATH) ?: '');
    return $candidate !== '' && $host === 'shopvivaliz.com.br' && $path === '/olist/callback.php'
        ? $candidate
        : 'https://shopvivaliz.com.br/olist/callback.php';
}

$error = trim((string)($_GET['error'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
if ($error !== '') {
    svo_json_exit(400, ['erro' => 'Autorizacao negada', 'oauth_error' => preg_replace('/[^a-z0-9_\-]/i', '', $error)]);
}
if ($code === '') {
    svo_json_exit(400, ['erro' => 'Codigo nao recebido', 'acao' => 'Inicie a autorizacao novamente em /olist/connect.php.']);
}

$envFile = dirname(__DIR__) . '/.env';
$env = svo_load_env($envFile);
$clientId = trim((string)($env['OLIST_CLIENT_ID'] ?? $env['TINY_CLIENT_ID'] ?? $env['CLIENT_ID_API_OLIST'] ?? ''));
$clientSecret = trim((string)($env['OLIST_CLIENT_SECRET'] ?? $env['TINY_CLIENT_SECRET'] ?? $env['CLIENT_SECRET_OLIST'] ?? ''));
if (strlen($clientId) < 16 || strlen($clientSecret) < 16) {
    svo_json_exit(503, ['erro' => 'Credenciais do aplicativo Olist nao configuradas no runtime privado.']);
}

$tokenStorePath = getenv('SHOPVIVALIZ_OLIST_TOKEN_FILE');
if (!is_string($tokenStorePath) || trim($tokenStorePath) === '') {
    $tokenStorePath = PHP_OS_FAMILY === 'Windows'
        ? dirname(__DIR__) . '/storage/private/olist-tokens.json'
        : '/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json';
}
$tokenStorePath = trim($tokenStorePath);
$tokenStoreDir = dirname($tokenStorePath);
if (!is_dir($tokenStoreDir) || !is_writable($tokenStoreDir)) {
    svo_json_exit(503, ['erro' => 'Armazenamento privado OAuth indisponivel.']);
}

$rotationLock = @fopen($tokenStoreDir . '/olist-token-rotation.lock', 'c+');
if ($rotationLock === false || !flock($rotationLock, LOCK_EX)) {
    svo_json_exit(503, ['erro' => 'Rotacao OAuth ocupada; tente novamente.']);
}
register_shutdown_function(static function () use ($rotationLock): void {
    if (is_resource($rotationLock)) {
        @flock($rotationLock, LOCK_UN);
        @fclose($rotationLock);
    }
});

$postData = http_build_query([
    'grant_type' => 'authorization_code',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'redirect_uri' => svo_redirect_uri($env),
], '', '&', PHP_QUERY_RFC3986);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
    'content' => $postData,
    'timeout' => 30,
    'ignore_errors' => true,
]]);
$response = @file_get_contents('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token', false, $context);
$tokenData = is_string($response) ? json_decode($response, true) : null;
if (!is_array($tokenData) || !is_string($tokenData['access_token'] ?? null) || !is_string($tokenData['refresh_token'] ?? null)) {
    $oauthError = is_array($tokenData) ? (string)($tokenData['error'] ?? '') : '';
    svo_json_exit(401, ['erro' => 'Falha ao trocar codigo por token', 'oauth_error' => preg_replace('/[^a-z0-9_\-]/i', '', $oauthError)]);
}
$accessToken = trim((string)$tokenData['access_token']);
$refreshToken = trim((string)$tokenData['refresh_token']);
if ($accessToken === '' || $refreshToken === '') {
    svo_json_exit(502, ['erro' => 'Resposta OAuth incompleta.']);
}

$store = [];
if (is_file($tokenStorePath) && is_readable($tokenStorePath)) {
    $decoded = json_decode((string)file_get_contents($tokenStorePath), true);
    if (is_array($decoded)) $store = $decoded;
}
$expiresIn = max(0, (int)($tokenData['expires_in'] ?? 0));
$store['OLIST_ACCESS_TOKEN'] = $accessToken;
$store['TINY_ACCESS_TOKEN'] = $accessToken;
$store['OLIST_REFRESH_TOKEN'] = $refreshToken;
$store['TINY_REFRESH_TOKEN'] = $refreshToken;
$store['updated_at'] = gmdate('c');
if ($expiresIn > 0) {
    $expiresAt = time() + $expiresIn;
    $store['expires_in'] = $expiresIn;
    $store['expires_at_epoch'] = $expiresAt;
    $store['expires_at'] = gmdate('c', $expiresAt);
}
$payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tmp = tempnam($tokenStoreDir, '.olist-token-');
if (!is_string($payload) || $tmp === false || file_put_contents($tmp, $payload . PHP_EOL, LOCK_EX) === false) {
    if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
    svo_json_exit(500, ['erro' => 'Falhou a persistencia no armazenamento privado.']);
}
@chmod($tmp, 0660);
if (!@rename($tmp, $tokenStorePath)) {
    @unlink($tmp);
    svo_json_exit(500, ['erro' => 'Falhou a publicacao atomica do token.']);
}
@chmod($tokenStorePath, 0660);

// Compatibility sync is best-effort; the rotating store above is authoritative.
$envSynced = false;
$envTarget = realpath($envFile);
if (is_string($envTarget) && $envTarget !== '' && is_file($envTarget) && is_readable($envTarget) && is_writable($envTarget)) {
    $content = (string)file_get_contents($envTarget);
    foreach ([
        'OLIST_ACCESS_TOKEN' => $accessToken,
        'OLIST_REFRESH_TOKEN' => $refreshToken,
        'TINY_ACCESS_TOKEN' => $accessToken,
        'TINY_REFRESH_TOKEN' => $refreshToken,
        'TOKEN_API_OLIST' => $accessToken,
    ] as $key => $value) {
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        $content = preg_match($pattern, $content)
            ? (string)preg_replace($pattern, $key . '=' . $value, $content, 1)
            : rtrim($content) . PHP_EOL . $key . '=' . $value . PHP_EOL;
    }
    $envTmp = tempnam(dirname($envTarget), '.olist-env-');
    if ($envTmp !== false && file_put_contents($envTmp, rtrim($content) . PHP_EOL, LOCK_EX) !== false) {
        $mode = fileperms($envTarget) & 0777;
        @chmod($envTmp, $mode ?: 0640);
        $envSynced = @rename($envTmp, $envTarget);
    }
    if (is_string($envTmp) && is_file($envTmp)) @unlink($envTmp);
}

svo_json_exit(200, [
    'sucesso' => true,
    'mensagem' => 'OAuth Olist ativado e salvo com sucesso.',
    'token_salvo' => true,
    'token_store_sincronizado' => true,
    'env_sincronizado' => $envSynced,
    'refresh_preventivo' => true,
    'refresh_margin_seconds' => 1800,
]);
''',
    encoding="utf-8",
)

# Remove duplicate executable OAuth paths. Static client configuration remains;
# only token exchange/rotation is unified.
delete_paths = [
    "api/olist/login.php",
    "api/olist/callback.php",
    "api/olist/refresh-token.php",
    ".github/workflows/one-time-olist-refresh.yml",
    ".github/workflows/repair-erp-runtime-manual.yml",
    ".github/workflows/recover-olist-runtime.yml",
    ".github/workflows/recover-olist-oauth-from-backups.yml",
    "scripts/recover-olist-oauth-from-backups.py",
    "tests/test_recover_olist_oauth_from_backups.py",
    "tests/olist-refresh-safety-test.php",
    "scripts/get-tiny-oauth-token.py",
    "scripts/playwright-get-token.py",
    "gen-token.php",
    "gen-token-local.py",
    "scripts/exchange-oauth-code.py",
    "olist/token-refresh.php",
    "claude/api/olist/token-refresh.php",
    "scripts/olist-direct-login.py",
    "olist/generate-token-direct.php",
    "olist/generate-refresh-token.php",
    "oauth-auto-exec-v2.py",
    "oauth-playwright-auto.py",
    "olist/cli-generate-token.php",
    "olist/setup-oauth.php",
    "olist/complete-oauth-flow.php",
    "scripts/auto-complete-olist.py",
    "olist/oauth-flow-official.php",
    "test-token-refresh.py",
]
for name in delete_paths:
    p = Path(name)
    if p.exists():
        p.unlink()

# Manual workflow only orchestrates the canonical daemon.
Path(".github/workflows/refresh-olist-token-2h.yml").write_text(
    '''name: Olist OAuth Runtime

on:
  pull_request:
    paths:
      - 'daemon-token-renewer.py'
      - 'olist/callback.php'
      - 'includes/marketplace/TinyV3Runtime.php'
      - 'includes/tiny-order-push.php'
      - 'olist/sync-products.php'
      - '.github/workflows/refresh-olist-token-2h.yml'
  push:
    branches: [main]
    paths:
      - 'daemon-token-renewer.py'
      - 'olist/callback.php'
      - 'includes/marketplace/TinyV3Runtime.php'
      - 'includes/tiny-order-push.php'
      - 'olist/sync-products.php'
      - '.github/workflows/refresh-olist-token-2h.yml'
  workflow_dispatch:
    inputs:
      confirmation:
        description: 'Digite REFRESH_OLIST_TOKEN para executar o renovador canonico'
        required: true
        type: string

permissions:
  contents: read

concurrency:
  group: olist-oauth-runtime
  cancel-in-progress: false

jobs:
  safety:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - uses: actions/checkout@v6
        with:
          persist-credentials: false
      - name: Validate the single refresh engine
        shell: bash
        run: |
          set -Eeuo pipefail
          python3 -m py_compile daemon-token-renewer.py
          php -l olist/callback.php
          php -l includes/marketplace/TinyV3Runtime.php
          php -l includes/tiny-order-push.php
          php -l olist/sync-products.php
          python3 - <<'PY'
          from pathlib import Path
          endpoint = 'accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token'
          for name in ['includes/marketplace/TinyV3Runtime.php','includes/tiny-order-push.php','olist/sync-products.php']:
              assert endpoint not in Path(name).read_text(encoding='utf-8'), name
          assert endpoint in Path('daemon-token-renewer.py').read_text(encoding='utf-8')
          assert endpoint in Path('olist/callback.php').read_text(encoding='utf-8')
          assert 'olist-token-rotation.lock' in Path('daemon-token-renewer.py').read_text(encoding='utf-8')
          assert 'olist-token-rotation.lock' in Path('olist/callback.php').read_text(encoding='utf-8')
          print('olist_oauth_single_writer_contract=ok')
          PY

  refresh:
    if: ${{ github.event_name == 'workflow_dispatch' }}
    needs: safety
    runs-on: ubuntu-latest
    timeout-minutes: 8
    environment: production
    steps:
      - name: Require explicit confirmation
        shell: bash
        env:
          CONFIRMATION: ${{ inputs.confirmation }}
        run: test "$CONFIRMATION" = REFRESH_OLIST_TOKEN
      - name: Configure verified SSH
        shell: bash
        env:
          PRIMARY_KEY: ${{ secrets.SHOPVIVALIZ_VM_SSH_KEY }}
          LEGACY_KEY: ${{ secrets.ORACLE_VM_SSH_KEY }}
          PRIMARY_HOSTS: ${{ secrets.SHOPVIVALIZ_VM_KNOWN_HOSTS }}
          LEGACY_HOSTS: ${{ secrets.ORACLE_VM_KNOWN_HOSTS }}
        run: |
          set -Eeuo pipefail
          key="${PRIMARY_KEY:-${LEGACY_KEY:-}}"
          known_hosts="${PRIMARY_HOSTS:-${LEGACY_HOSTS:-}}"
          test -n "$key" && test -n "$known_hosts"
          install -m 700 -d "$HOME/.ssh"
          printf '%s\n' "$key" > "$HOME/.ssh/id_rsa"
          printf '%s\n' "$known_hosts" > "$HOME/.ssh/known_hosts"
          chmod 600 "$HOME/.ssh/id_rsa" "$HOME/.ssh/known_hosts"
      - name: Run canonical refresh and prove API access
        shell: bash
        run: |
          set -Eeuo pipefail
          ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -i "$HOME/.ssh/id_rsa" ubuntu@137.131.156.17 'bash -s' <<'REMOTE'
          set -Eeuo pipefail
          current=/home/ubuntu/shopvivaliz-deploy/current
          shared=/home/ubuntu/shopvivaliz-deploy/shared
          export SHOPVIVALIZ_ENV_PATH="$shared/.env"
          export SHOPVIVALIZ_OLIST_TOKEN_FILE="$shared/private/olist-tokens.json"
          cd "$current"
          python3 daemon-token-renewer.py --once
          python3 - <<'PY'
          import json, time, urllib.request
          from pathlib import Path
          p=Path('/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json')
          d=json.loads(p.read_text())
          token=d.get('OLIST_ACCESS_TOKEN') or d.get('TINY_ACCESS_TOKEN') or ''
          refresh=d.get('OLIST_REFRESH_TOKEN') or d.get('TINY_REFRESH_TOKEN') or ''
          exp=int(d.get('expires_at_epoch') or 0)
          assert token and refresh and exp > int(time.time())
          req=urllib.request.Request('https://api.tiny.com.br/public-api/v3/produtos?limit=1&offset=0', headers={'Authorization':'Bearer '+token,'Accept':'application/json'})
          with urllib.request.urlopen(req, timeout=20) as r:
              assert int(r.status) == 200
          print('olist_api_http=200')
          print('remaining_seconds='+str(exp-int(time.time())))
          PY
          systemctl is-active --quiet shopvivaliz-token-renewer.service
          systemctl is-enabled --quiet shopvivaliz-token-renewer.service
          REMOTE
      - name: Remove SSH material
        if: always()
        run: rm -f "$HOME/.ssh/id_rsa" "$HOME/.ssh/known_hosts"
''',
    encoding="utf-8",
)

# Quality gate stops validating deleted recovery implementations and adds the
# single-writer contract instead.
qg = Path(".github/workflows/quality-gate.yml")
q = qg.read_text(encoding="utf-8")
q = "\n".join(
    line for line in q.splitlines()
    if "scripts/recover-olist-oauth-from-backups.py" not in line
    and "tests/test_recover_olist_oauth_from_backups.py" not in line
) + "\n"
q = re.sub(
    r"\n      - name: Run Olist OAuth backup recovery safety tests\n        shell: bash\n        run: \|\n          set -Eeuo pipefail\n          python3 -m unittest tests/test_recover_olist_oauth_from_backups.py -v\n",
    "\n",
    q,
    count=1,
)
anchor = "      - name: Run catalog daemon image regression test\n"
contract = '''      - name: Enforce single Olist OAuth writer
        shell: bash
        run: |
          set -Eeuo pipefail
          python3 - <<'PY'
          from pathlib import Path
          endpoint='accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token'
          for name in ['includes/marketplace/TinyV3Runtime.php','includes/tiny-order-push.php','olist/sync-products.php']:
              assert endpoint not in Path(name).read_text(encoding='utf-8'), name
          assert endpoint in Path('daemon-token-renewer.py').read_text(encoding='utf-8')
          assert endpoint in Path('olist/callback.php').read_text(encoding='utf-8')
          for removed in ['api/olist/callback.php','api/olist/refresh-token.php','scripts/get-tiny-oauth-token.py','scripts/playwright-get-token.py']:
              assert not Path(removed).exists(), removed
          print('olist_oauth_single_writer_contract=ok')
          PY

'''
if anchor not in q:
    raise SystemExit("quality gate insertion anchor missing")
q = q.replace(anchor, contract + anchor, 1)
qg.write_text(q, encoding="utf-8")

Path("docs/TINY-TOKEN-RENEWAL-SETUP.md").write_text(
    '''# Olist/Tiny OAuth — fluxo canônico

## Fonte de verdade

- Autorização/reautorização humana: `/olist/connect.php` → `/olist/callback.php`.
- Rotação automática: `daemon-token-renewer.py` via `shopvivaliz-token-renewer.service`.
- Estado rotativo: `/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json`.
- `.env` é bootstrap/compatibilidade; o token store privado tem prioridade para access/refresh token.

## Regra de segurança

Nenhum consumidor de pedidos, catálogo, publicação ou monitoramento pode trocar `refresh_token` diretamente. Esses componentes apenas leem o access token publicado pelo renovador. Isso evita concorrência entre refreshes e perda do refresh token rotacionado pelo provedor.

## Renovação preventiva

O serviço verifica no máximo a cada 5 minutos e inicia a renovação 30 minutos antes do vencimento. Access token e refresh token retornados pelo provedor são persistidos atomicamente no storage privado antes da próxima utilização.

## Reautorização

Se o provedor revogar o grant ou o aplicativo, abra `/olist/connect.php`, autorize o aplicativo e deixe `/olist/callback.php` concluir. O callback nunca devolve material do token e grava o novo estado no mesmo token store usado pelo daemon.

## Recuperação manual

O workflow `Olist OAuth Runtime` apenas invoca `daemon-token-renewer.py --once` na VM e valida a API v3. Ele não contém uma segunda implementação de refresh.
''',
    encoding="utf-8",
)

doc = Path("docs/secret-usage-map.md")
lines = doc.read_text(encoding="utf-8").splitlines()
updated = []
for line in lines:
    if line.startswith("| Tiny / Olist |"):
        updated.append("| Tiny / Olist | `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET` | [olist/connect.php](../olist/connect.php), [olist/callback.php](../olist/callback.php) | bootstrap OAuth canônico |")
    elif line.startswith("| Tiny / Olist runtime |"):
        updated.append("| Tiny / Olist runtime | access/refresh tokens rotativos no storage privado | [daemon-token-renewer.py](../daemon-token-renewer.py), [deploy/systemd/shopvivaliz-token-renewer.service](../deploy/systemd/shopvivaliz-token-renewer.service), [includes/marketplace/TinyV3Runtime.php](../includes/marketplace/TinyV3Runtime.php) | daemon único escritor; consumidores somente leitura |")
    else:
        updated.append(line)
doc.write_text("\n".join(updated) + "\n", encoding="utf-8")

print("oauth_unification_patch=applied")
