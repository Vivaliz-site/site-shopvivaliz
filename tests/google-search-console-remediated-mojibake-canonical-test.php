<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/google-search-console-issue-classifier.php';

$issues = gsc_classify_index_issues([
    'verdict' => 'NEUTRAL',
    'indexingState' => 'INDEXING_ALLOWED',
    'pageFetchState' => 'SUCCESSFUL',
    'robotsTxtState' => 'ALLOWED',
    'googleCanonical' => 'https://www.shopvivaliz.com.br/produto/massa-f12-para-calafetar-madeira-400g-mogno-viapol-411',
    'userCanonical' => 'https://shopvivaliz.com.br/produto/massa-f12-de-calafetar-e-corre%C3%A7%C3%A3o-madeira-viapol-400g-mogno-v0210691',
]);

if ($issues !== []) {
    fwrite(STDERR, 'Expected remediated canonical to be clean, got: ' . json_encode($issues) . "\n");
    exit(1);
}

fwrite(STDOUT, "google-search-console-remediated-mojibake-canonical-test: ok\n");
