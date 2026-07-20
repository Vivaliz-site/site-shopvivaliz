# Script de instalação de Docker Desktop e Ollama
# Execute como ADMINISTRADOR

Write-Host "╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║ INSTALLAÇÃO: Docker Desktop + Ollama + IA     ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan

# Verificar privilégios de admin
$isAdmin = ([System.Security.Principal.WindowsIdentity]::GetCurrent().Groups -contains `
  [System.Security.Principal.SecurityIdentifier]"S-1-5-32-544")

if (-not $isAdmin) {
  Write-Host "❌ ERRO: Este script requer privilégios de ADMINISTRADOR" -ForegroundColor Red
  Write-Host "Relance PowerShell como administrador e tente novamente." -ForegroundColor Yellow
  exit 1
}

Write-Host "✅ Privilégios de admin detectados" -ForegroundColor Green

# Instalar Docker Desktop
Write-Host "`n[1/3] Instalando Docker Desktop..." -ForegroundColor Yellow
winget install -e --id Docker.DockerDesktop -h --accept-package-agreements --accept-source-agreements

# Esperar Docker ficar pronto
Write-Host "`n⏳ Aguardando Docker inicializar (pode levar 2-3 minutos)..." -ForegroundColor Yellow
Start-Sleep -Seconds 30

# Instalar Ollama
Write-Host "`n[2/3] Instalando Ollama..." -ForegroundColor Yellow
winget install -e --id Ollama.Ollama -h --accept-package-agreements --accept-source-agreements

# Esperar Ollama ficar pronto
Write-Host "`n⏳ Aguardando Ollama inicializar..." -ForegroundColor Yellow
Start-Sleep -Seconds 10

# Verificar instalações
Write-Host "`n[3/3] Verificando instalações..." -ForegroundColor Yellow

$docker_check = Get-Command docker -ErrorAction SilentlyContinue
$ollama_check = Get-Command ollama -ErrorAction SilentlyContinue

if ($docker_check -and $ollama_check) {
  Write-Host "✅ Docker Desktop instalado com sucesso" -ForegroundColor Green
  Write-Host "✅ Ollama instalado com sucesso" -ForegroundColor Green
  
  Write-Host "`n📋 Próximos passos:" -ForegroundColor Cyan
  Write-Host "1. Execute: ollama pull mistral:7b-instruct-q4_K_M" -ForegroundColor White
  Write-Host "2. Aguarde o download (~4.1 GB)" -ForegroundColor White
  Write-Host "3. Teste: ollama run mistral" -ForegroundColor White
  
} else {
  Write-Host "⚠️ Verificação incompleta. Reinicie o computador e tente novamente." -ForegroundColor Yellow
}

Write-Host "`n✨ Installação concluída!" -ForegroundColor Green
