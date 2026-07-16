<?php
/**
 * 🔐 Security Scanner - OWASP Top 10 + Dependency Scanning
 * Vulnerabilidades, secrets, dependências inseguras
 */

class SecurityScanner {
    private $reportFile = '.security-scan-report.json';
    private $vulnerabilityDb = 'https://api.github.com/graphql'; // GitHub Advisory DB
    private $criticalThreshold = 7.0; // CVSS score

    private function rootPath(string $path = ''): string {
        $root = dirname(__DIR__);
        return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private function projectFiles(array $extensions): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->rootPath(), FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    if (!$file->isDir()) return true;
                    return !in_array($file->getFilename(), ['.git', 'node_modules', 'vendor', 'logs', 'test-results', 'playwright-report'], true);
                }
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    public function run() {
        echo "🔐 Iniciando Security Scan...\n";

        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'owasp_scan' => $this->scanOWASP(),
            'secrets_scan' => $this->scanSecrets(),
            'dependencies' => $this->scanDependencies(),
            'container_scan' => $this->scanContainer(),
            'summary' => [],
        ];

        $this->analyzeScan($results);
        $this->generateReport($results);

        return $results;
    }

    private function scanOWASP() {
        echo "🔍 Scanning OWASP Top 10...\n";

        $issues = [];

        // 1. SQL Injection
        $sqlInjectionVulns = $this->checkSQLInjection();
        if (!empty($sqlInjectionVulns)) {
            $issues[] = [
                'type' => 'SQL_INJECTION',
                'severity' => 'CRITICAL',
                'files' => $sqlInjectionVulns,
            ];
        }

        // 2. XSS (Cross-Site Scripting)
        $xssVulns = $this->checkXSS();
        if (!empty($xssVulns)) {
            $issues[] = [
                'type' => 'XSS',
                'severity' => 'HIGH',
                'files' => $xssVulns,
            ];
        }

        // 3. CSRF
        $csrfVulns = $this->checkCSRF();
        if (!empty($csrfVulns)) {
            $issues[] = [
                'type' => 'CSRF',
                'severity' => 'HIGH',
                'files' => $csrfVulns,
            ];
        }

        // 4. Insecure Deserialization
        $deserialVulns = $this->checkInsecureDeserialization();
        if (!empty($deserialVulns)) {
            $issues[] = [
                'type' => 'INSECURE_DESERIALIZATION',
                'severity' => 'CRITICAL',
                'files' => $deserialVulns,
            ];
        }

        // 5. Broken Authentication
        $authVulns = $this->checkBrokenAuth();
        if (!empty($authVulns)) {
            $issues[] = [
                'type' => 'BROKEN_AUTH',
                'severity' => 'CRITICAL',
                'files' => $authVulns,
            ];
        }

        echo "✅ OWASP scan concluído: " . count($issues) . " issues\n";

        return $issues;
    }

    private function checkSQLInjection() {
        echo "  Checking SQL Injection...\n";

        $vulns = [];
        $files = $this->projectFiles(['php']);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // Procurar patterns perigosos
            if (preg_match('/\$_(GET|POST|REQUEST)\s*\[\s*[\'"]?\w+[\'"]?\s*\].*?(?:mysqli|PDO|->query)/i', $content)) {
                if (!preg_match('/mysqli_real_escape_string|prepared.*statement|parameterized|bind/i', $content)) {
                    $vulns[] = $file;
                }
            }
        }

        return array_slice($vulns, 0, 5); // Top 5
    }

    private function checkXSS() {
        echo "  Checking XSS vulnerabilities...\n";

        $vulns = [];
        $files = $this->projectFiles(['php']);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // Procurar echo/print sem htmlspecialchars
            if (preg_match('/echo\s+\$_(GET|POST|REQUEST|COOKIE)/i', $content)) {
                if (!preg_match('/htmlspecialchars|htmlentities|strip_tags/i', $content)) {
                    $vulns[] = $file;
                }
            }
        }

        return array_slice($vulns, 0, 5);
    }

    private function checkCSRF() {
        echo "  Checking CSRF protection...\n";

        $vulns = [];
        $files = $this->projectFiles(['php']);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // Procurar forms POST sem CSRF token
            if (preg_match('/<form.*method=["\']post["\'].*>/i', $content)) {
                if (!preg_match('/csrf_token|nonce|token/i', $content)) {
                    $vulns[] = $file;
                }
            }
        }

        return array_slice($vulns, 0, 5);
    }

    private function checkInsecureDeserialization() {
        echo "  Checking insecure deserialization...\n";

        $vulns = [];
        $files = $this->projectFiles(['php']);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // Procurar unserialize com input de usuário
            if (preg_match('/unserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', $content)) {
                $vulns[] = $file;
            }
        }

        return array_slice($vulns, 0, 5);
    }

    private function checkBrokenAuth() {
        echo "  Checking broken authentication...\n";

        $vulns = [];

        // Verificar se admin panel tem proteção
        if (!file_exists($this->rootPath('includes/admin-guard.php'))) {
            $vulns[] = 'Admin panel sem proteção de autenticação';
        }

        // Verificar se senhas são hasheadas
        $configFiles = glob($this->rootPath('config/*.php')) ?: [];
        foreach ($configFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match('/password\s*=\s*[\'"](?!^\$2|\$argon|\$pbkdf)/i', $content)) {
                $vulns[] = $file . ' (possível senha em plain text)';
            }
        }

        return array_slice($vulns, 0, 5);
    }

    private function scanSecrets() {
        echo "🔍 Scanning para secrets em código...\n";

        $secrets = [];
        $patterns = [
            'AWS_KEY' => '/AKIA[0-9A-Z]{16}/',
            'PRIVATE_KEY' => '/-----BEGIN (RSA|DSA|EC)? PRIVATE KEY-----/',
            'API_KEY' => '/api[_-]?key\s*[:=]\s*[\'"]([a-zA-Z0-9\-_]{32,})[\'"]/',
            'DATABASE_URL' => '/((mysql|postgres):\/\/.*@.*\/)/i',
            'AUTH_TOKEN' => '/auth[_-]?token\s*[:=]\s*[\'"]([a-zA-Z0-9\-_]{20,})[\'"]/',
        ];

        $files = $this->projectFiles(['php', 'json']);

        foreach ($files as $file) {
            if (strpos($file, '.git') !== false || strpos($file, 'node_modules') !== false) {
                continue;
            }

            $content = file_get_contents($file);

            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $content)) {
                    $secrets[] = [
                        'type' => $type,
                        'file' => $file,
                        'severity' => 'CRITICAL',
                    ];
                }
            }
        }

        echo "✅ Secrets scan concluído: " . count($secrets) . " potenciais exposições\n";

        return $secrets;
    }

    private function scanDependencies() {
        echo "🔍 Scanning dependências...\n";

        $vulns = [];

        // Check composer.lock
        if (file_exists($this->rootPath('composer.lock'))) {
            echo "  Checking PHP dependencies...\n";
            $vulns = array_merge($vulns, $this->checkComposerVulns());
        }

        // Check package-lock.json
        if (file_exists($this->rootPath('package-lock.json'))) {
            echo "  Checking npm dependencies...\n";
            $vulns = array_merge($vulns, $this->checkNpmVulns());
        }

        echo "✅ Dependency scan concluído: " . count($vulns) . " vulnerabilidades\n";

        return $vulns;
    }

    private function checkComposerVulns() {
        // Simular resultado de composer audit
        $result = shell_exec('cd ' . escapeshellarg($this->rootPath()) . ' && composer audit --json 2>&1');

        if ($result) {
            $data = json_decode($result, true);
            return $data['vulnerabilities'] ?? [];
        }

        return [];
    }

    private function checkNpmVulns() {
        // Simular resultado de npm audit
        $result = shell_exec('cd ' . escapeshellarg($this->rootPath()) . ' && npm audit --json 2>&1');

        if ($result) {
            $data = json_decode($result, true);
            $vulns = [];

            if (isset($data['vulnerabilities'])) {
                foreach ($data['vulnerabilities'] as $pkg => $info) {
                    if ($info['severity'] === 'critical' || $info['severity'] === 'high') {
                        $vulns[] = [
                            'package' => $pkg,
                            'severity' => $info['severity'],
                            'version' => $info['version'] ?? 'unknown',
                        ];
                    }
                }
            }

            return $vulns;
        }

        return [];
    }

    private function scanContainer() {
        echo "🔍 Scanning Docker image...\n";

        // Procurar Dockerfile vulneráveis
        if (file_exists($this->rootPath('Dockerfile'))) {
            $content = file_get_contents($this->rootPath('Dockerfile'));

            $issues = [];

            // Verificar tag latest (bad practice)
            if (preg_match('/FROM.*:latest/i', $content)) {
                $issues[] = ['issue' => 'Usando :latest tag', 'severity' => 'MEDIUM'];
            }

            // Verificar root user
            if (!preg_match('/USER\s+\w+(?!root)/i', $content)) {
                $issues[] = ['issue' => 'Rodando como root', 'severity' => 'HIGH'];
            }

            return $issues;
        }

        return [];
    }

    private function analyzeScan(&$results) {
        $totalCritical = 0;
        $totalHigh = 0;
        $totalMedium = 0;

        // Contar severidades
        foreach ($results['owasp_scan'] as $issue) {
            if ($issue['severity'] === 'CRITICAL') $totalCritical++;
            elseif ($issue['severity'] === 'HIGH') $totalHigh++;
        }

        foreach ($results['secrets_scan'] as $secret) {
            $totalCritical++;
        }

        $results['summary'] = [
            'critical' => $totalCritical,
            'high' => $totalHigh,
            'medium' => $totalMedium,
            'total' => $totalCritical + $totalHigh + $totalMedium,
            'status' => $totalCritical === 0 ? 'PASS' : 'FAIL',
        ];
    }

    private function generateReport(&$results) {
        file_put_contents(
            $this->reportFile,
            json_encode($results, JSON_PRETTY_PRINT)
        );

        echo "\n📊 SECURITY SCAN REPORT\n";
        echo "────────────────────────────\n";
        echo "Critical: " . $results['summary']['critical'] . "\n";
        echo "High: " . $results['summary']['high'] . "\n";
        echo "Total: " . $results['summary']['total'] . "\n";
        echo "Status: " . $results['summary']['status'] . "\n";

        // Alert se crítico
        if ($results['summary']['critical'] > 0) {
            $this->sendAlert(
                '🚨 SECURITY SCAN FAILED',
                "Vulnerabilidades críticas encontradas: {$results['summary']['critical']}\n\n" .
                json_encode($results, JSON_PRETTY_PRINT)
            );
        }
    }

    private function sendAlert($title, $message) {
        mail(
            'fredmourao@gmail.com',
            "[SECURITY] $title",
            $message,
            'From: security@shopvivaliz.com.br'
        );
    }
}

// Executar
$scanner = new SecurityScanner();
$results = $scanner->run();
exit($results['summary']['status'] === 'PASS' ? 0 : 1);
