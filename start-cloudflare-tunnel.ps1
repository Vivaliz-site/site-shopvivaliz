# Script para iniciar Cloudflare Tunnel + RCE Server
# Acesso remoto seguro via 4G

Write-Host @"
╔══════════════════════════════════════════════════════════════════╗
║              CLOUDFLARE TUNNEL + RCE SERVER                      ║
║                                                                  ║
║  Configurando acesso remoto seguro via HTTPS...                 ║
╚══════════════════════════════════════════════════════════════════╝
"@

# Verificar se cloudflared existe
if (-not (Test-Path ".\cloudflared.exe")) {
    Write-Host "❌ cloudflared.exe não encontrado"
    Write-Host "📥 Baixando..."
    curl -L --output cloudflared.exe https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
}

# Verificar se RCE Server está rodando
Write-Host ""
Write-Host "🔍 Verificando RCE Server..."
try {
    $response = Invoke-WebRequest -Uri "http://127.0.0.1:5557/status" `
        -Headers @{"Authorization" = "Bearer hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"} `
        -Method GET `
        -TimeoutSec 2 `
        -ErrorAction Stop

    if ($response.StatusCode -eq 200) {
        Write-Host "✅ RCE Server rodando em http://127.0.0.1:5557"
    }
} catch {
    Write-Host "⚠️  RCE Server não respondeu. Iniciando..."
    .\start-rce-bg.ps1
}

# Iniciar Cloudflare Tunnel
Write-Host ""
Write-Host "🚀 Iniciando Cloudflare Tunnel..."
Write-Host ""
Write-Host "📋 INSTRUÇÕES:"
Write-Host "  1. O tunnel vai conectar ao Cloudflare"
Write-Host "  2. Você receberá uma URL HTTPS pública"
Write-Host "  3. Use essa URL no iPhone para acessar o RCE Server"
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════════════════"
Write-Host ""

# Executar tunnel
.\cloudflared.exe tunnel --config cloudflare-tunnel.yml run site-shopvivaliz-rce
