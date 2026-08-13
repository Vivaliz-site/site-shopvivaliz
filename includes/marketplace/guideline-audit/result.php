<?php

declare(strict_types=1);

/** @param array<string,array<string,mixed>> $checks */
function sv_guideline_audit_add(array &$checks, string $key, bool $ok, string $severity, string $message, mixed $actual = null, mixed $target = null): void
{
    $checks[$key] = compact('ok', 'severity', 'message', 'actual', 'target');
}

/** @param array<string,array<string,mixed>> $checks @return array<string,mixed> */
function sv_guideline_audit_finish(string $channel, array $profile, array $checks): array
{
    $hard = [];
    $soft = [];
    foreach ($checks as $key => $check) {
        if (!empty($check['ok'])) continue;
        $entry = [
            'key' => $key,
            'message' => (string)$check['message'],
            'actual' => $check['actual'] ?? null,
            'target' => $check['target'] ?? null,
        ];
        if (($check['severity'] ?? 'soft') === 'hard') $hard[] = $entry;
        else $soft[] = $entry;
    }
    $passed = count(array_filter($checks, static fn(array $check): bool => !empty($check['ok'])));
    return [
        'success' => true,
        'channel' => $channel,
        'label' => (string)$profile['label'],
        'policy_version' => (string)$profile['policy_version'],
        'verified_at' => (string)$profile['verified_at'],
        'hard_pass' => $hard === [],
        'score' => $checks === [] ? 0 : (int)round(($passed / count($checks)) * 100),
        'checks' => $checks,
        'hard_failures' => $hard,
        'advisories' => $soft,
        'profile' => sv_marketplace_guideline_public_profile($channel),
    ];
}
