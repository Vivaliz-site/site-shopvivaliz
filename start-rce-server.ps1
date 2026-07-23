#!/usr/bin/env pwsh
# ⚠️  RCE SERVER - SEM PROTEÇÃO - USE COM CUIDADO

param(
    [string]$Ip = "127.0.0.1",
    [int]$Port = 5557,
    [string]$Token = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
)

Write-Host @"
╔═══════════════════════════════════════════════════════════════╗
║                  🔴 RCE SERVER SEM RESTRIÇÕES                ║
║                                                               ║
║  AVISO CRÍTICO DE SEGURANÇA:                                 ║
║  - Executa QUALQUER comando                                  ║
║  - Se token vazar → Controle total do PC                    ║
║  - Use APENAS em rede WiFi privada                          ║
║  - NUNCA em redes públicas ou internet                      ║
╚═══════════════════════════════════════════════════════════════╝

⚙️  CONFIGURAÇÃO:
  IP:    $Ip
  Porta: $Port
  Token: $Token

📱 Para usar do iPhone:
  1. Descobrir seu IP:
     ipconfig | findstr IPv4
     (procure por "192.168.x.x" ou "10.0.x.x")

  2. Iniciar servidor naquele IP:
     .\start-rce-server.ps1 -Ip "192.168.1.100"

  3. No iPhone (Shortcuts):
     POST http://192.168.1.100:$Port/execute
     Header: Authorization: Bearer $Token
     Body: {"cmd": "dir", "timeout": 30}

"@

# Perguntar confirmação
$confirm = Read-Host "Tem CERTEZA que quer ativar RCE sem restrições? (sim/nao)"
if ($confirm -ne "sim") {
    Write-Host "❌ Cancelado"
    exit
}

# Iniciar servidor
$env:PORT = $Port
$env:BIND_IP = $Ip
$env:COMMAND_SERVER_TOKEN = $Token

Write-Host "`n✅ Iniciando servidor..." -ForegroundColor Green
node rce-command-server.js
