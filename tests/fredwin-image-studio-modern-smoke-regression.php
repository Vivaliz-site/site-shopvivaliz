<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$smoke = file_get_contents($root . '/tests/fredwin-admin-mobile-smoke.py');
if (!is_string($smoke)) { fwrite(STDERR, "FAIL: smoke unreadable\n"); exit(1); }
foreach (['#iv-channel', '#iv-provider', '#iv-model', '#iv-limit', '#iv-list', '#iv-run'] as $selector) {
    if (!str_contains($smoke, $selector)) { fwrite(STDERR, "FAIL: modern Image Studio selector missing: {$selector}\n"); exit(1); }
}
foreach ([".ais-form form[method=get]", '/admin/ai-image-studio/admin_dashboard.php?preview=1'] as $legacy) {
    if (str_contains($smoke, $legacy)) { fwrite(STDERR, "FAIL: stale Image Studio smoke contract remains: {$legacy}\n"); exit(1); }
}
echo "fredwin-image-studio-modern-smoke: ok\n";
