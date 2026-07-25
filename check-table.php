<?php
$env_file = '.env';
foreach (file($env_file) as $line) {
    $line = trim($line);
    if ($line && !str_starts_with($line, '#') && str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        define(trim($k), trim($v, " \t\n\r\x0B\"'"));
    }
}
$db = new mysqli(
    defined('DB_HOST') ? DB_HOST : 'localhost',
    defined('DB_USER') ? DB_USER : 'root',
    defined('DB_PASS') ? DB_PASS : '',
    defined('DB_NAME') ? DB_NAME : 'shopvivaliz',
    defined('DB_PORT') ? DB_PORT : 3306
);
$r = $db->query('DESCRIBE products');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . PHP_EOL;
}
