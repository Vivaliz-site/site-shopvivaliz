<?php
/**
 * SHOPEE CONFIG
 * Carrega credenciais de forma segura via .env
 */

class ShopeeConfig {
    private static $config = [];
    private static $loaded = false;

    public static function load() {
        if (self::$loaded) {
            return self::$config;
        }

        // Carregar .env
        $env_file = __DIR__ . '/../../.env';
        if (!file_exists($env_file)) {
            throw new Exception('.env file not found. Copy .env.example to .env and fill with your credentials');
        }

        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && $line[0] !== '#') {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remover aspas
                $value = trim($value, '"\'');

                putenv("$key=$value");
                self::$config[$key] = $value;
            }
        }

        self::$loaded = true;
        return self::$config;
    }

    public static function get($key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config[$key] ?? getenv($key) ?? $default;
    }

    public static function getShopee($key) {
        $key = 'SHOPEE_' . strtoupper($key);
        return self::get($key);
    }

    public static function validate() {
        $required = [
            'SHOPEE_PARTNER_ID',
            'SHOPEE_PARTNER_KEY',
            'SHOPEE_SHOP_ID',
            'SHOPEE_ACCESS_TOKEN'
        ];

        foreach ($required as $key) {
            if (self::get($key) === null || self::get($key) === 'seu_partner_id_aqui') {
                return [
                    'valid' => false,
                    'erro' => "Credencial nao configurada: $key",
                    'solucao' => 'Edite o arquivo .env com suas credenciais reais'
                ];
            }
        }

        return ['valid' => true];
    }

    public static function getStatus() {
        $config = self::load();
        $validation = self::validate();

        return [
            'configurado' => !empty($config),
            'credenciais' => [
                'partner_id' => !empty(self::getShopee('partner_id')) ? 'OK' : 'FALTANDO',
                'partner_key' => !empty(self::getShopee('partner_key')) ? 'OK' : 'FALTANDO',
                'shop_id' => !empty(self::getShopee('shop_id')) ? 'OK' : 'FALTANDO',
                'access_token' => !empty(self::getShopee('access_token')) ? 'OK' : 'FALTANDO'
            ],
            // Reflete o estado real das credenciais em vez de assumir "simulado"
            // por padrao -- 'modo' so vira 'real' quando validate() confirma
            // que todas as credenciais obrigatorias estao presentes.
            'modo' => self::get('SHOPEE_MODE', $validation['valid'] ? 'real' : 'nao_configurado'),
            'batch_size' => self::get('SHOPEE_BATCH_SIZE', 50)
        ];
    }
}

// Teste se chamado diretamente
if (php_sapi_name() === 'cli' || isset($_GET['status'])) {
    header('Content-Type: application/json');

    try {
        ShopeeConfig::load();
        $validation = ShopeeConfig::validate();

        if (!$validation['valid']) {
            echo json_encode([
                'status' => 'erro',
                'mensagem' => $validation['erro'],
                'solucao' => $validation['solucao']
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit(1);
        }

        echo json_encode([
            'status' => 'ok',
            'config' => ShopeeConfig::getStatus()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'erro',
            'mensagem' => $e->getMessage()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit(1);
    }
}
?>
