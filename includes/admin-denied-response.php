<?php
declare(strict_types=1);

/**
 * The admin guard must keep denying unauthorized sessions with HTTP 403.
 * For real browser navigations only, offer a way to leave the current
 * (possibly non-admin) account. Never expose this HTML to admin APIs.
 */
function sv_admin_denied_is_browser_page(string $scriptName, string $method, string $accept): bool
{
    return $method === 'GET'
        && str_starts_with($scriptName, '/admin/')
        && stripos($accept, 'text/html') !== false;
}

function sv_admin_denied_page(): string
{
    return <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Acesso administrativo restrito — ShopVivaliz</title>
<style>
*{box-sizing:border-box}
body{min-height:100vh;margin:0;padding:24px;display:grid;place-items:center;background:#f5f7fb;color:#16263b;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{width:min(540px,100%);padding:32px;background:#fff;border:1px solid #dce4ed;border-radius:14px;box-shadow:0 12px 30px rgba(15,23,42,.08)}
h1{font-size:1.5rem;margin:0 0 12px}
p{margin:0 0 20px;color:#475569}
a.action{display:inline-block;padding:11px 16px;background:#17365d;color:#fff;border-radius:8px;text-decoration:none;font-weight:700}
small{display:block;margin-top:18px;color:#64748b}
</style>
</head>
<body>
<main>
<h1>Esta sessão não tem acesso administrativo</h1>
<p>O painel exige uma conta com permissão de administrador. Para utilizar outra conta, saia da sessão atual e faça login pelo caminho oficial.</p>
<a class="action" href="/auth/logout.php">Sair da conta atual</a>
<small>Nenhuma permissão foi alterada. O acesso administrativo continua protegido.</small>
</main>
</body>
</html>
HTML;
}
