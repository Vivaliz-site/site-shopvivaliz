# ShopVivaliz Marina pre-cutover: SOMENTE LEITURA / NO_CUT.
[CmdletBinding()]
param([string]$RepoRoot = 'C:\site-shopvivaliz')
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Write-Output 'CHECKPOINT_MODE=READ_ONLY'
Write-Output 'ORIGIN_HOST=shopvivaliz-free-a1'
Write-Output 'ORIGIN_PRIVATE_IP=10.0.1.112'
Write-Output 'MARINA_HOST=marina-a1-4c24g'
Write-Output 'MARINA_PRIVATE_IP=10.80.1.116'
Write-Output 'BACKEND_PRIVATE_IP=10.0.1.38'
Write-Output 'SSH_REUSE_POLICY=SAME_AUTHORIZED_KEY'
Write-Output 'SSH_NEW_KEY_REQUIRED=NO'
$existingKey = 'C:\Users\FRED\Downloads\ssh-key-2026-07-04.key'
if (Test-Path -LiteralPath $existingKey -PathType Leaf) {
    Write-Output 'SSH_KEY_REUSE=CONFIGURED'
} else {
    Write-Output 'SSH_KEY_REUSE=FILE_NOT_FOUND'
}
Write-Output 'SSH_AUTH_RUNTIME=NOT_CERTIFIED_BY_LOCAL_PREFLIGHT'
if (-not (Test-Path -LiteralPath $RepoRoot -PathType Container)) {
    Write-Output 'REPOSITORY_STATUS=MISSING'
    Write-Output 'MIGRATION_GATE=NO_CUT'
    exit 2
}
$required = @('AGENTS.md','AUDIT_POLICY.md','docs\ops\oci-marina-migration-v5-20261017.md','docs\ops\marina-migration-codex-handoff-20261017.md')
$validators = @('scripts\p1-diagnose-mysql-delta.sh','scripts\p2-validate-oauth-ml-renewal.sh','scripts\p4-validate-php-modules.sh')
foreach ($f in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot $f) -PathType Leaf)) {
        Write-Output ('MISSING_DOC=' + $f)
    }
}
foreach ($f in $validators) {
    if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot $f) -PathType Leaf)) {
        Write-Output ('MISSING_VALIDATOR=' + $f)
    }
}
Write-Output 'PROOF_REQUIRED=FINAL_AUTHORIZED_DATABASE_DELTA'
Write-Output 'PROOF_REQUIRED=EXACT_RELEASE_UI_E2E_AND_PROVIDER_TESTS'
Write-Output 'PROOF_REQUIRED=SINGLE_WRITER_TLS_TUNNEL_AND_ROLLBACK'
Write-Output 'PROOF_REQUIRED=OCI_BILLING_AND_CUTOVER_APPROVAL'
Write-Output 'MIGRATION_GATE=NO_CUT'
exit 2
