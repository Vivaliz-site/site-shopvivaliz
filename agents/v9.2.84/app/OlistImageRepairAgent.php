<?php

declare(strict_types=1);

require_once __DIR__ . '/RealWorkOrchestratorAgent.php';

final class ShopvivalizOlistImageRepairAgent
{
    public function run(array $options = []): array
    {
        $result = (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
        $result['agent'] = 'olist_image_repair_real_work';
        return $result;
    }
}
