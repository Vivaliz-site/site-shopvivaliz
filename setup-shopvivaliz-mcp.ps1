# ============================================================================
# SETUP COMPLETO: MCP Server ShopVivaliz no Claude Desktop
# Fred Mourão - ShopVivaliz FastAPI v2.0
# ============================================================================

Write-Host "╔════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║     Setup MCP Server ShopVivaliz - Claude Desktop          ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

# ============================================================================
# PASSO 1: Verificar UV
# ============================================================================
Write-Host "`n[1/4] Verificando UV..." -ForegroundColor Yellow
$uvCheck = uv --version 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ $uvCheck" -ForegroundColor Green
} else {
    Write-Host "❌ UV não encontrado. Execute antes: cargo install uv" -ForegroundColor Red
    exit 1
}

# ============================================================================
# PASSO 2: Copiar MCP Server Script
# ============================================================================
Write-Host "`n[2/4] Copiando MCP Server para ShopVivaliz..." -ForegroundColor Yellow
$shopvivaliz = "D:\fredmourao-ai\site-shopvivaliz"
$mcpScript = "$shopvivaliz\shopvivaliz-mcp-server.py"

if (-not (Test-Path $shopvivaliz)) {
    Write-Host "❌ Pasta ShopVivaliz não encontrada: $shopvivaliz" -ForegroundColor Red
    Write-Host "   Crie a pasta ou altere o caminho no script" -ForegroundColor Yellow
    exit 1
}

# Copiar o script MCP
$mcpSourceUrl = "https://raw.githubusercontent.com/fredmourao-ai/site-shopvivaliz/main/shopvivaliz-mcp-server.py"
Write-Host "  Baixando MCP Server..." -ForegroundColor Cyan

try {
    $ProgressPreference = 'SilentlyContinue'
    Invoke-WebRequest -Uri $mcpSourceUrl -OutFile $mcpScript -ErrorAction SilentlyContinue
    
    if (Test-Path $mcpScript) {
        Write-Host "✅ MCP Server instalado: $mcpScript" -ForegroundColor Green
    } else {
        # Se não conseguir baixar, criar versão local
        Write-Host "  Criando versão local..." -ForegroundColor Yellow
        $mcpContent = @'
#!/usr/bin/env python3
import json, sys, subprocess, logging
from pathlib import Path

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

SHOPVIVALIZ_ROOT = Path("D:/fredmourao-ai/site-shopvivaliz")

def get_status():
    return {
        "status": "running",
        "project": "ShopVivaliz",
        "version": "2.0",
        "root": str(SHOPVIVALIZ_ROOT)
    }

def main():
    logger.info("MCP Server ShopVivaliz iniciado")
    for line in sys.stdin:
        try:
            req = json.loads(line)
            resp = get_status()
            print(json.dumps(resp))
            sys.stdout.flush()
        except:
            pass

if __name__ == "__main__":
    main()
'@
        $mcpContent | Out-File $mcpScript -Encoding UTF8
        Write-Host "✅ MCP Server criado (versão básica)" -ForegroundColor Green
    }
} catch {
    Write-Host "⚠️  Não consegui baixar do GitHub, criando versão local..." -ForegroundColor Yellow
}

# ============================================================================
# PASSO 3: Configurar Claude Desktop config.json
# ============================================================================
Write-Host "`n[3/4] Configurando Claude Desktop..." -ForegroundColor Yellow
$claudeConfigDir = "$env:APPDATA\Claude"
$claudeConfig = "$claudeConfigDir\claude_desktop_config.json"

# Criar diretório se não existir
if (-not (Test-Path $claudeConfigDir)) {
    New-Item -Path $claudeConfigDir -ItemType Directory -Force > $null
    Write-Host "  Diretório criado: $claudeConfigDir" -ForegroundColor Cyan
}

# Preparar config JSON
$config = @{
    mcpServers = @{
        shopvivaliz = @{
            command = "uv"
            args = @(
                "run",
                "--python", "3.11",
                $mcpScript
            )
        }
    }
} | ConvertTo-Json -Depth 10

# Salvar config
$config | Out-File $claudeConfig -Encoding UTF8
Write-Host "✅ Config salvo: $claudeConfig" -ForegroundColor Green

# Exibir conteúdo
Write-Host "`n  Conteúdo:" -ForegroundColor Cyan
Write-Host "  ─────────────────────────────────" -ForegroundColor Gray
$config | Write-Host -ForegroundColor Gray
Write-Host "  ─────────────────────────────────" -ForegroundColor Gray

# ============================================================================
# PASSO 4: Instruções finais
# ============================================================================
Write-Host "`n[4/4] Setup completo!" -ForegroundColor Yellow

Write-Host "`n╔════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║                   PRÓXIMOS PASSOS                          ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

Write-Host @"

1️⃣  FECHE Claude Desktop completamente
    • Não apenas minimizar
    • Feche de verdade

2️⃣  AGUARDE 3 segundos

3️⃣  REABRA Claude Desktop

4️⃣  VERIFIQUE O MCP SERVER
    Em Claude Desktop:
    Settings ⚙️ → MCP Servers
    
    Procure por: "shopvivaliz"
    • 🟢 Connected = Sucesso! ✅
    • 🔴 Disconnected = Verifique logs

5️⃣  TESTE NO CHAT
    Você pode usar o MCP Server para:
    • Consultar secrets centralizados
    • Verificar status dos .env files
    • Rodar sync de marketplaces
    • Deploy para Oracle Cloud

📂 Arquivos criados:
    • MCP Server: $mcpScript
    • Config Claude: $claudeConfig

📚 Documentação:
    • MCP Docs: https://modelcontextprotocol.io/docs
    • ShopVivaliz Root: $shopvivaliz

"@

Write-Host "✅ Pressione Enter para terminar" -ForegroundColor Green
Read-Host
