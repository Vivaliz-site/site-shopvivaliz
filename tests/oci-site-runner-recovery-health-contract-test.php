<?php
declare(strict_types=1);

$workflow = dirname(__DIR__) . '/.github/workflows/oci-site-runner-recovery.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "OCI site runner recovery workflow missing\n");
    exit(1);
}
$text = (string) file_get_contents($workflow);

$needle = '-A "ShopVivaliz-Safe-Sync/1.0"';
if (substr_count($text, $needle) < 2) {
    fwrite(STDERR, "OCI recovery must use the approved Safe Sync user agent before and after reboot\n");
    exit(1);
}
if (!str_contains($text, "jq -e '.ok == true and (.release_sha | type == \"string\")'")) {
    fwrite(STDERR, "OCI recovery must keep strict public health JSON validation\n");
    exit(1);
}
if (!str_contains($text, 'OCI_SITE_IDENTITY=PASS') || !str_contains($text, 'OCI_SITE_LIFECYCLE=RUNNING')) {
    fwrite(STDERR, "OCI recovery must keep exact OCI identity and lifecycle guards\n");
    exit(1);
}
echo "oci-site-runner-recovery-health-contract: ok\n";
