<?php
/**
 * reCAPTCHA v3 Helper - ShopVivaliz
 * Valida reCAPTCHA em formulários críticos
 */

declare(strict_types=1);

function sv_recaptcha_validate(string $token): bool
{
    // Obter chave secreta do .env ou config
    $secretKey = getenv('RECAPTCHA_SECRET_KEY') ?: '';

    if ($secretKey === '') {
        error_log('[reCAPTCHA] Secret key não configurada');
        return false;
    }

    try {
        $response = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'secret' => $secretKey,
                    'response' => $token,
                ]),
                'timeout' => 10,
            ]
        ]));

        if (!$response) {
            error_log('[reCAPTCHA] Falha ao conectar com Google');
            return false;
        }

        $data = json_decode($response, true);

        // Validar resposta
        if (empty($data['success']) || ($data['score'] ?? 0) < 0.5) {
            error_log('[reCAPTCHA] Validação falhou - score: ' . ($data['score'] ?? 0));
            return false;
        }

        return true;
    } catch (Exception $e) {
        error_log('[reCAPTCHA] Erro: ' . $e->getMessage());
        return false;
    }
}

function sv_recaptcha_key(): string
{
    return getenv('RECAPTCHA_SITE_KEY') ?: '';
}
?>
