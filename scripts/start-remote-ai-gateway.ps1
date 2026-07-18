$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
$GatewayScript = Join-Path $RepoRoot 'scripts\ai-remote-gateway.py'
$LogDir = Join-Path $RepoRoot 'logs'
$DataDir = Join-Path $RepoRoot 'storage\remote-access'
$ConfigFile = Join-Path $RepoRoot 'mcp-servers.json'
$Port = 5560
$FirewallRuleName = 'ShopVivaliz Remote AI Gateway 5560'

function Get-TailscaleStatus {
    $tailscaleExe = 'C:\Program Files\Tailscale\tailscale.exe'
    if (-not (Test-Path $tailscaleExe)) {
        return $null
    }

    try {
        $status = & $tailscaleExe status --json | ConvertFrom-Json
        if ($status.BackendState -eq 'Running' -and $status.Self) {
            return $status
        }
    } catch {
    }

    return $null
}

function Get-PrimaryHost {
    $tailscaleStatus = Get-TailscaleStatus
    if ($tailscaleStatus) {
        $tsIp = $tailscaleStatus.Self.TailscaleIPs | Select-Object -First 1
        if ($tsIp) {
            return $tsIp
        }
    }

    try {
        $lanIp = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
            Where-Object {
                $_.IPAddress -notlike '127.*' -and
                $_.IPAddress -notlike '169.254.*' -and
                $_.ValidLifetime -ne ([TimeSpan]::Zero)
            } |
            Sort-Object InterfaceMetric |
            Select-Object -ExpandProperty IPAddress -First 1
        if ($lanIp) {
            return $lanIp
        }
    } catch {
    }

    return '127.0.0.1'
}

function Ensure-GatewayFirewallRule {
    param(
        [Parameter(Mandatory=$true)][int]$GatewayPort
    )

    $existing = Get-NetFirewallRule -DisplayName $FirewallRuleName -ErrorAction SilentlyContinue
    if (-not $existing) {
        New-NetFirewallRule `
            -DisplayName $FirewallRuleName `
            -Direction Inbound `
            -Action Allow `
            -Protocol TCP `
            -LocalPort $GatewayPort | Out-Null
        return
    }

    Set-NetFirewallRule -DisplayName $FirewallRuleName -Enabled True -Action Allow | Out-Null
}

function Update-GatewayConfig {
    param(
        [Parameter(Mandatory=$true)][string]$HostName,
        [Parameter(Mandatory=$true)][int]$GatewayPort,
        [string]$MagicDnsName = ''
    )

    if (-not (Test-Path $ConfigFile)) {
        return
    }

    $config = Get-Content $ConfigFile -Raw | ConvertFrom-Json
    if (-not $config.servers) {
        $config | Add-Member -NotePropertyName servers -NotePropertyValue ([pscustomobject]@{})
    }

    $gateway = [pscustomobject]@{
        url = "http://$HostName`:$GatewayPort"
        environment = 'remote-ai-gateway'
        location = 'Local PC via Tailscale/LAN'
        hostname = $env:COMPUTERNAME
        ip = $HostName
        magicdns = $MagicDnsName
        enabled = $true
        auto_start = $true
        auth = 'X-API-Key'
    }

    $config.servers | Add-Member -Force -NotePropertyName 'remote-ai-gateway' -NotePropertyValue $gateway
    $config.updated = (Get-Date).ToUniversalTime().ToString('o')
    $config | ConvertTo-Json -Depth 10 | Set-Content $ConfigFile -Encoding UTF8
}

if (-not (Test-Path $GatewayScript)) {
    throw "Gateway script não encontrado: $GatewayScript"
}

if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir | Out-Null
}

if (-not (Test-Path $DataDir)) {
    New-Item -ItemType Directory -Path $DataDir | Out-Null
}

Ensure-GatewayFirewallRule -GatewayPort $Port

$stdout = Join-Path $LogDir 'ai-remote-gateway.out.log'
$stderr = Join-Path $LogDir 'ai-remote-gateway.err.log'

$existing = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if (-not $existing) {
    Start-Process -FilePath (Get-Command python.exe).Source `
        -ArgumentList @($GatewayScript, '--host', '0.0.0.0', '--port', $Port) `
        -WorkingDirectory $RepoRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr | Out-Null
}

$apiKeyFile = Join-Path $DataDir 'api-key.txt'
$health = $null
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Seconds 1
    if (Test-Path $apiKeyFile) {
        $apiKey = (Get-Content $apiKeyFile -Raw).Trim()
        if ($apiKey) {
            try {
                $health = Invoke-RestMethod -Uri "http://127.0.0.1:$Port/health" -Headers @{ 'X-API-Key' = $apiKey } -TimeoutSec 5
                if ($health.ok) {
                    break
                }
            } catch {
            }
        }
    }
}

$hostName = Get-PrimaryHost
$tailscaleStatus = Get-TailscaleStatus
$magicDnsName = if ($tailscaleStatus -and $tailscaleStatus.Self.DNSName) {
    ($tailscaleStatus.Self.DNSName).TrimEnd('.')
} else {
    ''
}

Update-GatewayConfig -HostName $hostName -GatewayPort $Port -MagicDnsName $magicDnsName

$apiKey = if (Test-Path $apiKeyFile) { (Get-Content $apiKeyFile -Raw).Trim() } else { '' }

Write-Host "Gateway: http://$hostName`:$Port" -ForegroundColor Green
Write-Host "Health:  http://127.0.0.1:$Port/health" -ForegroundColor Green
Write-Host "Auth:    X-API-Key" -ForegroundColor Green
if ($apiKey) {
    Write-Host "API key file: $apiKeyFile" -ForegroundColor Yellow
}
if ($health) {
    Write-Host "Health check OK: $($health.status)" -ForegroundColor Green
} else {
    Write-Host "Health check ainda não confirmou. Verifique os logs em $stdout e $stderr" -ForegroundColor Yellow
}

if ($magicDnsName) {
    Write-Host "MagicDNS: http://$magicDnsName`:$Port" -ForegroundColor Green
}

if ($hostName -eq '127.0.0.1') {
    Write-Host "Tailscale ainda não forneceu IP válido; o gateway está pronto na rede local." -ForegroundColor Yellow
} else {
    Write-Host "O endereço acima já pode ser usado pelos agentes no celular/PC na mesma rede." -ForegroundColor Green
}
