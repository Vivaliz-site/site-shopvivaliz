<?php
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/solange-stable-tunnel.yml';
$script = $root . '/scripts/provision-solange-stable-tunnel.sh';
foreach ([$workflow, $script] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FALHOU: artefato ausente {$path}\n");
        exit(1);
    }
}
$yaml = file_get_contents($workflow);
$sh = file_get_contents($script);
foreach (['workflow_dispatch:', 'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_DNS_EDIT_TOKEN', 'ORACLE_VM_SSH_KEY', '144.22.157.209'] as $needle) {
    if (stripos($yaml, $needle) === false) {
        fwrite(STDERR, "FALHOU: workflow sem {$needle}\n");
        exit(1);
    }
}
foreach (['set -Eeuo pipefail', 'StrictHostKeyChecking=yes', 'Authorization: Bearer $token', 'solange-staging.shopvivaliz.com.br', 'cloudflare/cloudflared@sha256:0aa26e284f05e6c77ae375b8c9c11d9eb6a448fb7bcd8d40f31cb6176189eb38', '--restart unless-stopped', '--network host', 'http://127.0.0.1:3300', 'api/health', 'REMOTE_ROOT=$remote_root_env', 'root="${REMOTE_ROOT:?missing REMOTE_ROOT}"'] as $needle) {
    if (stripos($sh, $needle) === false) {
        fwrite(STDERR, "FALHOU: provisionador sem {$needle}\n");
        exit(1);
    }
}
foreach (['schedule:', "branches: [main]", 'StrictHostKeyChecking=no'] as $forbidden) {
    if (stripos($yaml, $forbidden) !== false) {
        fwrite(STDERR, "FALHOU: gatilho/padrao proibido {$forbidden}\n");
        exit(1);
    }
}
foreach (['trycloudflare.com', '|| true', 'cloudflare/cloudflared:latest'] as $forbidden) {
    if (stripos($sh, $forbidden) !== false) {
        fwrite(STDERR, "FALHOU: provisionador contem padrao proibido {$forbidden}\n");
        exit(1);
    }
}
$publicSmoke = strpos($sh, 'curl --fail --silent --show-error --max-time 10 "$stable_url/"');
$reconcilerStop = strpos($sh, 'solange-demo-reconciler');
if ($publicSmoke === false || $reconcilerStop === false || $reconcilerStop < $publicSmoke) {
    fwrite(STDERR, "FALHOU: Quick Tunnel reconciler deve parar somente apos smoke publico\n");
    exit(1);
}
echo "solange-stable-tunnel-workflow-contract: ok\n";
