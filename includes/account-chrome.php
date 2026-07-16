<?php
declare(strict_types=1);

/**
 * Chrome compartilhado da area "Minha Conta". Inclua account-chrome-top.php
 * apos definir $svAccountPageTitle e $svAccountActive, e account-chrome-bottom.php
 * no fim da pagina.
 */

function sv_account_require_login(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/minha-conta/'));
        exit;
    }
    return [
        'id' => (int)$_SESSION['user_id'],
        'name' => (string)($_SESSION['user_name'] ?? ''),
        'email' => (string)($_SESSION['user_email'] ?? ''),
    ];
}

function sv_account_nav_items(): array
{
    return [
        'dashboard' => ['label' => 'Painel', 'href' => '/minha-conta/', 'icon' => '🏠'],
        'pedidos' => ['label' => 'Meus Pedidos', 'href' => '/minha-conta/pedidos.php', 'icon' => '📦'],
        'enderecos' => ['label' => 'Endereços', 'href' => '/minha-conta/enderecos.php', 'icon' => '📍'],
        'cupons' => ['label' => 'Meus Cupons', 'href' => '/minha-conta/cupons.php', 'icon' => '🎟️'],
        'dados' => ['label' => 'Meus Dados', 'href' => '/minha-conta/dados.php', 'icon' => '👤'],
    ];
}
