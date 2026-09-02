<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/secure-session.php';
require_once __DIR__ . '/../includes/social-auth.php';

$error = '';
$adsOauthSuccess = false;
$gmailOauthSuccess = false;
$cloudApiEnableSuccess = false;

function sv_google_signed_state_payload(string $state, string $prefix): ?array
{
    if (!str_starts_with($state, $prefix . '.')) return null;
    $parts = explode('.', $state, 3);
    if (count($parts) !== 3) return null;
    [, $payloadB64, $signature] = $parts;
    $expected = hash_hmac('sha256', $payloadB64, sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'));
    if (!hash_equals($expected, $signature)) return null;
    $padded = strtr($payloadB64, '-_', '+/');
    $rem = strlen($padded) % 4;
    if ($rem > 0) $padded .= str_repeat('=', 4 - $rem);
    $payload = json_decode((string)base64_decode($padded, true), true);
    if (!is_array($payload)) return null;
    $ts = (int)($payload['ts'] ?? 0);
    if ((int)($payload['v'] ?? 0) !== 1 || $ts < time() - 1800 || $ts > time() + 60) return null;
    return $payload;
}

function sv_google_ads_signed_state_job(string $state): ?string
{
    $payload = sv_google_signed_state_payload($state, 'gads1');
    if (!$payload) return null;
    $job = strtolower(trim((string)($payload['job'] ?? '')));
    return preg_match('/^[a-f0-9]{32}$/', $job) === 1 ? $job : null;
}

function sv_google_oauth_signed_state_purpose(string $state): string
{
    $payload = sv_google_signed_state_payload($state, 'gads1');
    $purpose = is_array($payload) ? strtolower(trim((string)($payload['purpose'] ?? 'google_ads'))) : 'google_ads';
    return in_array($purpose, ['google_ads', 'gmail_readonly'], true) ? $purpose : 'google_ads';
}

function sv_google_cloud_enable_project(string $state): ?string
{
    $payload = sv_google_signed_state_payload($state, 'gcloud1');
    if (!$payload || (string)($payload['action'] ?? '') !== 'enable_google_ads_api') return null;
    $project = trim((string)($payload['project'] ?? ''));
    return preg_match('/^[0-9]{6,20}$/', $project) === 1 ? $project : null;
}

function sv_google_ads_pending_dir(): string
{
    $dir = sys_get_temp_dir() . '/shopvivaliz-google-ads-refresh';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível preparar armazenamento temporário do token.');
    }
    @chmod($dir, 0700);
    return $dir;
}

function sv_google_ads_pending_token_path(string $job): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $job) !== 1) {
        throw new RuntimeException('Job de autorização inválido.');
    }
    return sv_google_ads_pending_dir() . '/' . $job . '.token';
}

function sv_google_ads_write_pending_refresh_token(string $job, string $refreshToken): void
{
    if ($refreshToken === '' || preg_match('/\s/', $refreshToken) === 1) {
        throw new RuntimeException('Refresh token inválido.');
    }
    $path = sv_google_ads_pending_token_path($job);
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, $refreshToken . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível armazenar o token temporário com segurança.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível publicar o token temporário com segurança.');
    }
    @chmod($path, 0600);
}

function sv_enable_google_service(string $accessToken, string $project, string $service): void
{
    $url = 'https://serviceusage.googleapis.com/v1/projects/' . rawurlencode($project) . '/services/' . rawurlencode($service) . ':enable';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = (string)curl_error($ch);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? '') : '';
        if ($message === '') $message = $curlError !== '' ? $curlError : 'HTTP ' . $status;
        throw new RuntimeException('Falha ao ativar ' . $service . ': ' . $message);
    }
}

try {
    if (!sv_social_google_is_configured()) throw new RuntimeException('Login com Google não está configurado neste ambiente.');

    $state = trim((string)($_GET['state'] ?? ''));
    $code = trim((string)($_GET['code'] ?? ''));
    $oauthError = trim((string)($_GET['error'] ?? ''));
    if ($oauthError !== '') throw new RuntimeException('Google retornou: ' . $oauthError);
    if ($state === '' || $code === '') throw new RuntimeException('Resposta inválida do Google.');

    $cloudProject = sv_google_cloud_enable_project($state);
    if (is_string($cloudProject) && $cloudProject !== '') {
        $tokenResponse = sv_social_http_post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
            'client_secret' => sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => sv_social_callback_url('google'),
            'grant_type' => 'authorization_code',
        ]);
        $tokenData = json_decode($tokenResponse['body'], true);
        if ($tokenResponse['status'] >= 400 || !is_array($tokenData) || empty($tokenData['access_token'])) {
            throw new RuntimeException('Falha ao obter autorização temporária do Google Cloud.');
        }
        $accessToken = (string)$tokenData['access_token'];
        // Bootstrap the control-plane service first, then enable Google Ads.
        sv_enable_google_service($accessToken, $cloudProject, 'serviceusage.googleapis.com');
        $enabled = false;
        $lastError = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            if ($attempt > 0) sleep(3);
            try {
                sv_enable_google_service($accessToken, $cloudProject, 'googleads.googleapis.com');
                $enabled = true;
                break;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }
        if (!$enabled) throw ($lastError ?: new RuntimeException('Não foi possível ativar Google Ads API.'));
        $cloudApiEnableSuccess = true;
    } else {
        $signedAdsJob = sv_google_ads_signed_state_job($state);
        $googleOauthPurpose = sv_google_oauth_signed_state_purpose($state);
        $adsRequest = $_SESSION['social_oauth']['google_ads'] ?? null;
        $sessionAdsJob = null;
        if (is_array($adsRequest)
            && isset($adsRequest['state'], $adsRequest['job'], $adsRequest['created_at'])
            && hash_equals((string)$adsRequest['state'], $state)
            && (int)$adsRequest['created_at'] >= (time() - 1800)
            && preg_match('/^[a-f0-9]{32}$/', (string)$adsRequest['job']) === 1) {
            $sessionAdsJob = (string)$adsRequest['job'];
            unset($_SESSION['social_oauth']['google_ads']);
        }

        $adsJob = $signedAdsJob ?: $sessionAdsJob;
        if (is_string($adsJob) && $adsJob !== '') {
            $tokenResponse = sv_social_http_post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
                'client_secret' => sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'),
                'redirect_uri' => sv_social_callback_url('google'),
                'grant_type' => 'authorization_code',
            ]);
            $tokenData = json_decode($tokenResponse['body'], true);
            if ($tokenResponse['status'] >= 400 || !is_array($tokenData) || empty($tokenData['access_token'])) throw new RuntimeException('Falha ao concluir autorização do Google Ads.');
            $refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
            if ($refreshToken === '') throw new RuntimeException('Google não retornou refresh token. Reautorize com consentimento.');
            sv_google_ads_write_pending_refresh_token($adsJob, $refreshToken);
            if ($googleOauthPurpose === 'gmail_readonly') {
                $gmailOauthSuccess = true;
            } else {
                $adsOauthSuccess = true;
            }
        } else {
            $request = sv_social_consume_request('google', $state);
            if (!$request) throw new RuntimeException('Sessão do login Google expirou. Tente novamente.');
            $tokenResponse = sv_social_http_post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
                'client_secret' => sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'),
                'redirect_uri' => sv_social_callback_url('google'),
                'grant_type' => 'authorization_code',
            ]);
            $tokenData = json_decode($tokenResponse['body'], true);
            if ($tokenResponse['status'] >= 400 || !is_array($tokenData) || empty($tokenData['access_token'])) throw new RuntimeException('Falha ao trocar código do Google por token.');
            $profileResponse = sv_social_http_get_json('https://openidconnect.googleapis.com/v1/userinfo', ['Authorization: Bearer ' . $tokenData['access_token']]);
            $profile = json_decode($profileResponse['body'], true);
            if ($profileResponse['status'] >= 400 || !is_array($profile) || empty($profile['sub'])) throw new RuntimeException('Falha ao obter perfil do Google.');
            $user = sv_social_upsert_user('google', [
                'provider_id' => (string)$profile['sub'], 'email' => (string)($profile['email'] ?? ''),
                'email_verified' => !empty($profile['email_verified']), 'name' => (string)($profile['name'] ?? ''),
                'avatar_url' => (string)($profile['picture'] ?? ''),
            ]);
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Não foi possível renovar a sessão autenticada.');
            }
            sv_social_login_user($user);
            $_SESSION['issued_at'] = time();
            if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
                throw new RuntimeException('Não foi possível persistir a sessão autenticada.');
            }
            header('Location: ' . sv_social_sanitize_redirect((string)($request['redirect'] ?? '/')));
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    log_error('Google OAuth callback failed', ['error' => $error]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Google OAuth - ShopVivaliz</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f8fb;color:#172033;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,.12);max-width:540px;width:100%;padding:32px}h1{font-size:24px;margin:0 0 12px}p{margin:0 0 18px;line-height:1.5;color:#475569}a{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600}</style></head><body><div class="card">
<?php if ($cloudApiEnableSuccess): ?><h1>Google Ads API ativada</h1><p>Service Usage e Google Ads API foram ativadas no projeto Google Cloud. Esta janela pode ser fechada.</p>
<?php elseif ($gmailOauthSuccess): ?><h1>Gmail autorizado</h1><p>A autorização de leitura do Gmail foi concluída. Esta janela pode ser fechada.</p>
<?php elseif ($adsOauthSuccess): ?><h1>Google Ads autorizado</h1><p>A autorização foi concluída. Esta janela pode ser fechada.</p>
<?php else: ?><h1>Não foi possível concluir a autorização do Google</h1><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><a href="/auth/login.php">Voltar</a><?php endif; ?>
</div></body></html>