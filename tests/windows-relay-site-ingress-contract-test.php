<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = [
  'fred' => $root . '/.github/workflows/fred-win-remote-action.yml',
  'kocepsv' => $root . '/.github/workflows/desktopkocepsv-remote-action.yml',
  'health' => $root . '/.github/workflows/remote-control-plane-health.yml',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) { fwrite(STDERR, "missing {$name} workflow\n"); exit(1); }
}
$fred = file_get_contents($files['fred']);
$desk = file_get_contents($files['kocepsv']);
$health = file_get_contents($files['health']);
foreach ([['fred',$fred,'127.0.0.1:5557'], ['kocepsv',$desk,'127.0.0.1:5558']] as [$name,$text,$relay]) {
    if (strpos($text, $relay) === false) { fwrite(STDERR, "{$name} missing site-local relay {$relay}\n"); exit(1); }
    if (strpos($text, 'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]') === false) { fwrite(STDERR, "{$name} missing site runner\n"); exit(1); }
    if (strpos($text, 'ubuntu@10.0.1.38') !== false) { fwrite(STDERR, "{$name} must not route Windows relay through backend\n"); exit(1); }
}
if (strpos($health, 'ubuntu@10.0.1.38') === false) { fwrite(STDERR, "health must retain private backend administration check\n"); exit(1); }
foreach (['127.0.0.1:5557/health','127.0.0.1:5558/health'] as $relay) {
    if (strpos($health, $relay) === false) { fwrite(STDERR, "health missing site-local {$relay}\n"); exit(1); }
}
$relaySection = strstr($health, '- name: Validate Windows private relays');
if ($relaySection === false) { fwrite(STDERR, "health relay section missing\n"); exit(1); }
$relaySection = preg_split('/\n\s*- name: Cleanup SSH material/', $relaySection)[0] ?? $relaySection;
if (strpos($relaySection, 'ubuntu@10.0.1.38') !== false) { fwrite(STDERR, "health Windows relays must be tested locally on site runner\n"); exit(1); }
echo "windows-relay-site-ingress-contract: ok\n";
