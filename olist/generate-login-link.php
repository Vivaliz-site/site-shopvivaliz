<?php
/**
 * Gerar Link de Login OAuth Tiny/Olist (OFICIAL)
 * Baseado na documentação: https://api-docs.erp.olist.com/
 */

// Configurações do Aplicativo DEV do painel ERP
$clientId = "tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553";
$redirectUri = getenv('OLIST_REDIRECT_URI') ?: getenv('URL_REDIRCT_OLIST') ?: getenv('TINY_REDIRECT_URI') ?: "https://shopvivaliz.com.br/olist/callback.php";

// Endpoint oficial OAuth2 da API V3 do Tiny
$oauthEndpoint = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth";

// Dados enviados para gerar o link de autorização
$data = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
];

// Montar URL com query parameters (OAuth2 usa GET, não POST)
$loginUrl = $oauthEndpoint . '?' . http_build_query($data);

echo "═══════════════════════════════════════════════════════════\n";
echo "LINK DE LOGIN OAUTH TINY/OLIST (OFICIAL)\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "✅ Client ID: " . substr($clientId, 0, 30) . "...\n";
echo "✅ Redirect URI: $redirectUri\n";
echo "✅ Endpoint: $oauthEndpoint\n\n";

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  CLIQUE NESTE LINK:                                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "$loginUrl\n\n";

echo "Instruções:\n";
echo "1. Copie o link acima\n";
echo "2. Cole no navegador\n";
echo "3. Faça login com sua conta Olist ERP\n";
echo "4. Clique 'Autorizar'\n";
echo "5. Será redirecionado para: $redirectUri?code=XXXXX\n";
echo "6. O callback.php capturará o código e salvará os tokens\n\n";

// JSON para fácil cópia
echo json_encode([
    'login_url' => $loginUrl,
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'oauth_endpoint' => $oauthEndpoint,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
