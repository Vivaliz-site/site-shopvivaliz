param(
  [Parameter(Mandatory=$true)][string]$NewCertificateBase64,
  [string]$DisplayName='ShopVivaliz Oracle VM',
  [string]$TenantId='cc55b801-12c2-4ea2-a930-d639aa8988a4',
  [string]$ClientId='a5e400f0-969e-4fbe-be61-d390cb112517',
  [string]$ApplicationObjectId='709b607b-87f9-4572-8661-2ea8cec6e294',
  [string]$ExistingThumbprint='7EFCBC6676B61F8C076F08127F5F7E365A95F2E9'
)
$ErrorActionPreference='Stop'

function ConvertTo-B64Url([byte[]]$Bytes) {
  [Convert]::ToBase64String($Bytes).TrimEnd('=').Replace('+','-').Replace('/','_')
}
function New-SignedJwt([System.Security.Cryptography.X509Certificates.X509Certificate2]$Cert, [hashtable]$Claims) {
  $header=@{alg='RS256';typ='JWT';x5t=(ConvertTo-B64Url $Cert.GetCertHash())} | ConvertTo-Json -Compress
  $payload=$Claims | ConvertTo-Json -Compress
  $unsigned=(ConvertTo-B64Url ([Text.Encoding]::UTF8.GetBytes($header)))+'.'+(ConvertTo-B64Url ([Text.Encoding]::UTF8.GetBytes($payload)))
  $rsa=$Cert.GetRSAPrivateKey()
  if(-not $rsa){ throw 'Private key is unavailable for the existing certificate' }
  try {
    $sig=$rsa.SignData([Text.Encoding]::UTF8.GetBytes($unsigned),[Security.Cryptography.HashAlgorithmName]::SHA256,[Security.Cryptography.RSASignaturePadding]::Pkcs1)
  } finally { $rsa.Dispose() }
  $unsigned+'.'+(ConvertTo-B64Url $sig)
}

$store=New-Object System.Security.Cryptography.X509Certificates.X509Store('My','CurrentUser')
$store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadOnly)
try {
  $cert=$store.Certificates | Where-Object { $_.Thumbprint -eq $ExistingThumbprint } | Select-Object -First 1
  if(-not $cert){ throw "Existing certificate $ExistingThumbprint not found in CurrentUser/My" }
  if(-not $cert.HasPrivateKey){ throw 'Existing certificate does not have its private key' }

  $now=[DateTimeOffset]::UtcNow
  $tokenAssertion=New-SignedJwt $cert @{
    aud="https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token"
    iss=$ClientId
    sub=$ClientId
    jti=[guid]::NewGuid().ToString()
    nbf=$now.AddMinutes(-1).ToUnixTimeSeconds()
    exp=$now.AddMinutes(9).ToUnixTimeSeconds()
  }
  $tokenBody=@{
    client_id=$ClientId
    scope='https://graph.microsoft.com/.default'
    grant_type='client_credentials'
    client_assertion_type='urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
    client_assertion=$tokenAssertion
  }
  $token=Invoke-RestMethod -Method Post -Uri "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token" -Body $tokenBody -ContentType 'application/x-www-form-urlencoded'
  if(-not $token.access_token){ throw 'Microsoft Graph token was not returned' }

  $parts=$token.access_token.Split('.')
  $pad='=' * ((4-$parts[1].Length%4)%4)
  $claims=([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($parts[1].Replace('-','+').Replace('_','/')+$pad))) | ConvertFrom-Json
  $roles=@($claims.roles)
  if(($roles -notcontains 'Directory.ReadWrite.All') -and ($roles -notcontains 'Application.ReadWrite.All') -and ($roles -notcontains 'Application.ReadWrite.OwnedBy')) {
    throw ('App token lacks an application-write permission. Roles: ' + ($roles -join ','))
  }

  $proof=New-SignedJwt $cert @{
    aud='00000002-0000-0000-c000-000000000000'
    iss=$ClientId
    nbf=$now.AddMinutes(-1).ToUnixTimeSeconds()
    exp=$now.AddMinutes(9).ToUnixTimeSeconds()
  }

  [void][Convert]::FromBase64String($NewCertificateBase64)
  $request=@{
    keyCredential=@{
      type='AsymmetricX509Cert'
      usage='Verify'
      key=$NewCertificateBase64
      displayName=$DisplayName
    }
    passwordCredential=$null
    proof=$proof
  } | ConvertTo-Json -Depth 6

  $result=Invoke-RestMethod -Method Post -Uri "https://graph.microsoft.com/v1.0/applications/$ApplicationObjectId/addKey" -Headers @{Authorization=('Bearer '+$token.access_token)} -ContentType 'application/json' -Body $request
  [pscustomobject]@{
    status='success'
    application_id=$ClientId
    application_object_id=$ApplicationObjectId
    display_name=$DisplayName
    new_key_id=$result.keyId
    existing_thumbprint=$ExistingThumbprint
    graph_roles=$roles
  } | ConvertTo-Json -Depth 5
} finally {
  $store.Close()
}
