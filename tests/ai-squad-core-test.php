<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/ai-squad-core.php';

function ais_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$catalog = svais_profile_catalog();
ais_assert(isset($catalog['deep_research'], $catalog['balanced'], $catalog['fast']), 'expected profiles missing');

$deep = $catalog['deep_research'];
ais_assert(($deep['openai']['model'] ?? '') === (getenv('AI_SQUAD_OPENAI_MODEL') ?: 'gpt-5.6-sol'), 'deep OpenAI model mismatch');
ais_assert(($deep['openai']['effort'] ?? '') === 'xhigh', 'deep OpenAI effort must be xhigh');
ais_assert(($deep['anthropic']['model'] ?? '') === (getenv('AI_SQUAD_ANTHROPIC_MODEL') ?: 'claude-opus-5'), 'deep Anthropic model mismatch');
ais_assert(($deep['anthropic']['effort'] ?? '') === 'xhigh', 'deep Anthropic effort must be xhigh');
ais_assert(($deep['gemini']['model'] ?? '') === (getenv('AI_SQUAD_GEMINI_MODEL') ?: 'gemini-3.1-pro-preview'), 'deep Gemini model mismatch');
ais_assert(($deep['gemini']['thinking_level'] ?? '') === 'HIGH', 'deep Gemini thinking must be HIGH');

$serialized = strtolower(json_encode($catalog, JSON_UNESCAPED_SLASHES) ?: '');
ais_assert(!str_contains($serialized, 'fable'), 'Fable must not appear in any AI Squad preset');

$openaiFixture = [
    'output' => [[
        'type' => 'message',
        'content' => [[
            'type' => 'output_text',
            'text' => 'openai-ok',
            'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com/a']],
        ]],
    ]],
];
ais_assert(svais_text_from_openai($openaiFixture) === 'openai-ok', 'OpenAI parser failed');

$anthropicFixture = [
    'content' => [[
        'type' => 'text',
        'text' => 'claude-ok',
        'citations' => [['type' => 'web_search_result_location', 'url' => 'https://example.com/b']],
    ]],
];
ais_assert(svais_text_from_anthropic($anthropicFixture) === 'claude-ok', 'Anthropic parser failed');

$geminiFixture = [
    'candidates' => [[
        'content' => ['parts' => [['text' => 'gemini-ok']]],
        'groundingMetadata' => [
            'groundingChunks' => [['web' => ['uri' => 'https://example.com/c']]],
        ],
    ]],
];
ais_assert(svais_text_from_gemini($geminiFixture) === 'gemini-ok', 'Gemini parser failed');

$urls = [];
svais_collect_urls([$openaiFixture, $anthropicFixture, $geminiFixture], $urls);
ais_assert(isset($urls['https://example.com/a']), 'OpenAI source URL not collected');
ais_assert(isset($urls['https://example.com/b']), 'Anthropic source URL not collected');
ais_assert(isset($urls['https://example.com/c']), 'Gemini source URL not collected');

$prompt = svais_round_prompt('teste', 'critique', [[
    'ok' => true,
    'provider' => 'openai',
    'phase' => 'research',
    'text' => 'achado',
]]);
ais_assert(str_contains($prompt, 'TAREFA ORIGINAL'), 'critique prompt missing original task');
ais_assert(str_contains($prompt, 'OPENAI'), 'critique prompt missing peer transcript');

$consensus = svais_consensus_prompt('teste', [[
    'ok' => true,
    'provider' => 'gemini',
    'phase' => 'converge',
    'text' => 'posição final',
]]);
ais_assert(str_contains($consensus, 'SÍNTESE DE CONSENSO'), 'consensus prompt contract missing');

echo "AI_SQUAD_CORE_TEST=PASS\n";
