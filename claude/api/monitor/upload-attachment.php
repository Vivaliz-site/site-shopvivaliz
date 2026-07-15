<?php
/**
 * Upload de anexos para o chat do monitor
 * Suporta: PDF, Images, Office Documents
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$uploadDir = __DIR__ . '/../../uploads/monitor-chat/';
@mkdir($uploadDir, 0755, true);

// Tipos permitidos
$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'text/plain' => 'txt',
    'text/csv' => 'csv'
];

$maxFileSize = 10 * 1024 * 1024; // 10MB

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
    exit;
}

$file = $_FILES['file'];

// Validar tamanho
if ($file['size'] > $maxFileSize) {
    http_response_code(413);
    echo json_encode(['error' => 'Arquivo muito grande (máximo 10MB)']);
    exit;
}

// Validar tipo MIME
$fileMime = mime_content_type($file['tmp_name']);
if (!isset($allowedMimes[$fileMime])) {
    http_response_code(415);
    echo json_encode([
        'error' => 'Tipo de arquivo não permitido',
        'mime' => $fileMime
    ]);
    exit;
}

// Sanitizar nome do arquivo
$originalName = basename($file['name']);
$extension = $allowedMimes[$fileMime];
$fileName = time() . '_' . md5($originalName) . '.' . $extension;
$filePath = $uploadDir . $fileName;

// Mover arquivo
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar arquivo']);
    exit;
}

// Registrar no log
$logFile = __DIR__ . '/../../logs/monitor-attachments.log';
@mkdir(dirname($logFile), 0755, true);
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'file' => $originalName,
    'size' => $file['size'],
    'type' => $fileMime,
    'stored_as' => $fileName,
    'url' => '/uploads/monitor-chat/' . $fileName
]) . "\n", FILE_APPEND);

http_response_code(200);
echo json_encode([
    'success' => true,
    'file' => [
        'name' => $originalName,
        'size' => $file['size'],
        'url' => '/uploads/monitor-chat/' . $fileName,
        'type' => $extension,
        'timestamp' => date('c')
    ]
]);
?>
