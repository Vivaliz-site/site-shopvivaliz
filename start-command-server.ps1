# Script para iniciar o Servidor de Comandos
# Uso: .\start-command-server.ps1 [-Ip "192.168.x.x"] [-Port 5557]

param(
    [string]$Ip = "127.0.0.1",  # Mudar para IP da rede se acessar do iPhone
    [int]$Port = 5557,
    [string]$Token = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
)

Write-Host @"
┌─────────────────────────────────────────────────────────────┐
│        COMANDO SEGURO - SERVIDOR DE EXECUÇÃO               │
└─────────────────────────────────────────────────────────────┘

⚙️  CONFIGURAÇÃO:
  IP:    $Ip
  Porta: $Port
  Token: $Token

📱 Para acessar do iPhone:
  1. Mudar IP para seu IP local:
     .\start-command-server.ps1 -Ip "192.168.x.x"

  2. Configurar na rede (não use 127.0.0.1)

  3. Teste com curl:
     curl -X POST http://192.168.x.x:$Port/execute \
       -H "Authorization: Bearer $Token" \
       -H "Content-Type: application/json" \
       -d '{\"cmd\": \"git status\", \"timeout\": 30}'

⚠️  SEGURANÇA:
  - Servidor roda em HTTP (não HTTPS)
  - Token em plaintext na URL
  - Use apenas em rede local confiável
  - Considere usar VPN para acesso remoto

"@

$env:COMMAND_SERVER_TOKEN = $Token
node secure-command-server.js
