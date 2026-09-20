<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'workflow' => $root . '/.github/workflows/backend-browser-host-control.yml',
    'install' => $root . '/scripts/install-backend-browser-host.sh',
    'action' => $root . '/scripts/backend-browser-host-action.sh',
    'session' => $root . '/ops/browser-host/browser-session.sh',
    'cleanup' => $root . '/ops/browser-host/browser-cleanup.sh',
    'service' => $root . '/ops/browser-host/shopvivaliz-browser-mfa.service',
    'timer' => $root . '/ops/browser-host/shopvivaliz-browser-cleanup.timer',
    'package' => $root . '/ops/browser-host/package.json',
];
foreach ($files as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "browser host {$label} missing\n");
        exit(1);
    }
}

$workflow = (string) file_get_contents($files['workflow']);
$install = (string) file_get_contents($files['install']);
$action = (string) file_get_contents($files['action']);
$session = (string) file_get_contents($files['session']);
$service = (string) file_get_contents($files['service']);
$package = json_decode((string) file_get_contents($files['package']), true, 512, JSON_THROW_ON_ERROR);

$requiredWorkflow = [
    'runs-on: ubuntu-latest',
    'environment: Production',
    "github.event.issue.number == 1586",
    "github.event.comment.user.login == 'fredmourao-ai'",
    "startsWith(github.event.comment.body, '/browser-host ')",
    'OCI_CLI_KEY_CONTENT',
    'SHOPVIVALIZ_VM_SSH_KEY',
    'StrictHostKeyChecking=yes',
    'create-port-forwarding',
    '--target-port 22',
    'BASTION_ALLOWLIST_RESTORED=PASS',
    "sudo -n bash '$remote/scripts/install-backend-browser-host.sh'",
    "sudo -n bash '$remote/scripts/backend-browser-host-action.sh'",
];
foreach ($requiredWorkflow as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "workflow contract missing: {$needle}\n");
        exit(1);
    }
}

$requiredSession = [
    '--remote-debugging-address=127.0.0.1',
    '--remote-debugging-port=9222',
    '-localhost',
    '127.0.0.1:6080',
    'BROWSER_SESSION_TTL_SECONDS',
    '(( TTL_SECONDS >= 300 && TTL_SECONDS <= 7200 ))',
    '"origin": origin',
    '"task": task',
    '"machine": "always-free-arm-1787907847-26"',
    '"browser_pid": int(pid)',
];
foreach ($requiredSession as $needle) {
    if (!str_contains($session, $needle)) {
        fwrite(STDERR, "session contract missing: {$needle}\n");
        exit(1);
    }
}

$requiredService = [
    'User=shopbrowser',
    'Group=shopbrowser',
    'RuntimeMaxSec=2h',
    'NoNewPrivileges=true',
    'ProtectSystem=strict',
    'ReadWritePaths=/var/lib/shopvivaliz-browser',
];
foreach ($requiredService as $needle) {
    if (!str_contains($service, $needle)) {
        fwrite(STDERR, "service hardening missing: {$needle}\n");
        exit(1);
    }
}

$requiredAction = [
    'tailscale serve --bg --yes --http=80 http://127.0.0.1:6080',
    'TAILSCALE_ACCESS_SCOPE=tailnet-only',
    'tailscale serve reset',
    '--accept-dns=false',
    '--accept-routes=false',
    '--ssh=false',
    'TAILSCALE_AUTH=INTERACTION_REQUIRED',
    'TAILSCALE_LOGIN_FILE=READY',
];
foreach ($requiredAction as $needle) {
    if (!str_contains($action, $needle)) {
        fwrite(STDERR, "action contract missing: {$needle}\n");
        exit(1);
    }
}

$requiredInstall = [
    'EXPECTED_HOST=always-free-arm-1787907847-26',
    '[[ "$EUID" -eq 0 ]]',
    'useradd --system --create-home',
    'PLAYWRIGHT_BROWSERS_PATH="$STATE/pw-browsers"',
    'systemctl enable --now shopvivaliz-browser-cleanup.timer',
];
foreach ($requiredInstall as $needle) {
    if (!str_contains($install, $needle)) {
        fwrite(STDERR, "install contract missing: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'tailscale funnel',
    '--remote-debugging-address=0.0.0.0',
    '0.0.0.0:6080',
    '0.0.0.0:5900',
    'StrictHostKeyChecking=no',
    'StrictHostKeyChecking=accept-new',
    'pull_request_target:',
    'workflow_call:',
    'repository_dispatch:',
];
foreach ($forbidden as $needle) {
    foreach (['workflow' => $workflow, 'install' => $install, 'action' => $action, 'session' => $session] as $label => $text) {
        if (str_contains($text, $needle)) {
            fwrite(STDERR, "forbidden browser host pattern in {$label}: {$needle}\n");
            exit(1);
        }
    }
}

if (($package['dependencies']['playwright'] ?? null) !== '1.63.0') {
    fwrite(STDERR, "Playwright must be exactly pinned to 1.63.0\n");
    exit(1);
}

echo "backend-browser-host-contract: ok\n";
