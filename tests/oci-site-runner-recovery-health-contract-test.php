<?php
declare(strict_types=1);

$workflow = dirname(__DIR__) . '/.github/workflows/oci-site-runner-recovery.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "OCI site runner recovery workflow missing\n");
    exit(1);
}
$text = (string) file_get_contents($workflow);
$required = [
    'RECOVERY_USER_AGENT: ShopVivaliz-OCI-Recovery/1.0',
    '-A "$RECOVERY_USER_AGENT"',
    "-H 'Accept: application/json'",
    "jq -e '.ok == true and (.release_sha | type == \"string\")'",
    'OCI_SITE_IDENTITY=PASS',
    'OCI_SITE_LIFECYCLE=RUNNING',
];
foreach ($required as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "OCI recovery health contract missing: {$needle}\n");
        exit(1);
    }
}
if (substr_count($text, '-A "$RECOVERY_USER_AGENT"') < 2) {
    fwrite(STDERR, "OCI recovery must authenticate both pre- and post-reboot health probes\n");
    exit(1);
}
echo "oci-site-runner-recovery-health-contract: ok\n";
