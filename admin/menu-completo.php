<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';

// Compatibilidade para links administrativos antigos. A central canônica é
// /admin/; manter esta rota evita 404 em módulos ainda não migrados sem criar
// um segundo menu divergente.
header('Location: /admin/', true, 302);
exit;
