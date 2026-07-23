#!/usr/bin/env powershell
<#
.SYNOPSIS
  Testa se o iOS Command Listener está funcionando

.USAGE
  .\scripts\test-listener.ps1

.EXAMPLES
  PS> .\scripts\test-listener.ps1

  Vai fazer um POST request para localhost:9999 e mostrar o resultado
#>

param(
    [string]$Command = "git status",
    [int]$Timeout = 30,
    [string]$Host = "localhost",
    [int]$Port = 9999
)

$url = "http://$Host`:$Port/execute"
$token = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"

Write-Host "`n🧪 TESTE: iOS Command Listener" -ForegroundColor Cyan
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host ""
Write-Host "📍 URL: $url"
Write-Host "🔐 Token: $token"
Write-Host "⚙️  Comando: $Command"
Write-Host "⏱️  Timeout: $Timeout segundos"
Write-Host ""

try {
    Write-Host "🚀 Enviando request..." -ForegroundColor Yellow

    $body = @{
        cmd = $Command
        timeout = $Timeout
    } | ConvertTo-Json

    $response = Invoke-WebRequest -Uri $url `
        -Method POST `
        -Headers @{"X-Token" = $token; "Content-Type" = "application/json"} `
        -Body $body `
        -TimeoutSec 60 `
        -SkipHttpErrorCheck

    $result = $response.Content | ConvertFrom-Json

    Write-Host ""
    Write-Host "✅ RESPOSTA RECEBIDA" -ForegroundColor Green
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Green
    Write-Host ""

    if ($result.success) {
        Write-Host "✨ Status: SUCCESS" -ForegroundColor Green
    } else {
        Write-Host "❌ Status: FAILED" -ForegroundColor Red
    }

    Write-Host "📤 Output:"
    Write-Host ($result.output -split "`n" | ForEach-Object { "   $_" })

    if ($result.error) {
        Write-Host "❌ Error:"
        Write-Host ($result.error -split "`n" | ForEach-Object { "   $_" })
    }

    Write-Host "⏱️  Duration: $($result.duration) ms"
    Write-Host "🕐 Timestamp: $($result.timestamp)"
    Write-Host ""

} catch [System.Net.Http.HttpRequestException] {
    Write-Host ""
    Write-Host "❌ ERRO DE CONEXÃO" -ForegroundColor Red
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Red
    Write-Host ""
    Write-Host "📍 Não consegui conectar em $url"
    Write-Host ""
    Write-Host "Possíveis causas:" -ForegroundColor Yellow
    Write-Host "  1. Listener não está rodando"
    Write-Host "  2. Porta 9999 está bloqueada"
    Write-Host "  3. IP/Host está errado"
    Write-Host ""
    Write-Host "Solução:" -ForegroundColor Yellow
    Write-Host "  1. Abra outro terminal PowerShell"
    Write-Host "  2. Execute: node scripts/ios-command-listener.js"
    Write-Host "  3. Tente este teste novamente"
    Write-Host ""
} catch {
    Write-Host ""
    Write-Host "❌ ERRO INESPERADO" -ForegroundColor Red
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Red
    Write-Host ""
    Write-Host "Erro: $($_.Exception.Message)"
    Write-Host ""
}
