<?php
/**
 * Conectar com Olist via OAuth
 * Redireciona para a tela de autorização da Olist
 *
 * Fluxo:
 * 1. Usuario clica no link
 * 2. Redireciona para login Olist
 * 3. Olist redireciona de volta para callback.php com o CODE
 * 4. callback.php troca o CODE por TOKEN
 * 5. sync-products.php usa o TOKEN para buscar 198 produtos
 */

// Credenciais
$client_id = getenv('OLIST_CLIENT_ID') ?: 'tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553';
$redirect_uri = 'https://dev.shopvivaliz.com.br/olist/callback.php';

// URL de autorização (endpoint oficial Olist/Tiny)
// Conforme documentacao: https://api-docs.erp.olist.com/llms.txt
$auth_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => 'openid'
]);

error_log("[Olist Connect] Redirecionando para: $auth_url");

// Redirecionar
header("Location: $auth_url");
exit;
?>
