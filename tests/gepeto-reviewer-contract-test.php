<?php
declare(strict_types=1);

function gepeto_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ui = (string)file_get_contents(dirname(__DIR__) . '/admin/buscador.php');
$api = (string)file_get_contents(dirname(__DIR__) . '/api/agent/buscador.php');

gepeto_assert(str_contains($ui, 'id="gepeto-review"'), 'Buscador UI must expose Gepeto review toggle');
gepeto_assert(str_contains($ui, "e.type==='gepeto_review'"), 'Buscador UI must render Gepeto review events');
gepeto_assert(str_contains($api, "['gepeto_review']"), 'Buscador API must accept Gepeto review flag');
gepeto_assert(str_contains($api, 'svais_gepeto_review_prompt'), 'Buscador API must build Gepeto review prompt');
gepeto_assert(str_contains($api, "'type' => 'gepeto_review'"), 'Buscador API must emit Gepeto review event');

echo "GEPETO_REVIEWER_CONTRACT_TEST=PASS\n";
