<?php
/**
 * API Dashboard Auditoria 24/7
 * Retorna dados de monitoramento em tempo real
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

$reportsDir = dirname(__DIR__, 2) . '/logs/reports';
$logsDir = dirname(__DIR__, 2) . '/logs';

function obter_ultimo_relatorio(): ?array
{
    global $reportsDir;

    if (!is_dir($reportsDir)) {
        return null;
    }

    $files = array_diff(scandir($reportsDir, SCANDIR_SORT_DESCENDING) ?: [], ['.', '..']);

    if (empty($files)) {
        return null;
    }

    $ultimoArquivo = $reportsDir . '/' . reset($files);

    if (!is_file($ultimoArquivo)) {
        return null;
    }

    $conteudo = file_get_contents($ultimoArquivo);
    return json_decode($conteudo, true);
}

function obter_historico(int $limite = 10): array
{
    global $reportsDir;

    if (!is_dir($reportsDir)) {
        return [];
    }

    $files = array_diff(scandir($reportsDir, SCANDIR_SORT_DESCENDING) ?: [], ['.', '..']);
    $historico = [];

    $contador = 0;
    foreach ($files as $arquivo) {
        if ($contador >= $limite) {
            break;
        }

        $caminho = $reportsDir . '/' . $arquivo;
        if (!is_file($caminho)) {
            continue;
        }

        $conteudo = file_get_contents($caminho);
        $dados = json_decode($conteudo, true);

        if ($dados) {
            $historico[] = [
                'timestamp' => $dados['timestamp'] ?? 'N/A',
                'taxa_sucesso' => $dados['taxa_sucesso'] ?? '0%',
                'status' => $dados['status_geral'] ?? '🔴 DESCONHECIDO',
                'ok' => ($dados['sucessos'] ?? 0) >= ($dados['total_testes'] ?? 1) * 0.8
            ];
            $contador++;
        }
    }

    return $historico;
}

function calcular_uptime(): string
{
    global $reportsDir;

    if (!is_dir($reportsDir)) {
        return '0%';
    }

    $files = array_diff(scandir($reportsDir) ?: [], ['.', '..']);

    if (empty($files)) {
        return '0%';
    }

    $ok = 0;
    $total = 0;

    foreach ($files as $arquivo) {
        $caminho = $reportsDir . '/' . $arquivo;
        if (!is_file($caminho)) {
            continue;
        }

        $conteudo = file_get_contents($caminho);
        $dados = json_decode($conteudo, true);

        if ($dados) {
            $total++;
            $taxa = floatval(str_replace('%', '', $dados['taxa_sucesso'] ?? '0'));
            if ($taxa >= 95) {
                $ok++;
            }
        }
    }

    if ($total === 0) {
        return '0%';
    }

    $percentual = ($ok / $total) * 100;
    return number_format($percentual, 1) . '%';
}

try {
    $ultimo_relatorio = obter_ultimo_relatorio();
    $historico = obter_historico(10);

    $resposta = [
        'status' => 'success',
        'timestamp' => date('c'),
        'ultimo_relatorio' => $ultimo_relatorio,
        'historico' => $historico,
        'uptime' => calcular_uptime(),
        'total_execucoes' => count($historico)
    ];

    if (!$ultimo_relatorio) {
        $resposta['aviso'] = 'Ainda não há relatórios. A primeira execução ocorrerá em breve.';
    }

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Erro ao carregar dados de auditoria: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
