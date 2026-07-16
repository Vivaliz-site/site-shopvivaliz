param(
    [switch]$Bootstrap,
    [string]$Repos = "all"
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$pythonScript = Join-Path $scriptDir "setup-auto-deploy.py"
$credsFile = Join-Path $scriptDir ".ftp-credentials"
$requiredEnv = @("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD", "FTP_PORT", "FTP_REMOTE_DIR", "GITHUB_TOKEN")
$hasEnvCreds = ($requiredEnv | Where-Object { -not [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($_)) }).Count -eq $requiredEnv.Count

if (-not (Test-Path $pythonScript)) {
    throw "Script nao encontrado: $pythonScript"
}

$args = @($pythonScript, "--repos", $Repos)

if (-not $Bootstrap -and ((Test-Path $credsFile) -or $hasEnvCreds)) {
    $args += "--non-interactive"
} elseif (-not $Bootstrap) {
    Write-Host "Credenciais automaticas ausentes. Entrando em modo bootstrap." -ForegroundColor Yellow
}

Write-Host "Executando setup de deploy autonomo..." -ForegroundColor Cyan
Write-Host ("Repositorios: " + $Repos) -ForegroundColor Cyan
if ($args -contains "--non-interactive") {
    Write-Host "Modo: nao interativo" -ForegroundColor Green
} else {
    Write-Host "Modo: bootstrap interativo" -ForegroundColor Yellow
}

& python @args
exit $LASTEXITCODE
