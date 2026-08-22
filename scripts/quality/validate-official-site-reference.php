<?php
declare(strict_types=1);
require_once __DIR__ . '/official-site-reference-http-policy.php';
$root=dirname(__DIR__,2);
$errors=[];
$configPath=$root.'/config/official-site.php';
$apiPath=$root.'/api/site/official-reference.php';
$docPath=$root.'/docs/knowledge/official-site.md';
foreach([$configPath,$apiPath,$docPath] as $path){if(!is_file($path))$errors[]='missing: '.str_replace($root.'/','',$path);elseif(filesize($path)===0)$errors[]='empty: '.str_replace($root.'/','',$path);}
$config=is_file($configPath)?require $configPath:[];
if(!is_array($config))$errors[]='official site config invalid';
if(($config['base_url']??'')!=='https://shopvivaliz.com.br')$errors[]='official base_url mismatch';
foreach(['terms','about','help','contact'] as $key){if(trim((string)($config['navigation'][$key]??''))==='')$errors[]="missing navigation: $key";}
if(count($config['payment_methods']??[])<5)$errors[]='payment methods incomplete';
if(count($config['top_categories']??[])<6)$errors[]='top categories incomplete';

// Rodada 6 (2026-08-19): antes este script so checava que as chaves de
// navigation nao estavam vazias -- qualquer string passava, mesmo uma rota
// 404. R6-5 encontrou 3 das 4 rotas quebradas em producao sem que este
// validador (que existe com o nome exato desse problema) nunca acusasse
// nada, porque tambem nunca rodava no CI. Agora faz HEAD real contra cada
// URL de navigation. 404/410 e falhas 5xx continuam bloqueantes; respostas
// 401/403/429 de WAF/rate-limit sao inconclusivas e viram warning, assim como
// indisponibilidade de rede. Isso evita confundir politica do edge contra o
// runner com rota quebrada. Ver R6-5 no relatorio da Rodada 6.
$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
if ($baseUrl !== '' && is_array($config['navigation'] ?? null)) {
    foreach ($config['navigation'] as $key => $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }
        $url = $baseUrl . $path;
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "User-Agent: Mozilla/5.0 ShopVivaliz-CI-Validator
Accept: text/html,*/*;q=0.8
",
            ],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($result === false || $statusLine === '') {
            fwrite(STDERR, "warning: nao foi possivel checar $url (rede indisponivel neste runner?)\n");
            continue;
        }
        if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
            $status = (int)$m[1];
            if (sv_official_site_http_status_is_inconclusive($status)) {
                fwrite(STDERR, "warning: navigation.$key devolveu HTTP $status em $url; acesso do runner inconclusivo (WAF/rate-limit)\n");
                continue;
            }
            if (sv_official_site_http_status_is_blocking($status)) {
                $errors[] = "navigation.$key aponta para $url, que devolveu HTTP $status";
            }
        }
    }
}

if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Official site reference validation passed.\n";
