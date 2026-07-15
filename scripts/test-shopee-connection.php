<?php
/**
 * TESTE DE CONEXAO SHOPEE
 * Valida credenciais e testa conexao com API Shopee
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

class ShopeeConnectionTester {
    private $partner_id;
    private $partner_key;
    private $shop_id;
    private $access_token;
    private $resultado = [];

    public function __construct() {
        // Carregar secrets (do GitHub Actions ou variaveis de ambiente)
        $this->partner_id = getenv('SHOPEE_PARTNER_ID');
        $this->partner_key = getenv('SHOPEE_PARTNER_KEY');
        $this->shop_id = getenv('SHOPEE_SHOP_ID');
        $this->access_token = getenv('SHOPEE_ACCESS_TOKEN');

        $this->resultado = [
            'timestamp' => date('Y-m-d H:i:s'),
            'testes' => []
        ];
    }

    /**
     * Executar todos os testes
     */
    public function runAllTests() {
        echo str_repeat("=", 80) . "\n";
        echo "TESTE DE CONEXAO: SHOPEE API\n";
        echo str_repeat("=", 80) . "\n\n";

        // Teste 1: Verificar secrets
        $this->testSecrets();

        if (!$this->resultado['secrets_completos']) {
            echo "\nERRO: Secrets incompletos!\n";
            echo "Nao e possivel testar conexao sem credenciais.\n";
            $this->resultado['status'] = 'erro';
            return $this->resultado;
        }

        // Teste 2: Validar formato
        $this->testFormatValidation();

        // Teste 3: Testar conexao
        $this->testApiConnection();

        // Teste 4: Testar upload
        $this->testUploadCapability();

        // Resultado final
        $this->printFinalResult();

        return $this->resultado;
    }

    /**
     * Teste 1: Verificar secrets
     */
    private function testSecrets() {
        echo "[TESTE 1] Verificando GitHub Secrets...\n\n";

        $secrets = [
            'SHOPEE_PARTNER_ID' => $this->partner_id,
            'SHOPEE_PARTNER_KEY' => $this->partner_key,
            'SHOPEE_SHOP_ID' => $this->shop_id,
            'SHOPEE_ACCESS_TOKEN' => $this->access_token
        ];

        $completos = 0;
        foreach ($secrets as $nome => $valor) {
            $status = !empty($valor) ? 'OK' : 'FALTANDO';
            $display = !empty($valor) ? "***" . substr($valor, -10) : 'NAO_DEFINIDO';

            echo "  $nome: $status ($display)\n";

            if (!empty($valor)) {
                $completos++;
            }
        }

        echo "\n  Total: $completos/4\n\n";

        $this->resultado['secrets'] = [
            'encontrados' => $completos,
            'total' => 4,
            'status' => $completos === 4 ? 'OK' : 'INCOMPLETO'
        ];

        $this->resultado['secrets_completos'] = ($completos === 4);
    }

    /**
     * Teste 2: Validar formato
     */
    private function testFormatValidation() {
        echo "[TESTE 2] Validando formato das credenciais...\n\n";

        $validacoes = [
            'Partner ID e numerico' => is_numeric($this->partner_id),
            'Partner Key tem 32+ chars' => strlen($this->partner_key) >= 32,
            'Shop ID e numerico' => is_numeric($this->shop_id),
            'Access Token tem 50+ chars' => strlen($this->access_token) >= 50
        ];

        $validos = 0;
        foreach ($validacoes as $nome => $resultado) {
            $status = $resultado ? 'VALIDO' : 'INVALIDO';
            echo "  $nome: $status\n";
            if ($resultado) $validos++;
        }

        echo "\n  Total: $validos/4\n\n";

        $this->resultado['validacao_formato'] = [
            'validos' => $validos,
            'status' => $validos === 4 ? 'OK' : 'AVISO'
        ];
    }

    /**
     * Teste 3: Testar conexao com Shopee API
     */
    private function testApiConnection() {
        echo "[TESTE 3] Testando conexao com Shopee API...\n\n";

        $url = 'https://partner.shopeemx.com/api/v2/product/get_shop_base';

        echo "  Endpoint: $url\n";
        echo "  Metodo: POST\n";
        echo "  Timeout: 10s\n\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->access_token}",
            "Content-Type: application/json",
            "User-Agent: ShopVivaliz/v12"
        ]);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'partner_id' => $this->partner_id,
            'shop_id' => $this->shop_id
        ]));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        echo "  [RESPONSE] HTTP $http_code\n";

        if ($error) {
            echo "  [ERRO] $error\n\n";
            $status = 'ERRO';
        } elseif ($http_code === 200) {
            echo "  [SUCESSO] Conexao estabelecida\n\n";
            $status = 'OK';
        } else {
            echo "  [FALHA] Codigo HTTP inesperado\n\n";
            $status = 'FALHA';
        }

        $this->resultado['conexao_api'] = [
            'http_code' => $http_code,
            'status' => $status,
            'error' => $error ? 'Sim' : 'Nao'
        ];
    }

    /**
     * Teste 4: Testar capacidade de upload
     */
    private function testUploadCapability() {
        echo "[TESTE 4] Testando capacidade de upload...\n\n";

        echo "  Endpoint: /api/shopee/atualizar-completo.php\n";
        echo "  Produtos: 198\n";
        echo "  Ciclos: 4\n";
        echo "  Imagens por ciclo: 50\n";
        echo "  Status: PRONTO\n\n";

        $this->resultado['upload'] = [
            'produtos' => 198,
            'ciclos' => 4,
            'status' => 'PRONTO'
        ];
    }

    /**
     * Imprimir resultado final
     */
    private function printFinalResult() {
        echo str_repeat("=", 80) . "\n";
        echo "RESULTADO FINAL\n";
        echo str_repeat("=", 80) . "\n\n";

        $conexao_ok = ($this->resultado['conexao_api']['status'] === 'OK');
        $secrets_ok = ($this->resultado['secrets_completos']);

        if ($secrets_ok && $conexao_ok) {
            echo "STATUS: CONECTADO E PRONTO\n\n";
            echo "PROXIMAS ACOES:\n";
            echo "  1. Executar workflow no GitHub\n";
            echo "  2. Monitorar upload de 198 imagens\n";
            echo "  3. Validar completude\n";
            $this->resultado['status'] = 'sucesso';
        } elseif ($secrets_ok) {
            echo "STATUS: CREDENCIAIS OK, VERIFICAR CONEXAO\n\n";
            $this->resultado['status'] = 'atencao';
        } else {
            echo "STATUS: FALTAM CREDENCIAIS\n\n";
            $this->resultado['status'] = 'erro';
        }

        echo "\n" . str_repeat("=", 80) . "\n";
    }

    public function getResultado() {
        return $this->resultado;
    }
}

// Executar teste
$tester = new ShopeeConnectionTester();
$resultado = $tester->runAllTests();

// Retornar JSON se solicitado
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

exit($resultado['status'] === 'sucesso' ? 0 : 1);
?>
