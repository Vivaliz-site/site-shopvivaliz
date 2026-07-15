param(
    [switch]$Apply,
    [switch]$AutoStash
)

$ErrorActionPreference = "Stop"

function Write-Info($Message) {
    Write-Host "[git-auto-sync] $Message"
}

function Fail($Message) {
    Write-Host "[git-auto-sync] BLOQUEADO: $Message" -ForegroundColor Red
    exit 1
}

$repo = git rev-parse --show-toplevel 2>$null
if (-not $repo) {
    Fail "Execute dentro de um repositorio Git."
}

Set-Location $repo

$branch = git branch --show-current
if (-not $branch) {
    Fail "Repositorio em detached HEAD. Nao sincronizar automaticamente."
}

Write-Info "Repositorio: $repo"
Write-Info "Branch: $branch"

git fetch --prune origin

$status = git status --porcelain
$upstream = git rev-parse --abbrev-ref --symbolic-full-name "@{u}" 2>$null

if (-not $upstream) {
    Write-Info "Sem upstream configurado. Para configurar:"
    Write-Info "git push -u origin $branch"
    exit 0
}

$counts = git rev-list --left-right --count "HEAD...$upstream"
$parts = $counts -split "\s+"
$ahead = [int]$parts[0]
$behind = [int]$parts[1]

Write-Info "Upstream: $upstream | ahead=$ahead | behind=$behind"

if ($status) {
    Write-Info "Existem alteracoes locais. Pull automatico bloqueado para evitar perda."
    git status --short
    if (-not $AutoStash) {
        Write-Info "Use -AutoStash somente se quiser permitir git pull --rebase --autostash."
        exit 1
    }
}

if ($behind -eq 0) {
    Write-Info "Nada para puxar."
    exit 0
}

if (-not $Apply) {
    Write-Info "Dry-run: execute com -Apply para rodar pull --rebase."
    exit 0
}

$backupBranch = "git-auto-sync-backup/$($branch)-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
git branch $backupBranch HEAD
Write-Info "Backup criado: $backupBranch"

$argsPull = @("pull", "--rebase")
if ($AutoStash) {
    $argsPull += "--autostash"
}

git @argsPull

if ($LASTEXITCODE -ne 0) {
    Fail "Pull/rebase falhou. Resolva conflitos manualmente. Backup: $backupBranch"
}

Write-Info "Sincronizacao concluida."
