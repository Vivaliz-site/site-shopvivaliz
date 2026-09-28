<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/ai-squad-core.php';

function buscador_reliability_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

buscador_reliability_assert(function_exists('svais_topic_requires_web_search'), 'topic web-search classifier missing');
buscador_reliability_assert(svais_topic_requires_web_search('Ola') === false, 'greeting must not require web sources');
buscador_reliability_assert(svais_topic_requires_web_search('Oi!') === false, 'short greeting must not require web sources');
buscador_reliability_assert(svais_topic_requires_web_search('Pesquise precos atuais de raquetes premium') === true, 'research task must keep live web search');

$partial = svais_round_prompt('teste', 'converge', [
    ['ok'=>true,'provider'=>'openai','phase'=>'research','text'=>'a'],
    ['ok'=>true,'provider'=>'gemini','phase'=>'research','text'=>'b'],
]);
buscador_reliability_assert(str_contains($partial, 'anthropic'), 'partial prompt must name missing provider');
buscador_reliability_assert(str_contains($partial, 'NÃO declare consenso dos três'), 'partial prompt must forbid false three-provider consensus');

$phaseGap = svais_round_prompt('teste', 'converge', [
    ['ok'=>true,'provider'=>'openai','phase'=>'research','text'=>'a'],
    ['ok'=>true,'provider'=>'openai','phase'=>'critique','text'=>'a2'],
    ['ok'=>true,'provider'=>'anthropic','phase'=>'research','text'=>'c'],
    ['ok'=>false,'provider'=>'anthropic','phase'=>'critique','text'=>'erro'],
    ['ok'=>true,'provider'=>'gemini','phase'=>'research','text'=>'g'],
    ['ok'=>true,'provider'=>'gemini','phase'=>'critique','text'=>'g2'],
]);
buscador_reliability_assert(str_contains($phaseGap, 'anthropic/critique'), 'converge prompt must expose missing intermediate phase');

$api = (string)file_get_contents(dirname(__DIR__) . '/api/agent/buscador.php');
$ui = (string)file_get_contents(dirname(__DIR__) . '/admin/buscador.php');
$core = (string)file_get_contents(dirname(__DIR__) . '/includes/ai-squad-core.php');
buscador_reliability_assert(
    preg_match('/function svais_codex_bridge_call\\b[\\s\\S]*?(?=\\nfunction\\s|\\z)/', $core, $codexMatch) === 1,
    'Codex bridge function missing'
);
$codexBody = (string)($codexMatch[0] ?? '');
buscador_reliability_assert(str_contains($codexBody, 'svais_bridge_curl_exec($ch)'), 'Codex bridge must relay heartbeat chunks through the shared curl helper');
buscador_reliability_assert(!str_contains($codexBody, '$body = curl_exec($ch)'), 'Codex bridge must not swallow heartbeat chunks with direct curl_exec');
buscador_reliability_assert(str_contains($api, 'svais_topic_requires_web_search($topic)'), 'API must apply the topic web-search classifier');
buscador_reliability_assert(str_contains($api, "'transport_attempts' => (array)"), 'API must expose safe transport attempt metadata');
buscador_reliability_assert(str_contains($ui, 'fallbackTrail(e)'), 'UI must render explicit fallback trail');

echo "BUSCADOR_RELIABILITY_CONTRACT=PASS\n";
