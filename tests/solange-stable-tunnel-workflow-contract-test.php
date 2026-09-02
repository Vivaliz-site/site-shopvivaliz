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
foreach (['set -Eeuo pipefail', 'StrictHostKeyChecking=yes', 'solange-staging.shopvivaliz.com.br', 'cloudflare/cloudflared:latest', '--restart unless-stopped', '--network host', 'http://127.0.0.1:3300', 'api/health'] as $needle) {
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
if (stripos($sh, 'trycloudflare.com') !== false) {
    fwrite(STDERR, "FALHOU: tunnel persistente nao pode depender de Quick Tunnel\n");
    exit(1);
}
echo "solange-stable-tunnel-workflow-contract: ok\n";
