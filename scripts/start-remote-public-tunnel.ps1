$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoRoot = Split-Path -Parent $PSScriptRoot
$DataDir = Join-Path $RepoRoot 'storage\remote-access'
$LogsDir = Join-Path $RepoRoot 'logs'
$GatewayUrl = 'http://127.0.0.1:5560'
$CloudflaredExe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$PublicTunnelFile = Join-Path $DataDir 'public-tunnel.json'
$StdOutLog = Join-Path $LogsDir 'cloudflared-public-tunnel.out.log'
$StdErrLog = Join-Path $LogsDir 'cloudflared-public-tunnel.err.log'

if (-not (Test-Path $CloudflaredExe)) {
    throw "cloudflared não encontrado em $CloudflaredExe"
}

if (-not (Test-Path $DataDir)) {
    New-Item -ItemType Directory -Path $DataDir | Out-Null
}

if (-not (Test-Path $LogsDir)) {
    New-Item -ItemType Directory -Path $LogsDir | Out-Null
}

Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
Remove-Item $StdOutLog, $StdErrLog -Force -ErrorAction SilentlyContinue

$process = Start-Process `
    -FilePath $CloudflaredExe `
    -ArgumentList @('tunnel', '--url', $GatewayUrl) `
    -WorkingDirectory $RepoRoot `
    -WindowStyle Hidden `
    -RedirectStandardOutput $StdOutLog `
    -RedirectStandardError $StdErrLog `
    -PassThru

$publicUrl = ''
for ($i = 0; $i -lt 60; $i++) {
    Start-Sleep -Seconds 1
    $parts = @()
    foreach ($candidate in @($StdOutLog, $StdErrLog)) {
        if (Test-Path $candidate) {
            $raw = Get-Content $candidate -Raw
            if ($raw) {
                $parts += $raw
            }
        }
    }

    if ($parts.Count -eq 0) {
        continue
    }

    $content = [string]::Join("`n", $parts)
    $match = [regex]::Match($content, 'https://[a-z0-9-]+\.trycloudflare\.com')
    if ($match.Success) {
        $publicUrl = $match.Value
        break
    }
}

if (-not $publicUrl) {
    throw "Não foi possível detectar a URL pública do Quick Tunnel. Verifique $StdOutLog e $StdErrLog"
}

$payload = [pscustomobject]@{
    timestamp = (Get-Date).ToUniversalTime().ToString('o')
    public_url = $publicUrl
    gateway_url = $GatewayUrl
    process_id = $process.Id
    stdout_log = $StdOutLog
    stderr_log = $StdErrLog
}

$payload | ConvertTo-Json -Depth 10 | Set-Content $PublicTunnelFile -Encoding UTF8

Write-Host "Public tunnel: $publicUrl" -ForegroundColor Green
Write-Host "Gateway:       $GatewayUrl" -ForegroundColor Green
Write-Host "PID:           $($process.Id)" -ForegroundColor Green
