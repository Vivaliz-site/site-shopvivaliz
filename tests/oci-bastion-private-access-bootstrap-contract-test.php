<?php
declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/oci-bastion-private-access-bootstrap.yml';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "OCI Bastion bootstrap workflow missing\n");
    exit(1);
}

$required = [
    "name: OCI Bastion Private Access Bootstrap",
    "runs-on: ubuntu-latest",
    "github.event.issue.title == '[private-access-bootstrap]'",
    "github.event.issue.body == 'action=install-rustdesk-and-agent-ssh'",
    "bastion session create-port-forwarding",
    "PRIVATE_SSH_TUNNELS=PASS",
    "< scripts/setup-rustdesk-remote.sh",
    "< scripts/setup-agent-ssh.sh",
    "AGENT_SSH_SITE_PROBE=PASS",
    "AGENT_SSH_BACKEND_PROBE=PASS",
    "PRIVATE_ACCESS_BOOTSTRAP=PASS",
    'if workers="$(pgrep -fc',
];
foreach ($required as $needle) {
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "missing OCI Bastion bootstrap contract: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    "runs-on: [self-hosted",
    "contents: write",
    "actions: write",
    "set +e",
    "|| true",
    "sudo -n env RUSTDESK_SERVER_KEY=\"$key\" RUSTDESK_ID_SERVER=10.0.1.38 bash -s -- client_install\n          REMOTE",
    "sudo -n env SHOPVIVALIZ_AGENT_SSH_PUBKEY=\"$pub\" bash -s -- install\n          REMOTE",
];
foreach ($forbidden as $needle) {
    if (strpos($text, $needle) !== false) {
        fwrite(STDERR, "forbidden OCI Bastion bootstrap pattern: {$needle}\n");
        exit(1);
    }
}

echo "oci-bastion-private-access-bootstrap-contract: ok\n";
