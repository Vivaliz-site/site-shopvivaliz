<?php
$root = dirname(__DIR__);
$path = $root . '/.github/workflows/claude.yml';
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "claude workflow missing\n");
    exit(1);
}
$checks = [
    "explicit mention" => "@claude",
    "bot exclusion" => "!endsWith(github.actor, '[bot]')",
    "issue comment trusted association" => "github.event.comment.author_association",
    "issue trusted association" => "github.event.issue.author_association",
    "review trusted association" => "github.event.review.author_association",
    "trusted roles" => '["OWNER","MEMBER","COLLABORATOR"]',
    "concurrency" => "concurrency:",
    "stale cancellation" => "cancel-in-progress: true",
    "job timeout" => "timeout-minutes: 15",
    "turn limit input" => "claude_args:",
    "turn limit" => "--max-turns 5",
];
foreach ($checks as $label => $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "missing cost guard: {$label}\n");
        exit(1);
    }
}
$quality = file_get_contents($root . '/.github/workflows/quality-gate.yml');
if ($quality === false || !str_contains($quality, 'php tests/claude-workflow-cost-guard-test.php')) {
    fwrite(STDERR, "cost guard test is not enforced by Quality Gate\n");
    exit(1);
}
echo "claude-workflow-cost-guard: ok\n";
