<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autodev/git/branch_manager.php';
require_once dirname(__DIR__) . '/autodev/git/pr_generator.php';

function create_auto_pr(string $msg): array
{
    $branch = autodev_branch_name('auto-' . $msg);
    return [
        'status' => 'proposal_only',
        'branch' => $branch,
        'pull_request' => autodev_pr_payload($msg, $branch),
        'note' => 'Git/PR automático exige revisão humana e workflow autenticado.',
    ];
}
