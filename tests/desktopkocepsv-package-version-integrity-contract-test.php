<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$supervisor = (string) file_get_contents($root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1');
$status = (string) file_get_contents($root . '/scripts/desktopkocepsv-desktop-commander-status.ps1');

foreach ([$supervisor, $status] as $src) {
    foreach ([
        '$PinnedVersion = \'0.2.51\'',
        'function Test-CanonicalRemoteCommand',
        'package.json',
        'ConvertFrom-Json',
        '$manifest.version -eq $PinnedVersion',
    ] as $needle) {
        if (strpos($src, $needle) === false) {
            fwrite(STDERR, "missing exact-version canonical check: {$needle}\n");
            exit(1);
        }
    }
}

foreach ([$supervisor, $status] as $src) {
    if (strpos($src, "-or\n        (\$command -match '@wonderwhy-er[\\\\/]desktop-commander") !== false) {
        fwrite(STDERR, "generic dist/index.js launcher must not be accepted without manifest validation\n");
        exit(1);
    }
}

foreach ([
    'function Ensure-PinnedPackageRoot',
    'PACKAGE_PREFLIGHT=verified_hint',
    'PACKAGE_PREFLIGHT=cache_hit',
    'PACKAGE_PREFLIGHT=npx_resolved',
] as $needle) {
    if (strpos($supervisor, $needle) === false) {
        fwrite(STDERR, "missing package preflight: {$needle}\n");
        exit(1);
    }
}

if (!preg_match('/function Install-Task \{(?P<body>.*?)\n\}/s', $supervisor, $match)) {
    fwrite(STDERR, "Install-Task body not found\n");
    exit(1);
}
$body = $match['body'];
$preflight = strpos($body, 'Ensure-PinnedPackageRoot');
$stop = strpos($body, 'Stop-RemoteProcesses');
if ($preflight === false || $stop === false || $preflight >= $stop) {
    fwrite(STDERR, "package preflight must complete before provider stop\n");
    exit(1);
}

echo "desktopkocepsv-package-version-integrity-contract: ok\n";
