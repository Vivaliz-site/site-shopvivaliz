<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$servicePath = $root . '/deploy/systemd/shopvivaliz-catalog-reconcile.service';
$timerPath = $root . '/deploy/systemd/shopvivaliz-catalog-reconcile.timer';
$installerPath = $root . '/scripts/install-catalog-sync-service.sh';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_file($servicePath), 'catalog reconcile service missing');
$assert(is_file($timerPath), 'catalog reconcile timer missing');
$service = (string)file_get_contents($servicePath);
$timer = (string)file_get_contents($timerPath);
$installer = (string)file_get_contents($installerPath);

$assert(str_contains($service, 'Type=oneshot'), 'reconcile service must be oneshot');
$assert(str_contains($service, 'daemon-sync-products.py --once'), 'reconcile service must use finite --once sync');
$assert(str_contains($service, 'User=ubuntu'), 'reconcile service must run as ubuntu');
$assert(str_contains($timer, 'OnUnitInactiveSec=30min'), 'reconcile timer must run every thirty minutes');
$assert(str_contains($timer, 'Persistent=true'), 'reconcile timer must be persistent');
$assert(str_contains($installer, 'shopvivaliz-catalog-reconcile.service'), 'installer must install reconcile service');
$assert(str_contains($installer, 'shopvivaliz-catalog-reconcile.timer'), 'installer must install reconcile timer');
$assert(str_contains($installer, 'systemctl enable --now shopvivaliz-catalog-reconcile.timer'), 'installer must enable reconcile timer');
$assert(str_contains($installer, 'systemctl disable --now shopvivaliz-sync-products.service'), 'legacy continuous sync must stay disabled');

echo "catalog-reconcile-service-contract: ok\n";
