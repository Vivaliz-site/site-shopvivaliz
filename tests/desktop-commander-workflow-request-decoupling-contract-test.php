<?php
$peer = file_get_contents(__DIR__ . '/../.github/workflows/desktop-commander-peer-repair.yml');
$vm = file_get_contents(__DIR__ . '/../.github/workflows/vm-desktop-commander-action.yml');
$checks = [
    [$peer, 'default: install_or_repair', 'peer manual dispatch must default to install_or_repair'],
    [$peer, 'action="${{ inputs.action }}"', 'peer manual dispatch must use explicit input'],
    [$peer, 'if [ "$GITHUB_EVENT_NAME" = "workflow_dispatch" ]; then', 'peer must branch on event source'],
    [$vm, 'default: status', 'vm manual dispatch must default to status'],
    [$vm, 'ACTION="${{ inputs.action }}"', 'vm manual dispatch must use explicit input'],
    [$vm, 'VM_ACTION_SKIPPED=unrelated_amazon_support_request', 'vm workflow must safely ignore Amazon support requests'],
];
foreach ($checks as [$src, $needle, $message]) {
    if (strpos($src, $needle) === false) {
        fwrite(STDERR, $message . ": missing {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-workflow-request-decoupling: ok\n";
