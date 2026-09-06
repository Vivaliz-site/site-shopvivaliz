<?php
$root = dirname(__DIR__);
$path = $root . '/.github/workflows/claude.yml';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "claude workflow missing\n");
    exit(1);
}
$text = str_replace("\r\n", "\n", $text);

$checks = [
    'explicit mention' => '@claude',
    'bot exclusion' => "!endsWith(github.actor, '[bot]')",
    'trusted roles' => '["OWNER","MEMBER","COLLABORATOR"]',
    'job timeout' => 'timeout-minutes: 15',
    'turn limit input' => 'claude_args:',
    'turn limit' => '--max-turns 5',
];
foreach ($checks as $label => $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "missing cost guard: {$label}\n");
        exit(1);
    }
}

$eventGuards = [
    'issue_comment' => <<<'YAML'
        (
          github.event_name == 'issue_comment' &&
          contains(github.event.comment.body, '@claude') &&
          contains(fromJSON('["OWNER","MEMBER","COLLABORATOR"]'), github.event.comment.author_association)
        )
YAML,
    'pull_request_review_comment' => <<<'YAML'
        (
          github.event_name == 'pull_request_review_comment' &&
          contains(github.event.comment.body, '@claude') &&
          contains(fromJSON('["OWNER","MEMBER","COLLABORATOR"]'), github.event.comment.author_association)
        )
YAML,
    'pull_request_review' => <<<'YAML'
        (
          github.event_name == 'pull_request_review' &&
          contains(github.event.review.body, '@claude') &&
          contains(fromJSON('["OWNER","MEMBER","COLLABORATOR"]'), github.event.review.author_association)
        )
YAML,
    'issues' => <<<'YAML'
        (
          github.event_name == 'issues' &&
          (contains(github.event.issue.body, '@claude') || contains(github.event.issue.title, '@claude')) &&
          contains(fromJSON('["OWNER","MEMBER","COLLABORATOR"]'), github.event.issue.author_association)
        )
YAML,
];
foreach ($eventGuards as $event => $fragment) {
    if (!str_contains($text, $fragment)) {
        fwrite(STDERR, "missing complete paid-AI guard for event: {$event}\n");
        exit(1);
    }
}

$jobStart = strpos($text, "\njobs:\n  claude:\n");
$jobIf = strpos($text, "    if: |\n", $jobStart ?: 0);
$jobConcurrency = strpos($text, "    concurrency:\n", $jobStart ?: 0);
if ($jobStart === false || $jobIf === false || $jobConcurrency === false || $jobConcurrency > $jobIf) {
    fwrite(STDERR, "Claude concurrency must be job-scoped before the opt-in job executes\n");
    exit(1);
}
if (preg_match('/^concurrency:/m', $text) === 1) {
    fwrite(STDERR, "workflow-level concurrency can let non-invocation events cancel paid Claude runs\n");
    exit(1);
}
if (!str_contains(substr($text, $jobConcurrency, $jobIf - $jobConcurrency), 'cancel-in-progress: true')) {
    fwrite(STDERR, "job-scoped stale cancellation missing\n");
    exit(1);
}

$quality = file_get_contents($root . '/.github/workflows/quality-gate.yml');
if ($quality === false || !str_contains($quality, 'php tests/claude-workflow-cost-guard-test.php')) {
    fwrite(STDERR, "cost guard test is not enforced by Quality Gate\n");
    exit(1);
}
echo "claude-workflow-cost-guard: ok\n";
