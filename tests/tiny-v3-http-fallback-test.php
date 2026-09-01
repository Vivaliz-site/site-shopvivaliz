<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/tiny-order-push.php';

function tiny_v3_fallback_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback(204) === false,
    '204 No Content nao pode repetir PUT/POST no fallback Python'
);
tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback(200) === false,
    'qualquer resposta 2xx deve ser tratada como sucesso autoritativo'
);
tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback(401) === true,
    'resposta HTTP de falha deve continuar elegivel ao fallback existente'
);
tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback(0) === true,
    'falha de transporte sem status HTTP deve continuar elegivel ao fallback'
);

fwrite(STDOUT, "COMPROVADO: respostas Tiny/Olist 2xx, inclusive 204 sem corpo, nao sao reenviadas.\n");
