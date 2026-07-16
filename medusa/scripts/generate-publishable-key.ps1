$ErrorActionPreference = "Stop"

param(
    [string]$BackendUrl = "http://localhost:9000",
    [string]$AdminToken = "",
    [string]$Title = "ShopVivaliz Storefront Key",
    [string]$SalesChannelId = "",
    [string]$StorefrontEnvPath = ""
)

if ([string]::IsNullOrWhiteSpace($StorefrontEnvPath)) {
    $StorefrontEnvPath = Join-Path $PSScriptRoot "..\apps\storefront\.env.local"
}

if ([string]::IsNullOrWhiteSpace($AdminToken)) {
    if ($env:MEDUSA_ADMIN_TOKEN) {
        $AdminToken = $env:MEDUSA_ADMIN_TOKEN
    } else {
        Write-Host "Defina -AdminToken ou MEDUSA_ADMIN_TOKEN antes de gerar a publishable key." -ForegroundColor Yellow
        exit 1
    }
}

$headers = @{
    Authorization = "Bearer $AdminToken"
    "Content-Type" = "application/json"
}

$createBody = @{
    title = $Title
    type = "publishable"
} | ConvertTo-Json

$createResponse = Invoke-RestMethod -Method Post -Uri "$BackendUrl/admin/api-keys" -Headers $headers -Body $createBody
$apiKey = $createResponse.api_key

if (-not $apiKey -or -not $apiKey.token) {
    Write-Host "Nao foi possivel criar a publishable key." -ForegroundColor Red
    exit 1
}

if (-not [string]::IsNullOrWhiteSpace($SalesChannelId)) {
    $salesChannelBody = @{
        add = @($SalesChannelId)
    } | ConvertTo-Json

    Invoke-RestMethod -Method Post -Uri "$BackendUrl/admin/api-keys/$($apiKey.id)/sales-channels" -Headers $headers -Body $salesChannelBody | Out-Null
}

$envLines = @()
if (Test-Path $StorefrontEnvPath) {
    $envLines = Get-Content $StorefrontEnvPath
}

$updated = $false
$result = foreach ($line in $envLines) {
    if ($line -match '^NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=') {
        $updated = $true
        "NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=$($apiKey.token)"
    } else {
        $line
    }
}

if (-not $updated) {
    $result += "NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=$($apiKey.token)"
}

Set-Content -Path $StorefrontEnvPath -Value $result

Write-Host "Publishable key criada: $($apiKey.id)" -ForegroundColor Green
Write-Host "Storefront atualizada em $StorefrontEnvPath" -ForegroundColor Green
