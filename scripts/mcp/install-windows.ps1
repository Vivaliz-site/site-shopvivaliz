param(
    [Parameter(Mandatory=$true)][string]$VmHost,
    [string]$VmUser = "shopvivaliz",
    [string]$RemoteRoot = "/var/www/shopvivaliz",
    [string]$ClientConfigPath = "$env:APPDATA\Claude\claude_desktop_config.json"
)

$ErrorActionPreference = "Stop"

if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
    throw "OpenSSH Client nao encontrado. Instale o recurso opcional OpenSSH Client do Windows."
}

$configDir = Split-Path -Parent $ClientConfigPath
New-Item -ItemType Directory -Force -Path $configDir | Out-Null

$config = @{
    mcpServers = @{
        shopvivaliz_vm = @{
            command = "ssh"
            args = @(
                "-T",
                "-o", "BatchMode=yes",
                "-o", "ServerAliveInterval=30",
                "$VmUser@$VmHost",
                "env SHOPVIVALIZ_ROOT=$RemoteRoot python3 $RemoteRoot/scripts/mcp/shopvivaliz_mcp_server.py"
            )
        }
    }
}

$config | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 -Path $ClientConfigPath

Write-Host "Testando conexao SSH..."
ssh -T -o BatchMode=yes -o ConnectTimeout=10 "$VmUser@$VmHost" "printf '%s\n' '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{}}' | env SHOPVIVALIZ_ROOT=$RemoteRoot python3 $RemoteRoot/scripts/mcp/shopvivaliz_mcp_server.py"

Write-Host "Configuracao MCP gravada em: $ClientConfigPath"
Write-Host "Reinicie o cliente de IA para carregar shopvivaliz_vm."
