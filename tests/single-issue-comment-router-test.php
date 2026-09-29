<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$owners = [];

foreach (array_merge(glob($workflowDir . '/*.yml') ?: [], glob($workflowDir . '/*.yaml') ?: []) as $path) {
    $text = (string) file_get_contents($path);
    if (preg_match('/^  issue_comment:\s*$/m', str_replace("\r\n", "\n", $text)) === 1) {
        $owners[] = basename($path);
    }
}
sort($owners);

$expected = ['comment-command-router.yml'];
if ($owners !== $expected) {
    fwrite(STDERR, 'issue_comment listeners must be exactly: ' . implode(',', $expected)
        . '; found: ' . implode(',', $owners) . "\n");
    exit(1);
}

$router = (string) file_get_contents($workflowDir . '/comment-command-router.yml');
$required = [
    '/refresh-backend-delete-repo-scope-v1 CONFIRM',
    '/delete-superseded-solange-v1 CONFIRM',
    '/continuity-e2e',
    '/provision-governed-backend-ci-runners-v1',
    '/mlrr',
    '/codex-auto-continuity-v7',
    '/refresh-a1-delete-repo-scope-v1 CONFIRM',
    '/codex-auto-continuity-v7-goal',
    '/remote',
    '/codex-remote-control-mcp-run',
    '/codex-comment-router-sweep-v1 CONFIRM',
    '/amazon-support-reply',
    '/amazon-support-readback',
    '/amazon-support-chat-reply',
    '/dc-reauth',
    '@claude',
    'refresh-backend-delete-repo-scope-once.yml',
    'delete-superseded-solange-once.yml',
    'task-continuity-production-e2e.yml',
    'provision-governed-backend-ci-runners.yml',
    'mlrr-production-ops-bridge.yml',
    'codex-auto-continuity-v7-launcher.yml',
    'refresh-a1-delete-repo-scope-once.yml',
    'codex-auto-continuity-v7-goal.yml',
    'shopvivaliz-remote-access.yml',
    'codex-remote-control-mcp-one-shot.yml',
    'codex-comment-router-sweep-one-shot.yml',
    'amazon-support-reply-oci-breakglass.yml',
    'desktop-commander-reauth-runner.yml',
];
foreach ($required as $needle) {
    if (!str_contains($router, $needle)) {
        fwrite(STDERR, "router missing required route token: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($router, "route == 'claude'") && !str_contains($router, 'route == "claude"')) {
    fwrite(STDERR, "router missing Claude route guard\n");
    exit(1);
}

echo "single-issue-comment-router: ok\n";
