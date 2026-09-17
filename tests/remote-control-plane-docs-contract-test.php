<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$host = (string) file_get_contents($root . '/docs/knowledge/host-access.md');
$readme = (string) file_get_contents($root . '/docs/knowledge/README.md');
$fred = (string) file_get_contents($root . '/docs/FRED-WIN-PRIVATE-RELAY.md');
$koPath = $root . '/docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md';
$dc = (string) file_get_contents($root . '/docs/DESKTOP-COMMANDER-24H.md');
if (!is_file($koPath)) { fwrite(STDERR, "missing DESKTOP-KOCEPSV private relay doc\n"); exit(1); }
$ko = (string) file_get_contents($koPath);
foreach (['shopvivaliz-free-a1','10.0.1.112','always-free-arm-1787907847-26','10.0.1.38','127.0.0.1:5557','127.0.0.1:5558','GitHub Actions/private relay','OCI Bastion','Desktop Commander fallback'] as $needle) {
    if (strpos($host . $readme . $fred . $ko . $dc, $needle) === false) { fwrite(STDERR, "docs missing {$needle}\n"); exit(1); }
}
if (stripos($host, 'preferir Desktop Commander') !== false) { fwrite(STDERR, "host-access still prefers Desktop Commander\n"); exit(1); }
if (strpos($host, 'Nunca editar diretamente `current/`') === false) { fwrite(STDERR, "immutable deploy rule missing\n"); exit(1); }
if (strpos($ko, 'environment=desktop-kocepsv') === false || strpos($ko, '127.0.0.1:5558') === false) { fwrite(STDERR, "KOCEPSV relay evidence contract missing\n"); exit(1); }
if (stripos($dc, 'fallback') === false || stripos($dc, 'provider') === false) { fwrite(STDERR, "DC docs must identify fallback/provider role\n"); exit(1); }
echo "remote-control-plane-docs-contract: ok\n";
