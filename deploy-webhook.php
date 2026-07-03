<?php
/**
 * Deploy Webhook — recebe push do GitHub e atualiza o servidor automaticamente.
 *
 * Setup (uma vez):
 *   1. Faça upload deste arquivo para a raiz do site via cPanel File Manager.
 *   2. Adicione ao .env do servidor:
 *        DEPLOY_SECRET=<qualquer string aleatória>
 *        GITHUB_TOKEN=<personal access token com permissão repo:read>
 *   3. No GitHub: Settings → Webhooks → Add webhook
 *        Payload URL: https://dev.shopvivaliz.com.br/deploy-webhook.php
 *        Content type: application/json
 *        Secret: <o mesmo DEPLOY_SECRET>
 *        Events: Just the push event
 *   4. Pronto — cada push em main faz deploy automático.
 */

declare(strict_types=1);

// ── Bootstrap: carrega .env ───────────────────────────────────────────────────
(static function () {
    $f = __DIR__ . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

// ── Helpers ───────────────────────────────────────────────────────────────────
function dw_env(string $key): string
{
    return (string)(getenv($key) ?: '');
}

function dw_abort(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function dw_recursive_remove(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        dw_recursive_remove($item->getPathname());
    }

    @rmdir($path);
}

function dw_log(string $msg): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($dir . '/deploy-webhook.log',
        '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// ── Validação da assinatura GitHub ───────────────────────────────────────────
$secret  = dw_env('DEPLOY_SECRET');
$payload = (string)file_get_contents('php://input');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dw_abort(405, 'Método não permitido. Use POST.');
}

if ($secret === '') {
    dw_abort(503, 'DEPLOY_SECRET não configurado no servidor.');
}

$sig      = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $sig)) {
    dw_abort(401, 'Assinatura inválida.');
}

if ($payload === '') {
    dw_abort(400, 'Payload vazio.');
}

// ── Só age em push para main ──────────────────────────────────────────────────
$data = json_decode($payload, true);
if (!is_array($data)) {
    dw_abort(400, 'Payload JSON inválido.');
}

$ref  = $data['ref'] ?? '';
if ($ref !== 'refs/heads/main') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'skipped' => true, 'ref' => $ref]);
    exit;
}

$sha = $data['after'] ?? 'main';
dw_log("Push recebido: ref=$ref sha=$sha");

// ── Estratégia 1: git pull (se git disponível via exec) ───────────────────────
$gitAvailable = false;
if (function_exists('exec')) {
    $token     = dw_env('GITHUB_TOKEN');
    $repo      = 'fredmourao-ai/site-shopvivaliz';
    $remoteUrl = $token !== ''
        ? "https://{$token}@github.com/{$repo}.git"
        : "https://github.com/{$repo}.git";

    $gitDir = __DIR__ . '/.git';
    if (is_dir($gitDir)) {
        $cmd    = "cd " . escapeshellarg(__DIR__) . " && git pull " . escapeshellarg($remoteUrl) . " main 2>&1";
        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);
        $out = implode("\n", $output);
        if ($code === 0) {
            dw_log("git pull OK: " . $out);
            $gitAvailable = true;
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'method' => 'git_pull', 'output' => $out, 'sha' => $sha]);
            exit;
        }
        dw_log("git pull falhou (code=$code): $out — tentando ZIP");
    }
}

// ── Estratégia 2: download ZIP via GitHub API ─────────────────────────────────
$token    = dw_env('GITHUB_TOKEN');
$repo     = 'fredmourao-ai/site-shopvivaliz';
$zipUrl   = "https://api.github.com/repos/{$repo}/zipball/main";

$tmpZip = sys_get_temp_dir() . '/shopvivaliz-deploy-' . time() . '.zip';
$tmpDir = sys_get_temp_dir() . '/shopvivaliz-extract-' . time();

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 120,
        'follow_location' => 1,
        'max_redirects'   => 5,
        'header'  => implode("\r\n", array_filter([
            'User-Agent: ShopVivaliz-Deploy/1.0',
            $token !== '' ? "Authorization: token {$token}" : '',
            'Accept: application/vnd.github+json',
        ])),
    ],
]);

$zip = @file_get_contents($zipUrl, false, $ctx);
if ($zip === false || strlen($zip) < 1000) {
    dw_log("Falha ao baixar ZIP do GitHub.");
    dw_abort(502, 'Não foi possível baixar o ZIP do repositório. Verifique GITHUB_TOKEN.');
}

file_put_contents($tmpZip, $zip);
dw_log("ZIP baixado: " . strlen($zip) . " bytes → $tmpZip");

// Extrair ZIP
$za = new ZipArchive();
if ($za->open($tmpZip) !== true) {
    @unlink($tmpZip);
    dw_abort(500, 'ZIP inválido ou corrompido.');
}

@mkdir($tmpDir, 0755, true);
$za->extractTo($tmpDir);
$za->close();

// O ZIP do GitHub tem um subdir: "{repo}-{sha}/"
$subDirs = glob($tmpDir . '/*', GLOB_ONLYDIR);
$srcDir  = $subDirs[0] ?? null;

if (!$srcDir || !is_dir($srcDir)) {
    @unlink($tmpZip);
    dw_abort(500, 'Estrutura inesperada no ZIP.');
}

// ── Copiar arquivos para o webroot ────────────────────────────────────────────
$SKIP = [
    '.git', '.github', '.claude', '.codex', '.vscode',
    'node_modules', 'vendor', '__pycache__',
    'storage/olist-images', 'storage/reports', 'storage/runtime',
    'logs', 'uploads', 'imports',
    'deploy-webhook.php', // não sobrescreve a si mesmo
    '.env',               // nunca sobrescreve .env do servidor
];

$copied = 0;

function dw_copy_dir(string $src, string $dst, array $skipList, int &$cnt): void
{
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    $items = new DirectoryIterator($src);
    foreach ($items as $item) {
        if ($item->isDot()) continue;
        $name    = $item->getFilename();
        $srcPath = $item->getPathname();
        $dstPath = $dst . '/' . $name;

        // verificar skip
        $relPath = ltrim(str_replace($src, '', $srcPath), '/');
        foreach ($skipList as $skip) {
            if ($name === $skip || str_starts_with($relPath, $skip)) continue 2;
        }

        if ($item->isDir()) {
            dw_copy_dir($srcPath, $dstPath, $skipList, $cnt);
        } else {
            if (@copy($srcPath, $dstPath)) {
                $cnt++;
            }
        }
    }
}

dw_copy_dir($srcDir, __DIR__, $SKIP, $copied);

// Limpar temporários
dw_recursive_remove($tmpZip);
dw_recursive_remove($tmpDir);

$msg = "Deploy via ZIP concluído: $copied arquivos copiados (sha=$sha)";
dw_log($msg);

header('Content-Type: application/json');
echo json_encode([
    'ok'     => true,
    'method' => 'zip_extract',
    'files'  => $copied,
    'sha'    => $sha,
    'msg'    => $msg,
]);
