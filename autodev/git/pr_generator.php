<?php
declare(strict_types=1);

/**
 * AutoDev PR Generator
 *
 * Creates and manages GitHub Pull Requests via the `gh` CLI.
 * All operations are logged and all shell inputs are escaped.
 */

define('AUTODEV_PR_LOG', __DIR__ . '/../../autodev/data/git.log');

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function _autodev_pr_log(string $level, string $message, array $context = []): void
{
    $logDir = dirname(AUTODEV_PR_LOG);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $ts   = date('Y-m-d H:i:s');
    $ctx  = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    $line = "[{$ts}] [{$level}] {$message}{$ctx}" . PHP_EOL;
    file_put_contents(AUTODEV_PR_LOG, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Run a shell command and return its decoded JSON output or raw string.
 *
 * @param  string $cmd       Full shell command.
 * @param  int    &$exitCode Exit code.
 * @return string            Trimmed stdout+stderr.
 */
function _autodev_exec(string $cmd, int &$exitCode = 0): string
{
    _autodev_pr_log('DEBUG', 'Exec: ' . $cmd);

    $output = [];
    exec($cmd . ' 2>&1', $output, $exitCode);

    $result = trim(implode("\n", $output));
    _autodev_pr_log('DEBUG', 'Exit=' . $exitCode . ' Output=' . $result);

    return $result;
}

/**
 * Return the repo root path (two levels above this file).
 */
function _autodev_repo_root(): string
{
    return realpath(__DIR__ . '/../../');
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Create a GitHub Pull Request via `gh pr create`.
 *
 * @param  string $title   PR title.
 * @param  string $body    PR body (Markdown).
 * @param  string $branch  Head branch to open the PR from.
 * @param  string $base    Base branch (default: main).
 * @return string|false    PR URL on success, false on failure.
 */
function create_pr(string $title, string $body, string $branch, string $base = 'main'): string|false
{
    _autodev_pr_log('INFO', 'Creating PR', [
        'title'  => $title,
        'branch' => $branch,
        'base'   => $base,
    ]);

    $repoRoot = _autodev_repo_root();

    // Write body to a temp file to avoid shell escaping hell with newlines
    $tmpBody = tempnam(sys_get_temp_dir(), 'autodev_pr_');
    if ($tmpBody === false) {
        _autodev_pr_log('ERROR', 'Could not create temp file for PR body');
        return false;
    }
    file_put_contents($tmpBody, $body);

    $cmd = sprintf(
        'cd %s && gh pr create --title %s --body-file %s --head %s --base %s',
        escapeshellarg($repoRoot),
        escapeshellarg($title),
        escapeshellarg($tmpBody),
        escapeshellarg($branch),
        escapeshellarg($base)
    );

    $exitCode = 0;
    $result   = _autodev_exec($cmd, $exitCode);

    @unlink($tmpBody);

    if ($exitCode !== 0) {
        _autodev_pr_log('ERROR', 'gh pr create failed', ['output' => $result]);
        return false;
    }

    // gh pr create outputs the PR URL on success
    $url = trim($result);
    _autodev_pr_log('INFO', 'PR created', ['url' => $url]);

    return $url;
}

/**
 * List open PRs created by the AutoDev system (head branch starts with "autodev/").
 *
 * @return array<int, array{number: int, title: string, url: string}>
 */
function list_open_prs(): array
{
    _autodev_pr_log('INFO', 'Listing open autodev PRs');

    $repoRoot = _autodev_repo_root();

    $cmd = sprintf(
        'cd %s && gh pr list --state open --json number,title,url,headRefName',
        escapeshellarg($repoRoot)
    );

    $exitCode = 0;
    $raw      = _autodev_exec($cmd, $exitCode);

    if ($exitCode !== 0) {
        _autodev_pr_log('ERROR', 'gh pr list failed', ['output' => $raw]);
        return [];
    }

    $all = json_decode($raw, true);
    if (!is_array($all)) {
        _autodev_pr_log('ERROR', 'Could not parse gh pr list JSON', ['raw' => $raw]);
        return [];
    }

    // Filter to autodev/ branches only
    $autodevPrs = array_values(array_filter($all, static function (array $pr): bool {
        return str_starts_with($pr['headRefName'] ?? '', 'autodev/');
    }));

    // Return only the fields the spec demands
    $result = array_map(static fn(array $pr) => [
        'number' => (int) $pr['number'],
        'title'  => (string) $pr['title'],
        'url'    => (string) $pr['url'],
    ], $autodevPrs);

    _autodev_pr_log('INFO', 'Open autodev PRs', ['count' => count($result)]);
    return $result;
}

/**
 * Close (without merging) a PR by number.
 *
 * @param  int  $number  GitHub PR number.
 * @return bool          True on success.
 */
function close_pr(int $number): bool
{
    _autodev_pr_log('INFO', 'Closing PR', ['number' => $number]);

    $repoRoot = _autodev_repo_root();

    $cmd = sprintf(
        'cd %s && gh pr close %s --comment %s',
        escapeshellarg($repoRoot),
        escapeshellarg((string) $number),
        escapeshellarg('Closed automatically by AutoDev evolution system.')
    );

    $exitCode = 0;
    $result   = _autodev_exec($cmd, $exitCode);

    if ($exitCode !== 0) {
        _autodev_pr_log('ERROR', 'gh pr close failed', [
            'number' => $number,
            'output' => $result,
        ]);
        return false;
    }

    _autodev_pr_log('INFO', 'PR closed', ['number' => $number]);
    return true;
}

/**
 * Generate a Markdown PR body with a before/after metrics comparison table.
 *
 * @param  string $action          Short description of what changed, e.g. "Optimize checkout flow".
 * @param  array  $metrics_before  Associative array of metric name => value.
 * @param  array  $metrics_after   Associative array of metric name => value (same keys).
 * @return string                  Markdown-formatted PR body.
 */
function generate_pr_body(string $action, array $metrics_before, array $metrics_after): string
{
    $ts = date('Y-m-d H:i:s T');

    // Build metrics table
    $rows = '';
    $allKeys = array_unique(array_merge(array_keys($metrics_before), array_keys($metrics_after)));
    foreach ($allKeys as $key) {
        $before = $metrics_before[$key] ?? 'N/A';
        $after  = $metrics_after[$key]  ?? 'N/A';

        // Delta indicator
        if (is_numeric($before) && is_numeric($after)) {
            $delta = (float) $after - (float) $before;
            $sign  = $delta > 0 ? '+' : '';
            $emoji = $delta < 0 ? ' :arrow_down:' : ($delta > 0 ? ' :arrow_up:' : '');
            $deltaStr = $sign . number_format($delta, 2) . $emoji;
        } else {
            $deltaStr = $before !== $after ? ':warning: changed' : '—';
        }

        $rows .= sprintf(
            "| %s | %s | %s | %s |\n",
            htmlspecialchars_decode($key, ENT_QUOTES),
            $before,
            $after,
            $deltaStr
        );
    }

    $table = <<<MD
| Métrica | Antes | Depois | Delta |
|---------|-------|--------|-------|
{$rows}
MD;

    $body = <<<MD
## AutoDev: {$action}

> Gerado automaticamente pelo **AutoDev Evolution System** em {$ts}.
> Revisão humana obrigatória antes do merge.

### O que mudou

{$action}

### Comparação de métricas

{$table}

### Checklist de validação

- [ ] Checkout E2E passou (`playwright_checkout.spec.js`)
- [ ] Testes de regressão passaram (`regression.spec.js`)
- [ ] Sem erros de JavaScript nas páginas críticas
- [ ] Revisão de código por humano concluída

### Notas

- Mudanças conservadoras por padrão (sem refatorações agressivas)
- Branch: gerada com prefixo `autodev/` + timestamp
- Rollback: `git revert` no commit de merge
MD;

    return $body;
}

/**
 * Stage specific files and create a git commit.
 *
 * @param  string[] $files    Paths of files to stage (relative to repo root or absolute).
 * @param  string   $message  Commit message.
 * @return bool               True on success.
 */
function commit_changes(array $files, string $message): bool
{
    if (empty($files)) {
        _autodev_pr_log('WARNING', 'commit_changes called with empty file list');
        return false;
    }

    _autodev_pr_log('INFO', 'Committing files', [
        'files'   => $files,
        'message' => $message,
    ]);

    $repoRoot = _autodev_repo_root();

    // Stage each file individually so we never accidentally stage sensitive files
    foreach ($files as $file) {
        $cmd = sprintf(
            'cd %s && git add -- %s',
            escapeshellarg($repoRoot),
            escapeshellarg($file)
        );
        $exitCode = 0;
        $output   = _autodev_exec($cmd, $exitCode);

        if ($exitCode !== 0) {
            _autodev_pr_log('ERROR', 'git add failed', [
                'file'   => $file,
                'output' => $output,
            ]);
            return false;
        }
    }

    // Write the commit message to a temp file to handle special characters safely
    $tmpMsg = tempnam(sys_get_temp_dir(), 'autodev_msg_');
    if ($tmpMsg === false) {
        _autodev_pr_log('ERROR', 'Could not create temp file for commit message');
        return false;
    }
    file_put_contents($tmpMsg, $message);

    $cmd = sprintf(
        'cd %s && git commit -F %s',
        escapeshellarg($repoRoot),
        escapeshellarg($tmpMsg)
    );

    $exitCode = 0;
    $result   = _autodev_exec($cmd, $exitCode);

    @unlink($tmpMsg);

    if ($exitCode !== 0) {
        _autodev_pr_log('ERROR', 'git commit failed', ['output' => $result]);
        return false;
    }

    _autodev_pr_log('INFO', 'Commit created', ['output' => $result]);
    return true;
}
