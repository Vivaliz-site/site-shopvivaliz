<?php
declare(strict_types=1);

function auto_fix(string $issue): ?array
{
    if ($issue === 'null_error') {
        return [
            'issue' => $issue,
            'action' => 'add_null_safety',
            'patch_hint' => '// fixed null safety',
        ];
    }

    if ($issue === 'missing_route') {
        return [
            'issue' => $issue,
            'action' => 'generate_route_stub',
            'patch_hint' => '// route auto-generated',
        ];
    }

    if ($issue === 'missing_image') {
        return [
            'issue' => $issue,
            'action' => 'fallback_asset',
            'patch_hint' => '/assets/default.png',
        ];
    }

    return null;
}
