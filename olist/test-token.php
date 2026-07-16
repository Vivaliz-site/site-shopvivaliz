<?php
/**
 * Teste de Token - Debug do OAuth
 */

header('Content-Type: application/json; charset=utf-8');

$result = [
    'timestamp' => date('c'),
    'token_locations' => [],
    'token_file_exists' => false,
    'token_content' => null,
    'writeable_paths' => [],
    'errors' => []
];

// 1. Procurar token em vários locais
$locations = [
    __DIR__ . '/../.tokens/olist_refresh_token.txt',
    __DIR__ . '/olist_refresh_token.txt',
    '/tmp/olist_refresh_token.txt',
    sys_get_temp_dir() . '/olist_refresh_token.txt'
];

foreach ($locations as $loc) {
    if (file_exists($loc)) {
        $result['token_locations'][] = [
            'path' => $loc,
            'exists' => true,
            'readable' => is_readable($loc),
            'size' => filesize($loc)
        ];

        if (is_readable($loc)) {
            $content = file_get_contents($loc);
            $result['token_content'] = substr($content, 0, 50) . '...';
            $result['token_file_exists'] = true;
        }
    } else {
        $result['token_locations'][] = [
            'path' => $loc,
            'exists' => false
        ];
    }
}

// 2. Testar permissões de escrita
$test_dirs = [
    __DIR__ . '/../.tokens',
    __DIR__,
    __DIR__ . '/../logs'
];

foreach ($test_dirs as $dir) {
    $dir_exists = is_dir($dir);
    $writable = is_writable($dir);

    $result['writeable_paths'][] = [
        'path' => $dir,
        'exists' => $dir_exists,
        'writable' => $writable
    ];

    if ($dir_exists && !$writable) {
        $result['errors'][] = "Diretório $dir não tem permissão de escrita";
    }
}

// 3. Procurar em session
session_start();
if (isset($_SESSION['olist_refresh_token'])) {
    $result['session_token'] = substr($_SESSION['olist_refresh_token'], 0, 50) . '...';
} else {
    $result['session_token'] = null;
}

// 4. Tenta criar teste
$test_file = sys_get_temp_dir() . '/olist_test_' . time() . '.txt';
$test_write = @file_put_contents($test_file, 'test');

if ($test_write) {
    $result['can_write_temp'] = true;
    @unlink($test_file);
} else {
    $result['can_write_temp'] = false;
    $result['errors'][] = "Não consegue escrever em " . sys_get_temp_dir();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
