<?php
declare(strict_types=1);

/**
 * ShopVivaliz AI Squad core.
 *
 * Three-provider research/debate engine:
 * - OpenAI Responses API
 * - Anthropic Messages API
 * - Google Gemini GenerateContent API
 *
 * Secrets are read only from the protected runtime environment. Never include
 * credential values in logs, exceptions returned to clients, or persisted data.
 */

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

function svais_profile_catalog(): array
{
    return [
        'deep_research' => [
            'label' => 'Pesquisa profunda',
            'rounds' => 2,
            'web_search' => true,
            'openai' => [
                'model' => getenv('AI_SQUAD_OPENAI_MODEL') ?: 'gpt-5.6-sol',
                'effort' => 'xhigh',
                'max_output_tokens' => 7000,
            ],
            'anthropic' => [
                'model' => getenv('AI_SQUAD_ANTHROPIC_MODEL') ?: 'claude-opus-5',
                'effort' => 'xhigh',
                'max_tokens' => 7000,
                'web_search_max_uses' => 10,
            ],
            'gemini' => [
                'model' => getenv('AI_SQUAD_GEMINI_MODEL') ?: 'gemini-3.1-pro-preview',
                'thinking_level' => 'HIGH',
                'max_output_tokens' => 7000,
            ],
        ],
        'balanced' => [
            'label' => 'Equilibrado',
            'rounds' => 2,
            'web_search' => true,
            'openai' => [
                'model' => getenv('AI_SQUAD_OPENAI_BALANCED_MODEL') ?: 'gpt-5.6-terra',
                'effort' => 'high',
                'max_output_tokens' => 4500,
            ],
            'anthropic' => [
                'model' => getenv('AI_SQUAD_ANTHROPIC_BALANCED_MODEL') ?: 'claude-sonnet-5',
                'effort' => 'high',
                'max_tokens' => 4500,
                'web_search_max_uses' => 6,
            ],
            'gemini' => [
                'model' => getenv('AI_SQUAD_GEMINI_BALANCED_MODEL') ?: 'gemini-3.5-flash',
                'thinking_level' => 'MEDIUM',
                'max_output_tokens' => 4500,
            ],
        ],
        'fast' => [
            'label' => 'Rápido',
            'rounds' => 1,
            'web_search' => false,
            'openai' => [
                'model' => getenv('AI_SQUAD_OPENAI_FAST_MODEL') ?: 'gpt-5.6-luna',
                'effort' => 'medium',
                'max_output_tokens' => 2500,
            ],
            'anthropic' => [
                'model' => getenv('AI_SQUAD_ANTHROPIC_FAST_MODEL') ?: 'claude-haiku-4-5-20251001',
                'effort' => 'low',
                'max_tokens' => 2500,
                'web_search_max_uses' => 0,
            ],
            'gemini' => [
                'model' => getenv('AI_SQUAD_GEMINI_FAST_MODEL') ?: 'gemini-3.5-flash',
                'thinking_level' => 'LOW',
                'max_output_tokens' => 2500,
            ],
        ],
    ];
}

function svais_profile(string $name): array
{
    $catalog = svais_profile_catalog();
    return $catalog[$name] ?? $catalog['deep_research'];
}

function svais_provider_state(array $profile): array
{
    return [
        'openai' => [
            'configured' => trim((string)(getenv('OPENAI_API_KEY') ?: '')) !== '',
            'model' => (string)$profile['openai']['model'],
            'reasoning' => (string)$profile['openai']['effort'],
        ],
        'anthropic' => [
            'configured' => trim((string)(getenv('ANTHROPIC_API_KEY') ?: '')) !== '',
            'model' => (string)$profile['anthropic']['model'],
            'reasoning' => (string)$profile['anthropic']['effort'],
        ],
        'gemini' => [
            'configured' => trim((string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '')) !== '',
            'model' => (string)$profile['gemini']['model'],
            'reasoning' => strtolower((string)$profile['gemini']['thinking_level']),
        ],
    ];
}

function svais_safe_error(Throwable $e): string
{
    $message = preg_replace('/(sk-[A-Za-z0-9_-]+|AIza[A-Za-z0-9_-]+|Bearer\s+\S+)/i', '[redacted]', $e->getMessage());
    return mb_substr((string)$message, 0, 500, 'UTF-8');
}

function svais_http_json(string $url, array $headers, array $payload, int $timeout = 180): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init_failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('provider_transport_error');
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('provider_invalid_json');
    }

    if ($status < 200 || $status >= 300) {
        $providerMessage = '';
        if (is_string($decoded['error']['message'] ?? null)) {
            $providerMessage = (string)$decoded['error']['message'];
        } elseif (is_string($decoded['message'] ?? null)) {
            $providerMessage = (string)$decoded['message'];
        }
        throw new RuntimeException('provider_http_' . $status . ($providerMessage !== '' ? ': ' . $providerMessage : ''));
    }

    return $decoded;
}

function svais_collect_urls(mixed $value, array &$urls): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (($key === 'url' || $key === 'uri') && is_string($item) && preg_match('#^https?://#i', $item)) {
                $urls[$item] = true;
            } else {
                svais_collect_urls($item, $urls);
            }
        }
    }
}

function svais_text_from_openai(array $data): string
{
    if (is_string($data['output_text'] ?? null) && trim((string)$data['output_text']) !== '') {
        return trim((string)$data['output_text']);
    }

    $parts = [];
    foreach (($data['output'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) continue;
            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                $parts[] = (string)$content['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

function svais_text_from_anthropic(array $data): string
{
    $parts = [];
    foreach (($data['content'] ?? []) as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
            $parts[] = (string)$block['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function svais_text_from_gemini(array $data): string
{
    $parts = [];
    foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (is_array($part) && is_string($part['text'] ?? null)) {
            $parts[] = (string)$part['text'];
        }
    }
    return trim(implode("\n", $parts));
}

function svais_base_system(string $provider, string $phase): string
{
    $providerName = match ($provider) {
        'openai' => 'OpenAI',
        'anthropic' => 'Claude',
        'gemini' => 'Gemini',
        default => $provider,
    };

    $phaseInstruction = match ($phase) {
        'research' => 'Trabalhe de forma independente. Pesquise antes de concluir quando a resposta depender de fatos atuais, disponibilidade, preço, regras, versões ou qualquer dado que possa ter mudado.',
        'critique' => 'Faça contraditório real. Verifique as evidências dos outros dois agentes, identifique falso consenso, dados desatualizados, links fracos e conclusões sem suporte. Corrija com pesquisa própria quando necessário.',
        'converge' => 'Busque convergência. Depois de revisar toda a discussão, declare a proposta final que você considera sustentável pelas evidências e explicite qualquer divergência que ainda importe.',
        'moderate' => 'Atue apenas como moderador. Sintetize o que os três agentes realmente sustentam em comum e preserve divergências materiais. Não invente consenso.',
        default => 'Raciocine cuidadosamente e baseie conclusões em evidências.',
    };

    return "Você é {$providerName}, um dos três pesquisadores independentes do AI Squad ShopVivaliz. "
        . $phaseInstruction . "\n"
        . "Regras: priorize fontes primárias e páginas do produto/serviço; informe incerteza; nunca invente preço, estoque, modelo, data, desconto ou disponibilidade; "
        . "quando usar a web, inclua URLs ou referências verificáveis no texto final. "
        . "Não revele raciocínio privado nem chain-of-thought: entregue apenas conclusões, evidências, checagens e justificativas resumidas. "
        . "Responda em português do Brasil.";
}

function svais_openai_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('OPENAI_API_KEY_not_configured');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'instructions' => $system,
        'input' => $prompt,
        'reasoning' => ['effort' => (string)$cfg['effort']],
        'max_output_tokens' => (int)$cfg['max_output_tokens'],
    ];
    if ($webSearch) {
        $payload['tools'] = [['type' => 'web_search']];
        $payload['tool_choice'] = 'auto';
    }

    $data = svais_http_json('https://api.openai.com/v1/responses', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], $payload, 240);

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_openai($data),
        'sources' => array_keys($urls),
        'usage' => $data['usage'] ?? [],
        'model' => (string)($data['model'] ?? $cfg['model']),
    ];
}

function svais_anthropic_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('ANTHROPIC_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('ANTHROPIC_API_KEY_not_configured');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'max_tokens' => (int)$cfg['max_tokens'],
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => (string)$cfg['effort']],
    ];
    if ($webSearch && (int)$cfg['web_search_max_uses'] > 0) {
        $payload['tools'] = [[
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => (int)$cfg['web_search_max_uses'],
            'user_location' => [
                'type' => 'approximate',
                'country' => 'BR',
                'timezone' => 'America/Sao_Paulo',
            ],
        ]];
    }

    $data = svais_http_json('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], $payload, 240);

    if (($data['stop_reason'] ?? '') === 'pause_turn') {
        $payload['messages'][] = ['role' => 'assistant', 'content' => $data['content'] ?? []];
        $data = svais_http_json('https://api.anthropic.com/v1/messages', [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], $payload, 240);
    }

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_anthropic($data),
        'sources' => array_keys($urls),
        'usage' => $data['usage'] ?? [],
        'model' => (string)($data['model'] ?? $cfg['model']),
    ];
}

function svais_gemini_call(array $cfg, string $system, string $prompt, bool $webSearch): array
{
    $key = trim((string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('GEMINI_API_KEY_not_configured');
    }

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => (int)$cfg['max_output_tokens'],
            'thinkingConfig' => ['thinkingLevel' => (string)$cfg['thinking_level']],
        ],
    ];
    if ($webSearch) {
        $payload['tools'] = [['googleSearch' => new stdClass()]];
    }

    $model = rawurlencode((string)$cfg['model']);
    $data = svais_http_json(
        'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
        [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
        ],
        $payload,
        240
    );

    $urls = [];
    svais_collect_urls($data, $urls);
    return [
        'text' => svais_text_from_gemini($data),
        'sources' => array_keys($urls),
        'usage' => $data['usageMetadata'] ?? [],
        'model' => (string)$cfg['model'],
    ];
}

function svais_call_provider(string $provider, array $profile, string $phase, string $prompt): array
{
    $webSearch = (bool)($profile['web_search'] ?? false);
    $system = svais_base_system($provider, $phase);

    $started = microtime(true);
    $result = match ($provider) {
        'openai' => svais_openai_call($profile['openai'], $system, $prompt, $webSearch),
        'anthropic' => svais_anthropic_call($profile['anthropic'], $system, $prompt, $webSearch),
        'gemini' => svais_gemini_call($profile['gemini'], $system, $prompt, $webSearch),
        default => throw new InvalidArgumentException('unknown_provider'),
    };

    if (trim((string)($result['text'] ?? '')) === '') {
        throw new RuntimeException('provider_empty_response');
    }

    $result['latency_ms'] = (int)round((microtime(true) - $started) * 1000);
    return $result;
}

function svais_transcript_text(array $entries): string
{
    $chunks = [];
    foreach ($entries as $entry) {
        if (!is_array($entry) || ($entry['ok'] ?? false) !== true) continue;
        $label = strtoupper((string)($entry['provider'] ?? 'agent'));
        $phase = (string)($entry['phase'] ?? '');
        $text = trim((string)($entry['text'] ?? ''));
        if ($text === '') continue;
        $chunks[] = "### {$label} ({$phase})\n{$text}";
    }
    return implode("\n\n", $chunks);
}

function svais_round_prompt(string $topic, string $phase, array $transcript): string
{
    if ($phase === 'research') {
        return "TAREFA DO USUÁRIO:\n{$topic}\n\n"
            . "Faça sua pesquisa independente. Para fatos que podem ter mudado, valide ao vivo. "
            . "Diferencie fatos confirmados, inferências e pontos ainda não verificados.";
    }

    $history = svais_transcript_text($transcript);
    if ($phase === 'critique') {
        return "TAREFA ORIGINAL:\n{$topic}\n\n"
            . "RESULTADOS DOS TRÊS PESQUISADORES:\n{$history}\n\n"
            . "Faça a revisão contraditória. Confirme ou derrube as afirmações materiais com evidência. "
            . "Aponte explicitamente onde concorda, discorda e o que precisa ser corrigido.";
    }

    if ($phase === 'converge') {
        return "TAREFA ORIGINAL:\n{$topic}\n\n"
            . "DEBATE COMPLETO ATÉ AQUI:\n{$history}\n\n"
            . "Formule sua posição final depois do contraditório. Proponha a conclusão comum mais defensável, "
            . "mas não esconda divergências relevantes.";
    }

    return "TAREFA ORIGINAL:\n{$topic}\n\nTRANSCRIÇÃO:\n{$history}";
}

function svais_consensus_prompt(string $topic, array $transcript): string
{
    $history = svais_transcript_text($transcript);
    return "TAREFA ORIGINAL:\n{$topic}\n\n"
        . "TRANSCRIÇÃO DOS TRÊS AGENTES:\n{$history}\n\n"
        . "Produza a SÍNTESE DE CONSENSO. Separe: (1) consenso confirmado pelos três, "
        . "(2) divergências que permanecem, (3) conclusão final sustentada pelas evidências. "
        . "Quando a tarefa pedir ranking ou escolhas, só apresente uma lista final se houver suporte na discussão; "
        . "explique brevemente por que cada item sobreviveu ao contraditório.";
}
