<?php
declare(strict_types=1);

function sv_csrf_token(string $scope = 'default'): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['sv_csrf']) || !is_array($_SESSION['sv_csrf'])) $_SESSION['sv_csrf'] = [];
    if (empty($_SESSION['sv_csrf'][$scope])) $_SESSION['sv_csrf'][$scope] = bin2hex(random_bytes(32));
    return (string)$_SESSION['sv_csrf'][$scope];
}

function sv_csrf_valid(string $scope, mixed $provided): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $expected = (string)($_SESSION['sv_csrf'][$scope] ?? '');
    return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
}

function sv_csrf_input(string $scope = 'default'): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(sv_csrf_token($scope), ENT_QUOTES, 'UTF-8') . '">';
}
