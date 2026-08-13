$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Sender = 'naoresponda@dev.shopvivaliz.com.br'
$Cache = Join-Path $env:LOCALAPPDATA 'mei-mg-email\microsoft-graph-token.json'

function ConvertFrom-Base64UrlJson {
    param([Parameter(Mandatory=$true)][string]$Value)
    $padded = $Value.Replace('-', '+').Replace('_', '/')
    switch ($padded.Length % 4) {
        2 { $padded += '==' }
        3 { $padded += '=' }
    }
    $bytes = [Convert]::FromBase64String($padded)
    ([Text.Encoding]::UTF8.GetString($bytes)) | ConvertFrom-Json
}

function Get-JwtClaims {
    param([Parameter(Mandatory=$true)][string]$Token)
    $parts = $Token.Split('.')
    if ($parts.Count -ne 3) { throw 'TOKEN_NOT_JWT' }
    ConvertFrom-Base64UrlJson -Value $parts[1]
}

function Get-ClaimText {
    param($Claims, [string[]]$Names)
    foreach ($name in $Names) {
        $property = $Claims.PSObject.Properties[$name]
        if ($null -ne $property -and -not [string]::IsNullOrWhiteSpace([string]$property.Value)) {
            return [string]$property.Value
        }
    }
    return ''
}

if (-not (Test-Path -LiteralPath $Cache)) {
    throw 'DELEGATED_CACHE_MISSING'
}

$cached = Get-Content -LiteralPath $Cache -Raw | ConvertFrom-Json
$refreshToken = [string]$cached.refresh_token
$cachedAccessToken = [string]$cached.access_token
if ([string]::IsNullOrWhiteSpace($refreshToken)) { throw 'DELEGATED_REFRESH_TOKEN_MISSING' }
if ([string]::IsNullOrWhiteSpace($cachedAccessToken)) { throw 'DELEGATED_ACCESS_TOKEN_MISSING' }

$cachedClaims = Get-JwtClaims -Token $cachedAccessToken
$tenantId = Get-ClaimText -Claims $cachedClaims -Names @('tid')
$clientId = Get-ClaimText -Claims $cachedClaims -Names @('azp','appid')
$userPrincipalName = Get-ClaimText -Claims $cachedClaims -Names @('preferred_username','upn','unique_name')
if ([string]::IsNullOrWhiteSpace($tenantId)) { throw 'DELEGATED_TENANT_MISSING' }
if ([string]::IsNullOrWhiteSpace($clientId)) { throw 'DELEGATED_CLIENT_MISSING' }
if ([string]::IsNullOrWhiteSpace($userPrincipalName)) { throw 'DELEGATED_UPN_MISSING' }

Write-Output ('DELEGATED_CACHE_FOUND=true')
Write-Output ('DELEGATED_TENANT_MATCH=' + ($tenantId -eq 'cc55b801-12c2-4ea2-a930-d639aa8988a4'))
Write-Output ('DELEGATED_CLIENT_ID=' + $clientId)
Write-Output ('DELEGATED_USER_DOMAIN=' + ($userPrincipalName.Split('@')[-1]))

$tokenEndpoint = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token"
$body = @{
    client_id = $clientId
    grant_type = 'refresh_token'
    refresh_token = $refreshToken
    scope = 'https://outlook.office365.com/.default offline_access openid profile'
}

try {
    $tokenResponse = Invoke-RestMethod -Uri $tokenEndpoint -Method Post -ContentType 'application/x-www-form-urlencoded' -Body $body -TimeoutSec 60
}
catch {
    $detail = $_.ErrorDetails.Message
    if ([string]::IsNullOrWhiteSpace($detail)) { $detail = $_.Exception.Message }
    $detail = ([string]$detail).Replace("`r", ' ').Replace("`n", ' ')
    throw ('EXCHANGE_REFRESH_TOKEN_FAILED ' + $detail.Substring(0, [Math]::Min(700, $detail.Length)))
}
finally {
    $body.refresh_token = '[CLEARED]'
    $refreshToken = $null
}

$exchangeToken = [string]$tokenResponse.access_token
if ([string]::IsNullOrWhiteSpace($exchangeToken)) { throw 'EXCHANGE_ACCESS_TOKEN_MISSING' }
$exchangeClaims = Get-JwtClaims -Token $exchangeToken
$audience = [string]$exchangeClaims.aud
$scopes = [string]$exchangeClaims.scp
$roles = @($exchangeClaims.roles) -join ','
Write-Output ('EXCHANGE_TOKEN_AUD=' + $audience)
Write-Output ('EXCHANGE_TOKEN_SCOPES=' + $scopes)
Write-Output ('EXCHANGE_TOKEN_ROLES=' + $roles)

$allowedAudiences = @(
    'https://outlook.office365.com',
    'https://outlook.office365.com/',
    '00000002-0000-0ff1-ce00-000000000000'
)
if ($allowedAudiences -notcontains $audience) {
    throw ('EXCHANGE_TOKEN_WRONG_AUDIENCE ' + $audience)
}

if (-not (Get-Module -ListAvailable ExchangeOnlineManagement)) {
    Set-PSRepository PSGallery -InstallationPolicy Trusted
    Install-Module ExchangeOnlineManagement -Scope CurrentUser -Force -AllowClobber
}
Import-Module ExchangeOnlineManagement -ErrorAction Stop

$connected = $false
try {
    Connect-ExchangeOnline -AccessToken $exchangeToken -UserPrincipalName $userPrincipalName -ShowBanner:$false -CommandName @('Get-BlockedSenderAddress','Remove-BlockedSenderAddress')
    $connected = $true
    Write-Output 'EXCHANGE_DELEGATED_CONNECTED=true'

    $blocked = @(Get-BlockedSenderAddress -SenderAddress $Sender -ErrorAction Stop)
    Write-Output ('BLOCKED_BEFORE=' + $blocked.Count)
    if ($blocked.Count -gt 0) {
        Remove-BlockedSenderAddress -SenderAddress $Sender -Reason 'Authorized sender restored after AS(42004) remediation' -Confirm:$false -ErrorAction Stop
        Write-Output 'UNBLOCK_SUBMITTED=true'
    }

    $deadline = [DateTimeOffset]::UtcNow.AddMinutes(5)
    do {
        Start-Sleep -Seconds 10
        $after = @(Get-BlockedSenderAddress -SenderAddress $Sender -ErrorAction Stop)
        Write-Output ('BLOCKED_POLL=' + $after.Count)
        if ($after.Count -eq 0) { break }
    } while ([DateTimeOffset]::UtcNow -lt $deadline)

    if ($after.Count -ne 0) { throw 'SENDER_STILL_BLOCKED' }
    Write-Output 'EXCHANGE_DELEGATED_UNBLOCK_CONFIRMED=true'
}
finally {
    $exchangeToken = $null
    if ($connected) {
        Disconnect-ExchangeOnline -Confirm:$false -ErrorAction SilentlyContinue
    }
}
