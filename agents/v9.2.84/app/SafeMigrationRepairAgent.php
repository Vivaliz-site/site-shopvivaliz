<?php

declare(strict_types=1);

require_once __DIR__ . '/RealWorkOrchestratorAgent.php';

final class ShopvivalizSafeMigrationRepairAgent
{
    public function run(array $options = []): array
    {
        $result = (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
        $result['agent'] = 'safe_migration_repair_real_work';
        return $result;
    }
}
