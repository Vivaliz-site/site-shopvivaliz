<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/logger.php';

class CheckoutPageOptimizer
{
    private $db;
    private $logger;

    public function __construct()
    {
        $this->logger = new Logger('CheckoutPageOptimizer');
        $this->db = null;

        if (extension_loaded('mysqli')) {
            require_once __DIR__ . '/../config/database.php';

            try {
                $this->db = getDatabaseConnection();
            } catch (Throwable $exception) {
                $this->logger->warning('Banco indisponivel para auditoria de checkout: ' . $exception->getMessage());
            }
        } else {
            $this->logger->warning('Extensao mysqli indisponivel neste ambiente de auditoria.');
        }
    }

    public function optimizeCheckoutPage(): array
    {
        $this->logger->info('Iniciando otimizacao da pagina de checkout...');

        $report = [
            'generated_at' => gmdate('c'),
            'checkout_ready' => true,
            'recommendations' => $this->buildRecommendations(),
            'signals' => $this->collectSignals(),
        ];

        $report['checkout_ready'] = empty($report['signals']['critical_issues']);

        $this->persistReport($report);
        $this->logger->info('Otimizacao da pagina de checkout concluida.');

        return $report;
    }

    public function __destruct()
    {
        if ($this->db && method_exists($this->db, 'close')) {
            $this->db->close();
        }
    }

    private function buildRecommendations(): array
    {
        return [
            [
                'id' => 'guest-checkout',
                'priority' => 'high',
                'status' => 'active',
                'summary' => 'Checkout para convidados deve permanecer visivel e sem bloqueio antes do pagamento.',
            ],
            [
                'id' => 'inline-validation',
                'priority' => 'high',
                'status' => 'active',
                'summary' => 'Mensagens de validacao devem aparecer no mesmo campo para reduzir abandono.',
            ],
            [
                'id' => 'returning-customer',
                'priority' => 'medium',
                'status' => 'active',
                'summary' => 'Dados de clientes recorrentes devem ser reaproveitados com seguranca no navegador.',
            ],
            [
                'id' => 'shipping-feedback',
                'priority' => 'medium',
                'status' => 'active',
                'summary' => 'Frete e prazo precisam aparecer cedo para reduzir ansiedade na compra.',
            ],
        ];
    }

    private function collectSignals(): array
    {
        $signals = [
            'critical_issues' => [],
            'warnings' => [],
            'artifacts' => [],
        ];

        $checkoutFiles = [
            __DIR__ . '/../checkout/index.php',
            __DIR__ . '/../checkout-v2/index.php',
            __DIR__ . '/../checkout.php',
        ];

        foreach ($checkoutFiles as $file) {
            if (is_file($file)) {
                $signals['artifacts'][] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        if (!$signals['artifacts']) {
            $signals['critical_issues'][] = 'Nenhum template de checkout foi localizado.';
        }

        $freteEndpoint = __DIR__ . '/../api/melhorenvio/shipping-check.php';
        if (!is_file($freteEndpoint)) {
            $signals['warnings'][] = 'Endpoint de frete nao encontrado para auditoria local.';
        }

        if (!$this->db) {
            $signals['warnings'][] = 'Conexao com banco indisponivel neste ambiente de auditoria.';
        }

        return $signals;
    }

    private function persistReport(array $report): void
    {
        $targetDir = __DIR__ . '/../logs';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        @file_put_contents(
            $targetDir . '/checkout-optimizer-report.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

if (defined('APP_ENV') && APP_ENV === 'development') {
    $optimizer = new CheckoutPageOptimizer();
    $optimizer->optimizeCheckoutPage();
}
