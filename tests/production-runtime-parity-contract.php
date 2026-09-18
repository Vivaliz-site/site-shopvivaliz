<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/scripts/production-runtime-parity.mjs');
$required = [
    "payment_option_visible', 'mercado_pago option missing",
    "unexpectedConsoleErrors",
    "unexpectedRequestFailures",
    "analytics-proxy-transient",
    "external-font-network-change",
    "navigation-aborted-product-image",
    "navigation-aborted-analytics",
    "checkout-reload-aborted-mercadopago-probe",
    "checkout-navigation-aborted-tracking",
    "headless-fingerprint-orb",
    "fail('pageerror'",
    "fail('console_error'",
    "fail('requestfailed'",
    "fail('server_5xx'",
    "PRODUCTION_RUNTIME_PARITY=PASS",
];
foreach ($required as $marker) {
    if (!str_contains($source, $marker)) {
        fwrite(STDERR, "runtime parity contract missing: {$marker}\n");
        exit(1);
    }
}
if (str_contains($source, "if (evidence.requestFailures.length) fail('requestfailed'")) {
    fwrite(STDERR, "runtime parity must classify request failures instead of blanket failure\n");
    exit(1);
}
if (str_contains($source, "if (evidence.consoleErrors.length) fail('console_error'")) {
    fwrite(STDERR, "runtime parity must classify console errors instead of blanket failure\n");
    exit(1);
}
echo "production_runtime_parity_contract=PASS\n";
