<?php
$root = dirname(__DIR__);
$obsolete = [
    'oci-a1-bootstrap.yml',
    'vm-legacy-runtime-cleanup.yml',
    'vm-maintenance-action.yml',
    'retire-fredwin-m365-certificate.yml',
];
$violations = [];
foreach ($obsolete as $name) {
    $active = $root . '/.github/workflows/' . $name;
    if (is_file($active)) $violations[] = $name;
}
if ($violations) {
    fwrite(STDERR, "Obsolete workflows remain executable under the two-A1 architecture:\n" . implode("\n", $violations) . "\n");
    exit(1);
}
echo "obsolete-two-a1-workflows-disabled-contract: ok\n";
