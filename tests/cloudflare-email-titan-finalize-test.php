<?php
declare(strict_types=1);

$workflow = file_get_contents(__DIR__ . '/../.github/workflows/cloudflare-email-routing-bootstrap.yml') ?: '';
$errors = [];

foreach ([
    '- verify' => 'verify mode missing',
    '- repair' => 'repair mode missing',
    "FINAL_SPF: 'v=spf1 include:amazonses.com include:_spf.mx.cloudflare.net ~all'" => 'final SPF contract missing',
    'POST_CUTOVER_STATE_REPAIRED' => 'post-cutover repair marker missing',
    'POST_CUTOVER_EMAIL_ROUTING_VERIFIED' => 'post-cutover verification marker missing',
    'VERIFY_NON_CLOUDFLARE_MX_PRESENT' => 'Cloudflare-only MX guard missing',
    'VERIFY_BREVO_DKIM_MISSING' => 'Brevo DKIM guard missing',
    'VERIFY_DMARC_MISSING' => 'DMARC guard missing',
] as $needle => $message) {
    if (!str_contains($workflow, $needle)) {
        $errors[] = $message;
    }
}

foreach ([
    'titan.email',
    'include:spf.titan.email',
    'rollback',
    'restore_titan',
    'TITAN_MX',
] as $forbidden) {
    if (stripos($workflow, $forbidden) !== false) {
        $errors[] = "retired provider rollback surface remains: {$forbidden}";
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "cloudflare_email_post_cutover_guard=PASS\n";
