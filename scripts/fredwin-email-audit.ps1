$ErrorActionPreference = 'Continue'
$repo = 'C:\mei-mg-email'
Write-Output '=== EMAIL_AUDIT_BEGIN ==='
Write-Output ("timestamp=" + (Get-Date -Format o))
Write-Output ("repo_exists=" + (Test-Path $repo))
if (-not (Test-Path $repo)) { exit 4 }
Set-Location $repo

try { Write-Output ("git_head=" + (git rev-parse --short HEAD 2>$null)) } catch {}
try { Write-Output ("git_branch=" + (git branch --show-current 2>$null)) } catch {}

$envPath = Join-Path $repo '.env'
Write-Output ("env_exists=" + (Test-Path $envPath))
if (Test-Path $envPath) {
  $names = @('EMAIL_PROVIDER','MICROSOFT_TENANT_ID','MICROSOFT_CLIENT_ID','MICROSOFT_CLIENT_SECRET','MICROSOFT_SENDER','GRAPH_TENANT_ID','GRAPH_CLIENT_ID','GRAPH_CLIENT_SECRET','GRAPH_SENDER','DATABASE_URL','DAILY_SEND_LIMIT')
  $lines = Get-Content $envPath -ErrorAction SilentlyContinue
  foreach ($name in $names) {
    $present = [bool]($lines | Where-Object { $_ -match ('^\s*' + [regex]::Escape($name) + '\s*=\s*\S+') })
    Write-Output ("env_" + $name + "_present=" + $present)
  }
}

Write-Output ("template_html_exists=" + (Test-Path (Join-Path $repo 'templates')))
try {
  $providerHits = Get-ChildItem -Path $repo -Recurse -File -Include *.py,*.md,*.yml,*.yaml -ErrorAction SilentlyContinue |
    Select-String -Pattern 'graph.microsoft.com|sendMail|MicrosoftGraph|EMAIL_PROVIDER|9950|10000' -SimpleMatch:$false -ErrorAction SilentlyContinue |
    Select-Object -First 30
  foreach ($hit in $providerHits) { Write-Output ("hit=" + $hit.Path.Replace($repo+'\','') + ':' + $hit.LineNumber + ':' + $hit.Line.Trim()) }
} catch {}

try {
  $docker = docker compose ps --format json 2>$null
  if ($LASTEXITCODE -eq 0) { Write-Output 'docker_compose_available=True'; $docker | Write-Output } else { Write-Output 'docker_compose_available=False' }
} catch { Write-Output 'docker_compose_available=False' }

try {
  if (Test-Path '.\.venv\Scripts\python.exe') {
    Write-Output 'venv_python=True'
    & .\.venv\Scripts\python.exe -m pytest -q --disable-warnings --maxfail=1 2>&1 | Select-Object -Last 40 | Write-Output
    Write-Output ("pytest_exit=" + $LASTEXITCODE)
  } else {
    Write-Output 'venv_python=False'
  }
} catch { Write-Output ("pytest_exception=" + $_.Exception.Message) }

Write-Output '=== EMAIL_AUDIT_END ==='
