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

foreach ([200, 204, 400, 401, 409, 429, 500] as $status) {
    tiny_v3_fallback_assert(
        svtop_tiny_should_use_python_fallback('', 0, '', $status) === false,
        "HTTP {$status} e autoritativo e nao pode acionar fallback"
    );
}

tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback(false, 7, 'connection_failed', 0) === true,
    'falha de transporte sem resposta HTTP deve acionar fallback'
);
tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback('', 0, '', 0) === false,
    'status zero sem evidencia de falha de transporte nao deve repetir a chamada'
);
tiny_v3_fallback_assert(
    svtop_tiny_should_use_python_fallback(false, 7, 'connection_failed', 500) === false,
    'resposta HTTP real deve ser autoritativa mesmo com sinal de transporte'
);

fwrite(STDOUT, "COMPROVADO: qualquer resposta HTTP Tiny/Olist e autoritativa; apenas falha real de transporte sem HTTP usa fallback.\n");
