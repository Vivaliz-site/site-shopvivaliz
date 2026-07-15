<?php
/**
 * SHOPEE SECRETS LOADER
 * Carrega credenciais do GitHub Secrets
 */

class ShopeeSecretsLoader {
    private $github_token;
    private $github_repo;
    private $secrets = [];

    public function __construct($github_token = null, $github_repo = null) {
        $this->github_token = $github_token ?? getenv('GITHUB_TOKEN');
        $this->github_repo = $github_repo ?? getenv('GITHUB_REPOSITORY');
    }

    /**
     * Carregar secrets do GitHub
     */
    public function loadFromGitHub() {
        if (!$this->github_token || !$this->github_repo) {
            throw new Exception('GITHUB_TOKEN ou GITHUB_REPOSITORY nao definido');
        }

        // Separar owner/repo
        list($owner, $repo) = explode('/', $this->github_repo);

        // Secrets a buscar
        $secret_names = [
            'SHOPEE_PARTNER_ID',
            'SHOPEE_PARTNER_KEY',
            'SHOPEE_SHOP_ID',
            'SHOPEE_ACCESS_TOKEN'
        ];

        foreach ($secret_names as $secret_name) {
            $value = $this->getSecret($owner, $repo, $secret_name);
            if ($value) {
                $this->secrets[$secret_name] = $value;
                putenv("$secret_name=$value");
            }
        }

        return $this->secrets;
    }

    /**
     * Buscar um secret especifico
     */
    private function getSecret($owner, $repo, $secret_name) {
        // Em CI/CD (GitHub Actions), os secrets ja estao em variaveis de ambiente
        $env_var = getenv($secret_name);
        if ($env_var) {
            return $env_var;
        }

        // Alternativa: chamar GitHub API (requer permissoes)
        // $url = "https://api.github.com/repos/$owner/$repo/actions/secrets/$secret_name";
        // $response = $this->callGitHubAPI($url);
        // return $response['value'] ?? null;

        return null;
    }

    /**
     * Chamar GitHub API
     */
    private function callGitHubAPI($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: token {$this->github_token}",
            "Accept: application/vnd.github.v3+json"
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Validar secrets carregados
     */
    public function validate() {
        $required = [
            'SHOPEE_PARTNER_ID',
            'SHOPEE_PARTNER_KEY',
            'SHOPEE_SHOP_ID',
            'SHOPEE_ACCESS_TOKEN'
        ];

        $missing = [];
        foreach ($required as $key) {
            if (empty($this->secrets[$key]) && !getenv($key)) {
                $missing[] = $key;
            }
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
            'message' => empty($missing) ? 'Todos os secrets carregados' : 'Secrets faltando: ' . implode(', ', $missing)
        ];
    }

    /**
     * Obter status
     */
    public function getStatus() {
        $loaded = $this->loadFromGitHub();

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'source' => 'GitHub Secrets',
            'loaded' => count($loaded),
            'secrets' => [
                'SHOPEE_PARTNER_ID' => !empty($this->secrets['SHOPEE_PARTNER_ID']) ? 'OK' : 'FALTANDO',
                'SHOPEE_PARTNER_KEY' => !empty($this->secrets['SHOPEE_PARTNER_KEY']) ? 'OK' : 'FALTANDO',
                'SHOPEE_SHOP_ID' => !empty($this->secrets['SHOPEE_SHOP_ID']) ? 'OK' : 'FALTANDO',
                'SHOPEE_ACCESS_TOKEN' => !empty($this->secrets['SHOPEE_ACCESS_TOKEN']) ? 'OK' : 'FALTANDO'
            ],
            'validation' => $this->validate()
        ];
    }

    public static function getInstance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }
}

// Teste se chamado diretamente
if (php_sapi_name() === 'cli' || isset($_GET['status'])) {
    header('Content-Type: application/json');

    try {
        $loader = ShopeeSecretsLoader::getInstance();
        $status = $loader->getStatus();

        // Validar
        $validation = $loader->validate();

        if (!$validation['valid']) {
            echo json_encode([
                'status' => 'erro',
                'message' => $validation['message'],
                'missing' => $validation['missing'],
                'detalhes' => $status
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit(1);
        }

        echo json_encode([
            'status' => 'ok',
            'message' => 'Todos os secrets foram carregados com sucesso',
            'config' => $status
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'erro',
            'message' => $e->getMessage(),
            'dica' => 'Verifique se esta rodando em GitHub Actions ou tem GITHUB_TOKEN configurado'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit(1);
    }
}
?>
