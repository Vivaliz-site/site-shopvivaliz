<?php
declare(strict_types=1);

$root = (string)(getenv('SHOPVIVALIZ_TEST_ROOT') ?: dirname(__DIR__));
$wrapper = (string)@file_get_contents($root . '/admin/squad-chat.php');
$ui = (string)@file_get_contents($root . '/admin/squad-chat.html');
$api = (string)@file_get_contents($root . '/claude/api/agent/squad-chat.php');
$errors = [];

if ($wrapper === '' || $ui === '' || $api === '') {
    fwrite(STDERR, "Squad Chat sources unavailable\n");
    exit(1);
}

if (str_contains($wrapper, "sessionStorage.setItem('SQUAD_TOKEN'")) {
    $errors[] = 'admin wrapper must not inject SQUAD_TOKEN into browser storage';
}
if (str_contains($ui, 'X-Squad-Token') || str_contains($ui, "sessionStorage.getItem('SQUAD_TOKEN')")) {
    $errors[] = 'browser must not read or send SQUAD_TOKEN';
}
if (!str_contains($wrapper, 'HTTP_X_CSRF_TOKEN') || !str_contains($ui, 'X-CSRF-Token')) {
    $errors[] = 'admin proxy must enforce a session CSRF token';
}
if (!str_contains($ui, "const API_ENDPOINT = '/admin/squad-chat.php?api=1';")) {
    $errors[] = 'UI must call authenticated server-side proxy';
}
if (str_contains($ui, 'setTimeout(autonomousCycle')) {
    $errors[] = 'periodic paid-AI autonomous timer must not be available';
}
if (str_contains($ui, 'payload.attachment=currentAttachment')) {
    $errors[] = 'UI must not claim unsupported attachment delivery';
}
if (!str_contains($api, "'attachment_not_supported'")) {
    $errors[] = 'API must explicitly reject unsupported attachments';
}
if (!str_contains($api, "\$body['agents']")) {
    $errors[] = 'API must honor the UI agents array';
}
if (!str_contains($api, '$requestedAgents !== null && !is_array($requestedAgents)')) {
    $errors[] = 'malformed agents must fail closed instead of defaulting to all agents';
}
if (!str_contains($ui, 'setTimeout(r, 5200)')) {
    $errors[] = 'finite dialogue pacing must respect the 12 requests/minute rate limit';
}
if (!str_contains($ui, "typeof data.ok!=='boolean'")) {
    $errors[] = 'UI must reject invalid/redirected proxy responses';
}
if (!str_contains($api, 'flock($handle, LOCK_EX)')) {
    $errors[] = 'rate limit must lock the full read-modify-write cycle';
}
if (!str_contains($api, "'rate_limit_unavailable'")) {
    $errors[] = 'rate limit storage failure must fail closed';
}
foreach (["'ok'", "'partial'", "'successful_agents'", "'failed_agents'"] as $field) {
    if (!str_contains($api, $field)) {
        $errors[] = "response contract missing {$field}";
    }
}

$docs = (string)@file_get_contents($root . '/docs/knowledge/squad-chat.md');
foreach (['/admin/squad-chat.php', 'CSRF', 'attachment_not_supported', 'successful_agents'] as $needle) {
    if (!str_contains($docs, $needle)) {
        $errors[] = "Squad Chat docs missing {$needle}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "SQUAD_CHAT_ADMIN_HARDENING_TEST=PASS\n";
