<?php
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/amazon-support-reply-oci-breakglass.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "amazon support OCI breakglass workflow missing\n");
    exit(1);
}
$text = (string) file_get_contents($workflow);
$required = [
    "issue_comment:",
    "types: [created]",
    "runs-on: ubuntu-latest",
    "environment: Production",
    "github.event.issue.number == 1586",
    "github.event.comment.user.login == 'fredmourao-ai'",
    "github.event.comment.body == '/amazon-support-reply case_ids=22153077391,22153259501'",
    "OCI_CLI_USER",
    "OCI_CLI_TENANCY",
    "OCI_CLI_FINGERPRINT",
    "OCI_CLI_REGION",
    "OCI_CLI_KEY_CONTENT",
    "SITE_INSTANCE_NAME: shopvivaliz-free-a1",
    "ComputeInstanceAgentClient",
    "seller-central-safe-t-read-worker.mjs",
    "--auth-check",
    "22153077391",
    "701-8413776-8628228",
    "22153259501",
    "701-0172386-7380246",
    "SELLER_CENTRAL_AUTH=AUTHENTICATED",
    "read_back",
    "ALREADY_EXISTS",
    "SENT",
];
foreach ($required as $needle) {
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "amazon support OCI breakglass missing contract: {$needle}\n");
        exit(1);
    }
}
$forbidden = [
    "push:",
    "issues:",
    "schedule:",
    "repository_dispatch:",
    "runs-on: self-hosted",
    "actions: write",
    "contents: write",
    "issues: write",
    "StrictHostKeyChecking=",
    "set +e",
    "|| true",
    "SOFTRESET",
    "compute instance action",
];
foreach ($forbidden as $needle) {
    if (strpos($text, $needle) !== false) {
        fwrite(STDERR, "amazon support OCI breakglass contains forbidden pattern: {$needle}\n");
        exit(1);
    }
}
echo "amazon-support-reply-oci-breakglass-contract: ok\n";
