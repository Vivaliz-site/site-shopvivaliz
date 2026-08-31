<?php
$root = dirname(__DIR__);
$activePath = $root . '/.github/workflows/vm-desktop-commander-action.yml';
$legacyActivePath = $root . '/.github/workflows/vm-desktop-commander-secure-recovery.yml';
$legacyDisabledPath = $legacyActivePath . '.disabled';
$controlPath = $root . '/.github/workflows/desktop-commander-three-host-control-plane.yml';

if (!is_file($activePath)) { fwrite(STDERR, "dc-nondisruptive: active VM action workflow missing\n"); exit(1); }
if (is_file($legacyActivePath)) { fwrite(STDERR, "dc-nondisruptive: retired secure-recovery workflow must not be active\n"); exit(1); }
if (!is_file($legacyDisabledPath)) { fwrite(STDERR, "dc-nondisruptive: retired secure-recovery evidence missing\n"); exit(1); }
if (!is_file($controlPath)) { fwrite(STDERR, "dc-nondisruptive: read-only control plane missing\n"); exit(1); }

$active = (string) file_get_contents($activePath);
$control = (string) file_get_contents($controlPath);
foreach (['recover_session)', 'install_or_repair)', 'Action not allowlisted', 'device.json', 'auth-required.cooldown', '-nt', '$GITHUB_SHA', 'StrictHostKeyChecking=yes'] as $needle) {
    if (strpos($active, $needle) === false) {
        fwrite(STDERR, "dc-nondisruptive: active recovery missing {$needle}\n");
        exit(1);
    }
}
foreach (['git reset --hard', 'git clean -', 'git merge --ff-only', 'sudo kill -9'] as $forbidden) {
    if (stripos($active, $forbidden) !== false || stripos($control, $forbidden) !== false) {
        fwrite(STDERR, "dc-nondisruptive: broad/destructive mutation remains: {$forbidden}\n");
        exit(1);
    }
}
foreach (['diagnose:', 'Sanitized diagnostic only. No credentials', 'Stage sanitized status evidence'] as $needle) {
    if (strpos($control, $needle) === false) {
        fwrite(STDERR, "dc-nondisruptive: control plane read-only marker missing {$needle}\n");
        exit(1);
    }
}

echo "desktop-commander-nondisruptive-recovery: ok\n";
