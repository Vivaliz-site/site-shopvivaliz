<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-denied-response.php';

$cases = [
    ['/admin/amazon-margin.php', 'GET', 'text/html,application/xhtml+xml', true],
    ['/admin/index.php', 'GET', 'TEXT/HTML', true],
    ['/admin/amazon-margin.php', 'GET', '*/*', false],
    ['/api/admin/amazon-margin.php', 'GET', 'text/html', false],
    ['/admin/amazon-margin.php', 'POST', 'text/html', false],
    ['/admin/amazon-margin.php', 'DELETE', 'text/html', false],
    ['/', 'GET', 'text/html', false],
];
foreach ($cases as [$path, $method, $accept, $expected]) {
    if (sv_admin_denied_is_browser_page($path, $method, $accept) !== $expected) {
        throw new RuntimeException('admin denied content negotiation regressed');
    }
}

$html = sv_admin_denied_page();
if (!str_starts_with($html, '<!doctype html>')
    || !str_contains($html, 'Esta sessão não tem acesso administrativo')
    || !str_contains($html, 'href="/auth/logout.php"')
    || str_contains($html, 'http-equiv="refresh"')
    || str_contains($html, '<script')
) {
    throw new RuntimeException('admin denied page must guide manual account change without bypass');
}
echo "PASS admin denied page: browser recovery, API denial, no automatic session switch\n";
