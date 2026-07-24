# Script para gerar Device ID único para seu iPhone
# E configurar autenticação por device

Write-Host @"
╔══════════════════════════════════════════════════════════════════╗
║           SETUP DEVICE AUTHENTICATION - IPHONE ONLY              ║
║                                                                  ║
║  Isso configura RCE Server para aceitar APENAS seu iPhone       ║
╚══════════════════════════════════════════════════════════════════╝
"@

# Gerar Device ID único
$deviceId = "iphone-$(New-Guid | ForEach-Object {$_.ToString().Replace('-','')})"

Write-Host ""
Write-Host "📱 DEVICE ID GERADO:"
Write-Host ""
Write-Host "   $deviceId"
Write-Host ""
Write-Host "═════════════════════════════════════════════════════════════"
Write-Host ""

# Mostrar como usar
Write-Host "🚀 COMO USAR NO IPHONE:"
Write-Host ""
Write-Host "  1. Crie um Shortcut com Web Request:"
Write-Host ""
Write-Host "     POST https://seu-url.cloudflare-tunnel.com/execute"
Write-Host ""
Write-Host "     Headers:"
Write-Host "     - Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
Write-Host "     - X-Device-ID: $deviceId"
Write-Host ""
Write-Host "     Body (JSON):"
Write-Host "     {\"cmd\": \"dir\", \"timeout\": 30}"
Write-Host ""
Write-Host "═════════════════════════════════════════════════════════════"
Write-Host ""

# Atualizar arquivo do servidor
Write-Host "⚙️  Atualizando configuração..."

$configFile = "rce-command-server-device-auth.js"
$content = Get-Content $configFile -Raw

# Substituir Device ID
$newContent = $content -replace `
    'ALLOWED_DEVICES = \{[^}]*\}', `
    @"
ALLOWED_DEVICES = {
  "iPhone Pessoal": "$deviceId",
}
"@

$newContent | Set-Content $configFile
Write-Host "✅ Device ID configurado no servidor"
Write-Host ""

# Instruções finais
Write-Host "📋 PRÓXIMOS PASSOS:"
Write-Host ""
Write-Host "  1. Parar servidor atual:"
Write-Host "     Get-Process node | Stop-Process"
Write-Host ""
Write-Host "  2. Iniciar novo servidor com Device Auth:"
Write-Host "     node rce-command-server-device-auth.js"
Write-Host ""
Write-Host "  3. Usar URL + Headers no iPhone:"
Write-Host "     - Authorization: Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
Write-Host "     - X-Device-ID: $deviceId"
Write-Host ""
Write-Host "═════════════════════════════════════════════════════════════"
Write-Host ""
Write-Host "🔐 Agora APENAS SEU IPHONE consegue acessar!"
Write-Host ""
