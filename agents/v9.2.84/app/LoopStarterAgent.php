<?php

declare(strict_types=1);

require_once __DIR__ . '/RealWorkOrchestratorAgent.php';

final class ShopvivalizLoopStarterAgent
{
    public function run(array $options = []): array
    {
        $result = (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
        $result['agent'] = 'loop_starter_real_work';
        return $result;
    }
}
