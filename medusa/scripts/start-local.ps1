$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $root "docker-compose.local.yml"

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "Docker nao encontrado. Instale o Docker Desktop para subir Postgres e Redis." -ForegroundColor Yellow
    exit 1
}

Write-Host "Subindo Postgres e Redis do Medusa..." -ForegroundColor Cyan
docker compose -f $composeFile up -d

Write-Host "Infra local pronta. Agora rode os apps em terminais separados:" -ForegroundColor Green
Write-Host "  cd medusa; pnpm backend:dev"
Write-Host "  cd medusa; pnpm storefront:dev"
