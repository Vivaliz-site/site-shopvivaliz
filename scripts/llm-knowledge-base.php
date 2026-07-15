<?php
/**
 * 🎓 LLM Knowledge Base - Auto-Learning do LLM Analyzer
 * Armazena soluções bem-sucedidas e reutiliza em erros similares
 */

class LLMKnowledgeBase {
    private $kbFile = '.llm-knowledge-base.json';
    private $feedbackFile = '.llm-feedback.json';
    private $minSimilarityThreshold = 0.75; // 75% similar = usar solução anterior

    public function __construct() {
        if (!file_exists($this->kbFile)) {
            file_put_contents($this->kbFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function findSolution($errorType, $errorMessage) {
        echo "🔍 Procurando solução no Knowledge Base...\n";

        $kb = $this->readKB();

        foreach ($kb as $entry) {
            $similarity = $this->calculateSimilarity($errorType, $entry['error_type']);

            if ($similarity >= $this->minSimilarityThreshold) {
                // Verificar se solução anterior funcionou
                if ($entry['success_rate'] >= 0.80) {
                    echo "✅ Solução encontrada no KB (similarity: " . round($similarity * 100) . "%)\n";
                    echo "   Taxa de sucesso: {$entry['success_rate']}%\n";

                    return [
                        'found' => true,
                        'solution' => $entry['solution'],
                        'source' => 'knowledge_base',
                        'confidence' => round($similarity * 100),
                    ];
                }
            }
        }

        echo "❌ Nenhuma solução no KB. Usando LLM...\n";
        return ['found' => false];
    }

    public function storeSolution($errorType, $errorMessage, $solution, $wasSuccessful) {
        echo "💾 Armazenando solução no KB\n";

        $kb = $this->readKB();

        // Procurar entrada similar
        $existingEntry = null;
        foreach ($kb as &$entry) {
            if ($this->calculateSimilarity($errorType, $entry['error_type']) > 0.9) {
                $existingEntry = &$entry;
                break;
            }
        }

        if ($existingEntry) {
            // Atualizar entrada existente
            $existingEntry['occurrences']++;

            if ($wasSuccessful) {
                $existingEntry['successful_fixes']++;
            }

            $existingEntry['success_rate'] = round(
                ($existingEntry['successful_fixes'] / $existingEntry['occurrences']) * 100,
                2
            );

            echo "✅ Entrada atualizada. Taxa de sucesso: {$existingEntry['success_rate']}%\n";
        } else {
            // Criar nova entrada
            $kb[] = [
                'error_type' => $errorType,
                'error_message' => $errorMessage,
                'solution' => $solution,
                'occurrences' => 1,
                'successful_fixes' => $wasSuccessful ? 1 : 0,
                'success_rate' => $wasSuccessful ? 100 : 0,
                'first_seen' => date('Y-m-d H:i:s'),
                'last_seen' => date('Y-m-d H:i:s'),
                'learned_from_llm' => true,
            ];

            echo "✅ Novo conhecimento armazenado\n";
        }

        file_put_contents($this->kbFile, json_encode($kb, JSON_PRETTY_PRINT));
    }

    public function recordFeedback($solutionId, $feedback) {
        echo "📝 Registrando feedback do usuário...\n";

        $feedbackData = [
            'solution_id' => $solutionId,
            'feedback' => $feedback, // 'correct', 'incorrect', 'partial'
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $feedbacks = json_decode(file_get_contents($this->feedbackFile) ?: '[]', true);
        $feedbacks[] = $feedbackData;

        file_put_contents($this->feedbackFile, json_encode($feedbacks, JSON_PRETTY_PRINT));

        // Processar feedback
        $this->processFeedback($solutionId, $feedback);
    }

    private function processFeedback($solutionId, $feedback) {
        $kb = $this->readKB();

        // Encontrar entrada
        foreach ($kb as &$entry) {
            if (md5(json_encode($entry)) === $solutionId) {
                if ($feedback === 'correct') {
                    $entry['successful_fixes']++;
                } elseif ($feedback === 'incorrect') {
                    $entry['failed_fixes'] = ($entry['failed_fixes'] ?? 0) + 1;
                }

                $total = $entry['successful_fixes'] + ($entry['failed_fixes'] ?? 0);
                $entry['success_rate'] = round(($entry['successful_fixes'] / $total) * 100, 2);

                echo "📊 Taxa atualizada: {$entry['success_rate']}%\n";
                break;
            }
        }

        file_put_contents($this->kbFile, json_encode($kb, JSON_PRETTY_PRINT));
    }

    public function getStatistics() {
        echo "\n📊 ESTATÍSTICAS DO KNOWLEDGE BASE\n";

        $kb = $this->readKB();

        $stats = [
            'total_entries' => count($kb),
            'avg_success_rate' => 0,
            'most_common_errors' => [],
            'most_reliable_solutions' => [],
        ];

        $successRates = [];
        $errorCounts = [];

        foreach ($kb as $entry) {
            $successRates[] = $entry['success_rate'];
            $errorCounts[$entry['error_type']] = ($errorCounts[$entry['error_type']] ?? 0) + 1;
        }

        $stats['avg_success_rate'] = round(array_sum($successRates) / count($successRates), 2);

        // Erros mais comuns
        arsort($errorCounts);
        $stats['most_common_errors'] = array_slice($errorCounts, 0, 5);

        // Soluções mais confiáveis
        usort($kb, fn($a, $b) => $b['success_rate'] - $a['success_rate']);
        $stats['most_reliable_solutions'] = array_slice($kb, 0, 5);

        echo "Total de entradas: {$stats['total_entries']}\n";
        echo "Taxa de sucesso média: {$stats['avg_success_rate']}%\n";
        echo "\nErros mais comuns:\n";
        foreach ($stats['most_common_errors'] as $error => $count) {
            echo "  - {$error}: {$count}x\n";
        }

        echo "\nSoluções mais confiáveis (>90%):\n";
        foreach ($stats['most_reliable_solutions'] as $entry) {
            if ($entry['success_rate'] >= 90) {
                echo "  - {$entry['error_type']}: {$entry['success_rate']}%\n";
            }
        }

        return $stats;
    }

    private function calculateSimilarity($str1, $str2) {
        // Usar algoritmo de Levenshtein para calcular similaridade
        $distance = levenshtein(strtolower($str1), strtolower($str2));
        $maxLen = max(strlen($str1), strlen($str2));

        if ($maxLen === 0) return 1.0;

        return 1 - ($distance / $maxLen);
    }

    private function readKB() {
        return json_decode(file_get_contents($this->kbFile), true) ?: [];
    }

    public function exportKB() {
        echo "📤 Exportando Knowledge Base...\n";

        $kb = $this->readKB();
        $exportFile = 'llm-knowledge-base-export-' . date('Y-m-d') . '.json';

        file_put_contents($exportFile, json_encode($kb, JSON_PRETTY_PRINT));

        echo "✅ Exportado para: {$exportFile}\n";

        return $exportFile;
    }

    public function importKB($file) {
        echo "📥 Importando Knowledge Base...\n";

        if (!file_exists($file)) {
            echo "❌ Arquivo não encontrado: {$file}\n";
            return false;
        }

        $imported = json_decode(file_get_contents($file), true);
        $existing = $this->readKB();

        // Merge
        foreach ($imported as $entry) {
            $found = false;
            foreach ($existing as &$e) {
                if ($e['error_type'] === $entry['error_type']) {
                    // Manter entrada com maior taxa de sucesso
                    if ($entry['success_rate'] > $e['success_rate']) {
                        $e = $entry;
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $existing[] = $entry;
            }
        }

        file_put_contents($this->kbFile, json_encode($existing, JSON_PRETTY_PRINT));

        echo "✅ Importado com sucesso\n";

        return true;
    }
}

// Testes
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $kb = new LLMKnowledgeBase();

    if ($argv[1] === 'stats') {
        $kb->getStatistics();
    } elseif ($argv[1] === 'export') {
        $kb->exportKB();
    } elseif ($argv[1] === 'import' && isset($argv[2])) {
        $kb->importKB($argv[2]);
    }
}
