<?php

declare(strict_types=1);

require_once __DIR__ . '/RealWorkOrchestratorAgent.php';

final class ShopvivalizAutonomousWatchdogAgent
{
    public function run(array $options = []): array
    {
        return (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
    }
}
