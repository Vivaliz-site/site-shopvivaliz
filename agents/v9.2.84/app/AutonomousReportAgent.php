<?php

declare(strict_types=1);

require_once __DIR__ . '/RealWorkOrchestratorAgent.php';

final class ShopvivalizAutonomousReportAgent
{
    public const VERSION = '10.0.0-evidence-based';

    public function run(array $options = []): array
    {
        $result = (new ShopvivalizRealWorkOrchestratorAgent())->run($options);
        $result['agent'] = 'autonomous_report_real_work';
        return $result;
    }
}
