<?php
/**
 * Upload de anexos para o chat do monitor
 * Suporta: PDF, Images, Office Documents
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Vary: Origin');
header('X-Content-Type-Options: nosniff');

$allowedOrigins = array_values(array_filter(array_unique([
    'https://shopvivaliz.com.br',
    'https://www.shopvivaliz.com.br',
    getenv('MONITOR_ALLOWED_ORIGIN') ?: null,
])));

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '') {
    if (!in_array($requestOrigin, $allowedOrigins, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Origem não autorizada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../includes/secure-session.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Autenticação necessária'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['is_admin'])) {
    require_once __DIR__ . '/../../../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $_SESSION['is_admin'] = !empty($row['is_admin']) ? 1 : 0;
    } catch (Throwable $e) {
        error_log('[monitor-upload] Falha ao validar administrador: ' . $e->getMessage());
        $_SESSION['is_admin'] = 0;
    }
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadDir = __DIR__ . '/../../uploads/monitor-chat/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    error_log('[monitor-upload] Não foi possível criar o diretório de upload');
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar armazenamento'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['file'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Falha no upload', 'code' => $uploadError], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpName = (string)($file['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileSize = (int)($file['size'] ?? 0);
if ($fileSize <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Arquivo vazio'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar tamanho
if ($fileSize > $maxFileSize) {
    http_response_code(413);
    echo json_encode(['error' => 'Arquivo muito grande (máximo 10MB)'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar tipo MIME a partir do conteúdo real do arquivo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$fileMime = $finfo->file($tmpName);
if ($fileMime === false || !isset($allowedMimes[$fileMime])) {
    http_response_code(415);
    echo json_encode([
        'error' => 'Tipo de arquivo não permitido',
        'mime' => $fileMime ?: 'desconhecido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Manter apenas um nome original seguro para exibição e auditoria.
$originalName = basename((string)($file['name'] ?? 'arquivo'));
$originalName = preg_replace('/[^\pL\pN._ -]+/u', '_', $originalName) ?: 'arquivo';
$extension = $allowedMimes[$fileMime];

try {
    $randomId = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    error_log('[monitor-upload] Falha ao gerar nome seguro: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar arquivo'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileName = $randomId . '.' . $extension;
$filePath = $uploadDir . $fileName;

// Mover arquivo
if (!move_uploaded_file($tmpName, $filePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar arquivo'], JSON_UNESCAPED_UNICODE);
    exit;
}

@chmod($filePath, 0640);

// Registrar no log
$logFile = __DIR__ . '/../../logs/monitor-attachments.log';
$logDir = dirname($logFile);
if ((is_dir($logDir) || mkdir($logDir, 0755, true)) && is_dir($logDir)) {
    $logEntry = json_encode([
        'timestamp' => date('c'),
        'user_id' => (int)$_SESSION['user_id'],
        'file' => $originalName,
        'size' => $fileSize,
        'type' => $fileMime,
        'stored_as' => $fileName,
        'url' => '/uploads/monitor-chat/' . $fileName,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($logEntry !== false) {
        file_put_contents($logFile, $logEntry . "\n", FILE_APPEND | LOCK_EX);
    }
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'file' => [
        'name' => $originalName,
        'size' => $fileSize,
        'url' => '/uploads/monitor-chat/' . $fileName,
        'type' => $extension,
        'timestamp' => date('c')
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
