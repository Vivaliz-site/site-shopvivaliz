<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
require __DIR__ . '/includes/tiny-order-push.php';
$token = svtop_tiny_get_token();
$res = svtop_tiny_request('GET', '/pedidos/369429398', $token);
echo $res['body'] . "\n";
