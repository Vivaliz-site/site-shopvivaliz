<?php
declare(strict_types=1);

echo "🔍 VERIFICAÇÃO DE DEPLOY AUTÔNOMO\n";
echo "==================================\n\n";

$baseDir = dirname(__DIR__) . '/api/autonomous';
$files = [
    'send-email.php',
    'task-validator.php',
    'approval-queue-manager.php',
    'cost-tracker.php',
    'regression-tracker.php',
    'incident-manager.php',
    'testing-framework.php',
    'health-monitor.php',
    'review-enforcer.php',
    'task-deduplicator.php',
    'maintenance-controller.php',
    'backup-manager.php',
    'execution-budget.php',
    'database-safety.php',
    'canary-and-validation.php',
    'operational-controls.php'
];

$missing = [];
$loaded = 0;

foreach ($files as $file) {
    $path = $baseDir . '/' . $file;
    if (!file_exists($path)) {
        $missing[] = $file;
        echo "❌ $file - NÃO ENCONTRADO\n";
    } else {
        echo "✅ $file\n";
        $loaded++;
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 RESULTADO:\n";
echo "   ✅ Arquivos presentes: $loaded/16\n";
if (count($missing) > 0) {
    echo "   ❌ Arquivos faltando: " . count($missing) . "\n";
    echo "      - " . implode("\n      - ", $missing) . "\n";
} else {
    echo "   ✅ TODOS os 16 arquivos encontrados!\n";
}

echo "\n🚀 DEPLOYMENT STATUS: " . (count($missing) === 0 ? "SUCESSO" : "FALHOU") . "\n";
?>
