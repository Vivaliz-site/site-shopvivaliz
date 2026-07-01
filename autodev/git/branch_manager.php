<?php
declare(strict_types=1);

/**
 * AutoDev Git Branch Manager
 *
 * Manages git branches for the AutoDev evolution system.
 * All autodev branches use the "autodev/" prefix.
 */

define('AUTODEV_GIT_LOG', __DIR__ . '/../../autodev/data/git.log');
define('AUTODEV_BRANCH_PREFIX', 'autodev/');

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function _autodev_git_log(string $level, string $message, array $context = []): void
{
    $logDir = dirname(AUTODEV_GIT_LOG);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $ts      = date('Y-m-d H:i:s');
    $ctx     = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    $line    = "[{$ts}] [{$level}] {$message}{$ctx}" . PHP_EOL;
    file_put_contents(AUTODEV_GIT_LOG, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Run a git command from the repo root.
 *
 * @param  string   $cmd        Full git command (arguments only, without "git ")
 * @param  string[] &$output    Lines of output
 * @param  int      &$exitCode  Exit code
 * @return string               Trimmed combined output
 */
function _autodev_git(string $cmd, array &$output = [], int &$exitCode = 0): string
{
    $repoRoot = realpath(__DIR__ . '/../../');
    $full     = 'git -C ' . escapeshellarg($repoRoot) . ' ' . $cmd . ' 2>&1';

    _autodev_git_log('DEBUG', 'Running: ' . $full);

    exec($full, $output, $exitCode);

    $result = implode("\n", $output);
    _autodev_git_log('DEBUG', 'Exit=' . $exitCode . ' Output=' . $result);

    return trim($result);
}

/**
 * Sanitize a free-text string into a git-safe branch slug.
 */
function _autodev_sanitize_slug(string $name): string
{
    $slug = mb_strtolower(trim($name));
    // Replace anything not alphanumeric, dot, or hyphen with a hyphen
    $slug = preg_replace('/[^a-z0-9.\-]+/', '-', $slug);
    // Collapse multiple hyphens
    $slug = preg_replace('/-{2,}/', '-', $slug);
    // Strip leading/trailing hyphens
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'task';
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Create a new autodev branch from the current HEAD.
 *
 * The resulting branch name follows the pattern:
 *   autodev/<sanitized-name>-<YmdHis>
 *
 * @param  string $name  Human-readable name for the branch.
 * @return string|false  The full branch name, or false on failure.
 */
function create_branch(string $name): string|false
{
    $slug       = _autodev_sanitize_slug($name);
    $timestamp  = date('YmdHis');
    $branch     = AUTODEV_BRANCH_PREFIX . $slug . '-' . $timestamp;

    _autodev_git_log('INFO', 'Creating branch', ['branch' => $branch]);

    $output   = [];
    $exitCode = 0;
    _autodev_git('checkout -b ' . escapeshellarg($branch), $output, $exitCode);

    if ($exitCode !== 0) {
        _autodev_git_log('ERROR', 'Failed to create branch', [
            'branch' => $branch,
            'output' => implode("\n", $output),
        ]);
        return false;
    }

    _autodev_git_log('INFO', 'Branch created', ['branch' => $branch]);
    return $branch;
}

/**
 * Return the name of the currently checked-out git branch.
 *
 * @return string|false  Branch name, or false if it cannot be determined.
 */
function get_current_branch(): string|false
{
    $output   = [];
    $exitCode = 0;
    $result   = _autodev_git('rev-parse --abbrev-ref HEAD', $output, $exitCode);

    if ($exitCode !== 0 || $result === '') {
        _autodev_git_log('ERROR', 'Could not determine current branch');
        return false;
    }

    _autodev_git_log('INFO', 'Current branch: ' . $result);
    return $result;
}

/**
 * List all local branches that start with "autodev/".
 *
 * @return string[]  Array of branch names (without leading "  " or "* " markers).
 */
function list_autodev_branches(): array
{
    $output   = [];
    $exitCode = 0;
    _autodev_git('branch --list ' . escapeshellarg(AUTODEV_BRANCH_PREFIX . '*'), $output, $exitCode);

    if ($exitCode !== 0) {
        _autodev_git_log('ERROR', 'Failed to list autodev branches');
        return [];
    }

    $branches = [];
    foreach ($output as $line) {
        $clean = trim(ltrim($line, '*'));
        if ($clean !== '') {
            $branches[] = $clean;
        }
    }

    _autodev_git_log('INFO', 'Listed autodev branches', ['count' => count($branches)]);
    return $branches;
}

/**
 * Delete a local autodev branch.
 *
 * Only branches that start with "autodev/" may be deleted.
 * Uses -d (safe delete — only deletes if already merged).
 *
 * @param  string $branch  Full branch name, e.g. "autodev/fix-cart-20240101120000".
 * @return bool            True on success, false on failure.
 */
function delete_branch(string $branch): bool
{
    if (!str_starts_with($branch, AUTODEV_BRANCH_PREFIX)) {
        _autodev_git_log('ERROR', 'Refused to delete non-autodev branch', ['branch' => $branch]);
        return false;
    }

    _autodev_git_log('INFO', 'Deleting branch', ['branch' => $branch]);

    $output   = [];
    $exitCode = 0;
    _autodev_git('branch -d ' . escapeshellarg($branch), $output, $exitCode);

    if ($exitCode !== 0) {
        _autodev_git_log('ERROR', 'Failed to delete branch', [
            'branch' => $branch,
            'output' => implode("\n", $output),
        ]);
        return false;
    }

    _autodev_git_log('INFO', 'Branch deleted', ['branch' => $branch]);
    return true;
}

/**
 * Check whether the working tree and index are clean (no uncommitted changes).
 *
 * @return bool  True if clean, false if there are staged, unstaged, or untracked changes.
 */
function ensure_clean_state(): bool
{
    $output   = [];
    $exitCode = 0;
    $result   = _autodev_git('status --porcelain', $output, $exitCode);

    if ($exitCode !== 0) {
        _autodev_git_log('ERROR', 'git status --porcelain failed');
        return false;
    }

    $clean = ($result === '');
    _autodev_git_log('INFO', 'Working tree clean: ' . ($clean ? 'yes' : 'no'), [
        'dirty_files' => $clean ? [] : $output,
    ]);

    return $clean;
}
