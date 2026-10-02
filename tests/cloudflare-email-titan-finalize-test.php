<?php
declare(strict_types=1);

$workflow = file_get_contents(__DIR__ . '/../.github/workflows/cloudflare-email-routing-bootstrap.yml') ?: '';
$errors = [];

foreach ([
    '- finalize' => 'finalize mode missing',
    "FINAL_SPF: 'v=spf1 include:amazonses.com include:_spf.mx.cloudflare.net ~all'" => 'final SPF contract missing',
    'Finalize Titan SPF removal' => 'finalize step missing',
    'TITAN_SPF_REMOVED_VERIFIED' => 'post-change verification missing',
    'FINALIZE_ABORT_TITAN_MX_PRESENT' => 'Titan MX safety gate missing',
    'FINALIZE_ABORT_UNEXPECTED_SPF' => 'unexpected SPF fail-closed gate missing',
] as $needle => $message) {
    if (!str_contains($workflow, $needle)) $errors[] = $message;
}

if (!str_contains($workflow, 'include:spf.titan.email')) {
    $errors[] = 'rollback snapshot must remain available until service cancellation is separately confirmed';
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "cloudflare_email_titan_finalize=PASS\n";
