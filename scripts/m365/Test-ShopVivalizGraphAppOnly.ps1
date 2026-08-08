param(
  [string]$TenantId='cc55b801-12c2-4ea2-a930-d639aa8988a4',
  [string]$ClientId='a5e400f0-969e-4fbe-be61-d390cb112517',
  [string]$Thumbprint=''
)
$ErrorActionPreference='Stop'

$store = [System.Security.Cryptography.X509Certificates.X509Store]::new(
  [System.Security.Cryptography.X509Certificates.StoreName]::My,
  [System.Security.Cryptography.X509Certificates.StoreLocation]::CurrentUser
)
$store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadOnly)
try {
  $certs = @($store.Certificates | Where-Object { $_.HasPrivateKey })
  if($Thumbprint){
    $wanted = ($Thumbprint -replace '\s','').ToUpperInvariant()
    $cert = $certs | Where-Object { (($_.Thumbprint -replace '\s','').ToUpperInvariant()) -eq $wanted } | Select-Object -First 1
  } else {
    $cert = $certs | Where-Object { $_.Subject -eq 'CN=ShopVivalizExchangeAuth' } | Sort-Object NotAfter -Descending | Select-Object -First 1
  }
} finally {
  $store.Close()
}
if(-not $cert -or -not $cert.HasPrivateKey){ throw 'Certificate with private key not found in CurrentUser\My' }
function B64Url([byte[]]$bytes){ [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+','-').Replace('/','_') }
$now=[DateTimeOffset]::UtcNow
$header=@{alg='RS256';typ='JWT';x5t=(B64Url $cert.GetCertHash())} | ConvertTo-Json -Compress
$payload=@{aud="https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token";iss=$ClientId;sub=$ClientId;jti=[guid]::NewGuid().ToString();nbf=$now.AddMinutes(-1).ToUnixTimeSeconds();exp=$now.AddMinutes(9).ToUnixTimeSeconds()} | ConvertTo-Json -Compress
$unsigned=(B64Url ([Text.Encoding]::UTF8.GetBytes($header)))+'.'+(B64Url ([Text.Encoding]::UTF8.GetBytes($payload)))
$rsa=$cert.GetRSAPrivateKey(); $sig=$rsa.SignData([Text.Encoding]::UTF8.GetBytes($unsigned),[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
$assertion=$unsigned+'.'+(B64Url $sig)
$body=@{client_id=$ClientId;scope='https://graph.microsoft.com/.default';grant_type='client_credentials';client_assertion_type='urn:ietf:params:oauth:client-assertion-type:jwt-bearer';client_assertion=$assertion}
$token=Invoke-RestMethod -Method Post -Uri "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token" -Body $body -ContentType 'application/x-www-form-urlencoded'
$parts=$token.access_token.Split('.'); $claimsJson=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(($parts[1].Replace('-','+').Replace('_','/') + ('=' * ((4-$parts[1].Length%4)%4)))))
$claims=$claimsJson | ConvertFrom-Json
[pscustomobject]@{status='success';token_type=$token.token_type;expires_in=$token.expires_in;aud=$claims.aud;roles=@($claims.roles);thumbprint=$cert.Thumbprint;has_mail_send=(@($claims.roles) -contains 'Mail.Send')} | ConvertTo-Json -Depth 5
