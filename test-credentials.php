<?php
// Test credentials and token renewal
$env_file = '.env';
if (is_file($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim(trim($v), '"\''));
    }
}

echo "=== TESTE DE CREDENCIAIS E TOKEN ===\n\n";

echo "1. Verificando credenciais...\n";
$keys = ['OLIST_REFRESH_TOKEN', 'OLIST_CLIENT_ID', 'OLIST_CLIENT_SECRET'];
$all_present = true;
foreach ($keys as $k) {
  $v = getenv($k);
  $status = strlen($v) > 0 ? '✅' : '❌';
  echo "   $status $k: " . (strlen($v) > 40 ? substr($v, 0, 40) . '...' : $v) . "\n";
  if (strlen($v) === 0) $all_present = false;
}

if (!$all_present) {
    echo "\n❌ Faltam credenciais!\n";
    exit(1);
}

echo "\n2. Testando renovação de token...\n";
$TOKEN_URL = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
$refresh = getenv('OLIST_REFRESH_TOKEN');
$clientId = getenv('OLIST_CLIENT_ID');
$clientSecret = getenv('OLIST_CLIENT_SECRET');

$ch = curl_init($TOKEN_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refresh,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($status === 200) {
    echo "   ✅ Token renovado com sucesso (HTTP $status)\n";
    $json = json_decode($body, true);
    echo "   Access Token: " . substr($json['access_token'] ?? '', 0, 50) . "...\n";
    echo "\n✅ TUDO OK! Credenciais estão funcionando.\n";
} else {
    echo "   ❌ Erro ao renovar token (HTTP $status)\n";
    echo "   Response: " . substr($body, 0, 300) . "\n";
    exit(1);
}
?>
