<?php

declare(strict_types=1);

require_once __DIR__ . '/RealWorkOrchestratorAgent.php';

final class ShopvivalizSelfTestAgent
{
    public function run(array $options = []): array
    {
        $result = (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
        $result['agent'] = 'self_test_real_work';
        return $result;
    }
}
