param(
    [string]$TenantId = 'cc55b801-12c2-4ea2-a930-d639aa8988a4',
    [string]$ClientId = 'a5e400f0-969e-4fbe-be61-d390cb112517',
    [string]$Subject = 'CN=ShopVivalizExchangeAuth'
)

$ErrorActionPreference = 'Stop'
Import-Module Microsoft.PowerShell.Security -ErrorAction Stop
Import-Module PKI -ErrorAction Stop

if (-not (Get-PSDrive -Name Cert -ErrorAction SilentlyContinue)) {
    throw 'Windows Certificate provider is unavailable in this PowerShell session'
}

$certDir = 'C:\Certs'
$cerPath = Join-Path $certDir 'ShopVivalizExchangeAuth.cer'
New-Item -ItemType Directory -Force -Path $certDir | Out-Null

$existing = Get-ChildItem Cert:\CurrentUser\My -ErrorAction SilentlyContinue |
    Where-Object { $_.Subject -eq $Subject -and $_.HasPrivateKey -and $_.NotAfter -gt (Get-Date).AddDays(30) } |
    Sort-Object NotAfter -Descending |
    Select-Object -First 1

if (-not $existing) {
    $existing = New-SelfSignedCertificate `
        -Subject $Subject `
        -CertStoreLocation 'Cert:\CurrentUser\My' `
        -KeyAlgorithm RSA `
        -KeyLength 2048 `
        -HashAlgorithm SHA256 `
        -KeyExportPolicy NonExportable `
        -KeySpec Signature `
        -NotAfter (Get-Date).AddYears(5)
}

Export-Certificate -Cert $existing -FilePath $cerPath -Force | Out-Null

$envPath = 'C:\mei-mg-email\.env'
if (Test-Path $envPath) {
    $lines = Get-Content $envPath
    $remove = @(
        'MICROSOFT_GRAPH_TENANT_ID',
        'MICROSOFT_GRAPH_CLIENT_ID',
        'MICROSOFT_GRAPH_AUTH_MODE',
        'MICROSOFT_GRAPH_CERT_THUMBPRINT',
        'MICROSOFT_GRAPH_USER',
        'EMAIL_PROVIDER',
        'MAIL_FROM',
        'MAIL_FROM_NAME'
    )
    $clean = $lines | Where-Object {
        $line = $_
        -not ($remove | Where-Object { $line -match ('^\s*' + [regex]::Escape($_) + '\s*=') })
    }
    $clean += 'EMAIL_PROVIDER=microsoft_graph'
    $clean += 'MICROSOFT_GRAPH_AUTH_MODE=app_only_cert'
    $clean += "MICROSOFT_GRAPH_TENANT_ID=$TenantId"
    $clean += "MICROSOFT_GRAPH_CLIENT_ID=$ClientId"
    $clean += 'MICROSOFT_GRAPH_USER=naoresponda@dev.shopvivaliz.com.br'
    $clean += "MICROSOFT_GRAPH_CERT_THUMBPRINT=$($existing.Thumbprint)"
    $clean += 'MAIL_FROM=Contabilidade Melo <naoresponda@dev.shopvivaliz.com.br>'
    $clean += 'MAIL_FROM_NAME=Contabilidade Melo'
    Set-Content -Path $envPath -Value $clean -Encoding UTF8
}

Write-Output 'FREDWIN_M365_CERT_READY'
Write-Output ('certificate_thumbprint=' + $existing.Thumbprint)
Write-Output ('certificate_not_after=' + $existing.NotAfter.ToString('o'))
Write-Output ('public_certificate_path=' + $cerPath)
Write-Output ('tenant_id=' + $TenantId)
Write-Output ('client_id=' + $ClientId)
Write-Output ('private_key_exportable=false')
