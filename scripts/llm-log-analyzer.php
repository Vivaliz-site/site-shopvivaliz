<?php
/**
 * 🧠 LLM Log Analyzer - Auto-fix de erros via Claude/Gemini
 * Coleta logs de erro, analisa com LLM e propõe patches automáticos
 */

class LLMLogAnalyzer {
    private $anthropicKey = '';
    private $geminiKey = '';
    private $openaiKey = '';
    private $anthropicModel = '';
    private $geminiModel = '';
    private $openaiModel = '';
    private $logDirs = [
        '/var/log/',
        '/home/ubuntu/site-shopvivaliz/logs/',
        '/home/ubuntu/site-shopvivaliz/.github/logs/',
    ];
    private $errorThreshold = 3; // Alertar se 3+ erros similares

    public function __construct() {
        $this->anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
        $this->geminiKey = getenv('GEMINI_API_KEY') ?: '';
        $this->openaiKey = getenv('OPENAI_API_KEY') ?: '';
        $this->anthropicModel = getenv('ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001';
        $this->geminiModel = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash';
        $this->openaiModel = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
    }

    public function run() {
        echo "🔍 Iniciando LLM Log Analyzer...\n";

        // 1. Coletar logs de erro
        $errors = $this->collectErrorLogs();

        if (empty($errors)) {
            echo "✅ Nenhum erro detectado nos logs\n";
            return true;
        }

        echo "⚠️ {$errors['count']} erros encontrados\n";

        // 2. Agrupar erros similares
        $grouped = $this->groupSimilarErrors($errors['list']);

        // 3. Analisar com LLM
        foreach ($grouped as $errorGroup) {
            $analysis = $this->analyzeWithLLM($errorGroup);

            if ($analysis && $analysis['severity'] >= 'medium') {
                // 4. Propor patch
                $patch = $this->generatePatch($analysis);

                if ($patch) {
                    echo "✅ Patch gerado para: {$analysis['error_type']}\n";
                    $this->applyPatchAndCommit($patch, $analysis);
                }
            }
        }

        return true;
    }

    private function collectErrorLogs() {
        $errors = [];
        $count = 0;

        foreach ($this->logDirs as $dir) {
            if (!is_dir($dir)) continue;

            $logFiles = glob($dir . '*.log');
            foreach ($logFiles as $file) {
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                $recentLines = array_slice($lines, -100); // Últimas 100 linhas

                foreach ($recentLines as $line) {
                    if (preg_match('/ERROR|CRITICAL|FATAL|Exception/i', $line)) {
                        $errors[] = [
                            'file' => $file,
                            'line' => $line,
                            'timestamp' => time(),
                        ];
                        $count++;
                    }
                }
            }
        }

        return ['list' => $errors, 'count' => $count];
    }

    private function groupSimilarErrors($errors) {
        $grouped = [];

        foreach ($errors as $error) {
            // Extrair padrão do erro
            preg_match('/ERROR.*?:\s*(.+?)(?:\(|$)/i', $error['line'], $matches);
            $errorType = $matches[1] ?? substr($error['line'], 0, 50);

            $key = md5($errorType);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'error_type' => $errorType,
                    'count' => 0,
                    'examples' => [],
                    'file' => $error['file'],
                ];
            }

            $grouped[$key]['count']++;
            if (count($grouped[$key]['examples']) < 3) {
                $grouped[$key]['examples'][] = $error['line'];
            }
        }

        return array_filter($grouped, fn($g) => $g['count'] >= $this->errorThreshold);
    }

    private function analyzeWithLLM($errorGroup) {
        echo "🤖 Analisando com LLM: {$errorGroup['error_type']}\n";

        $prompt = "Analise este erro e sugira uma correção:\n\n" .
                  "Erro: {$errorGroup['error_type']}\n" .
                  "Ocorrências: {$errorGroup['count']}\n\n" .
                  "Exemplos:\n" .
                  implode("\n", $errorGroup['examples']) . "\n\n" .
                  "Responda em JSON:\n" .
                  '{"severity":"low|medium|high","root_cause":"...","fix":"...","file_path":"...","line_number":0}';

        // Tentar OpenAI mini primeiro para manter o analisador 24/7 economico.
        $response = $this->callOpenAIAPI($prompt);

        if (!$response) {
            // Fallback para Gemini
            $response = $this->callGeminiAPI($prompt);
        }

        if (!$response) {
            // Fallback para Claude Haiku
            $response = $this->callClaudeAPI($prompt);
        }

        if ($response) {
            return json_decode($response, true);
        }

        return null;
    }

    private function callClaudeAPI($prompt) {
        if (!$this->anthropicKey) return null;

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->anthropicModel,
                'max_tokens' => 512,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->anthropicKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['content'][0]['text'] ?? null;
        }

        return null;
    }

    private function callGeminiAPI($prompt) {
        if (!$this->geminiKey) return null;

        $model = rawurlencode($this->geminiModel);
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_URL => "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $this->geminiKey,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        }

        return null;
    }

    private function callOpenAIAPI($prompt) {
        if (!$this->openaiKey) return null;

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->openaiModel,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['choices'][0]['message']['content'] ?? null;
        }

        return null;
    }

    private function generatePatch($analysis) {
        if (!isset($analysis['file_path']) || !isset($analysis['fix'])) {
            return null;
        }

        $filePath = $analysis['file_path'];
        $fix = $analysis['fix'];
        $lineNumber = $analysis['line_number'] ?? 0;

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        if ($lineNumber > 0 && $lineNumber <= count($lines)) {
            // Aplicar patch específico de linha
            $lines[$lineNumber - 1] = $fix;
            $newContent = implode("\n", $lines);
        } else {
            // Aplicar patch global (buscar e substituir)
            $newContent = str_replace(
                $analysis['error_type'] ?? '',
                $fix,
                $content
            );
        }

        return [
            'file_path' => $filePath,
            'original_content' => $content,
            'new_content' => $newContent,
            'analysis' => $analysis,
        ];
    }

    private function applyPatchAndCommit($patch, $analysis) {
        file_put_contents($patch['file_path'], $patch['new_content']);

        // Commit automático
        $message = "fix: auto-resolved {$analysis['error_type']} via LLM analyzer\n\n" .
                   "Root cause: {$analysis['root_cause']}\n" .
                   "Severity: {$analysis['severity']}\n" .
                   "File: {$patch['file_path']}";

        shell_exec("cd /home/ubuntu/site-shopvivaliz && git add {$patch['file_path']} 2>/dev/null");
        shell_exec("cd /home/ubuntu/site-shopvivaliz && git commit -m '{$message}' 2>/dev/null");
        shell_exec("cd /home/ubuntu/site-shopvivaliz && git push origin main 2>/dev/null");

        echo "✅ Patch aplicado e commitado: {$patch['file_path']}\n";
    }
}

// Executar
$analyzer = new LLMLogAnalyzer();
exit($analyzer->run() ? 0 : 1);
