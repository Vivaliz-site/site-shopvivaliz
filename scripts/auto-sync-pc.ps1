param(
  [string]$RepoPath = "C:\Users\user\site-shopvivaliz",
  [string]$BaseBranch = "main"
)

Set-Location $RepoPath
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$logDir = Join-Path $RepoPath "logs"
New-Item -ItemType Directory -Force $logDir | Out-Null
$log = Join-Path $logDir "auto-sync-pc.log"

function Log($m) {
  "[$(Get-Date -Format s)] $m" | Tee-Object -FilePath $log -Append
}

Log "Inicio sync PC"

git fetch origin

$status = git status --porcelain

if ($status) {
  $branch = "auto/pc-$stamp"
  git checkout -b $branch
  git add -A
  git commit -m "chore: auto sync pc $stamp"
  git push -u origin $branch
  gh pr create --draft --base $BaseBranch --head $branch --title "Auto sync PC $stamp" --body "Sincronizacao automatica do PC. Revisar antes de mesclar."
  Log "Alteracoes locais enviadas para PR: $branch"
  exit 0
}

git checkout $BaseBranch
git pull --ff-only origin $BaseBranch
Log "PC atualizado por fast-forward"
