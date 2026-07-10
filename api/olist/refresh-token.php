<?php
/**
 * Força renovação de token Olist/Tiny
 */

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load env
$env_file = __DIR__ . '/../../.env';
if (is_file($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim(trim($v), '"\''));
    }
}

$clientId = getenv('OLIST_CLIENT_ID');
$clientSecret = getenv('OLIST_CLIENT_SECRET');
$refreshToken = getenv('OLIST_REFRESH_TOKEN');

if (!$clientId || !$clientSecret || !$refreshToken) {
    die("Missing Olist credentials in .env\n");
}

echo "Refreshing Olist token...\n";

$ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

echo "HTTP $httpCode\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if (isset($data['access_token'])) {
        // Save tokens
        $envContent = file_get_contents($env_file);
        $envContent = preg_replace(
            '/^OLIST_ACCESS_TOKEN=.*/m',
            'OLIST_ACCESS_TOKEN=' . $data['access_token'],
            $envContent
        );
        $envContent = preg_replace(
            '/^OLIST_REFRESH_TOKEN=.*/m',
            'OLIST_REFRESH_TOKEN=' . $data['refresh_token'],
            $envContent
        );
        file_put_contents($env_file, $envContent);
        echo "✓ Tokens refreshed and saved\n";
    }
} else {
    echo "✗ Token refresh failed\n";
    echo $response . "\n";
}
?>
