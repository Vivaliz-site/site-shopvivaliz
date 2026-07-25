<?php
$env_file = '.env';
foreach (file($env_file) as $line) {
    $line = trim($line);
    if ($line && !str_starts_with($line, '#') && str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        define(trim($k), trim($v, " \t\n\r\x0B\"'"));
    }
}
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$r = $db->query('DESCRIBE products');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . PHP_EOL;
}
