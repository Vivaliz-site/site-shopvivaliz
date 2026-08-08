param(
  [string]$Subject = 'CN=ShopVivalizExchangeAuth',
  [int]$Years = 2,
  [string]$CerPath = 'C:\Certs\ShopVivalizExchangeAuth.cer'
)
$ErrorActionPreference='Stop'

$store = [System.Security.Cryptography.X509Certificates.X509Store]::new(
  [System.Security.Cryptography.X509Certificates.StoreName]::My,
  [System.Security.Cryptography.X509Certificates.StoreLocation]::CurrentUser
)
$store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
try {
  $existing = $store.Certificates |
    Where-Object { $_.Subject -eq $Subject -and $_.HasPrivateKey -and $_.NotAfter -gt (Get-Date).AddDays(30) } |
    Sort-Object NotAfter -Descending |
    Select-Object -First 1

  if(-not $existing){
    $keyName = 'ShopVivalizExchangeAuth-' + [guid]::NewGuid().ToString('N')
    $creation = [System.Security.Cryptography.CngKeyCreationParameters]::new()
    $creation.Provider = [System.Security.Cryptography.CngProvider]::MicrosoftSoftwareKeyStorageProvider
    $creation.KeyUsage = [System.Security.Cryptography.CngKeyUsages]::Signing
    $creation.ExportPolicy = [System.Security.Cryptography.CngExportPolicies]::None
    $creation.Parameters.Add([System.Security.Cryptography.CngProperty]::new('Length',[BitConverter]::GetBytes(2048),[System.Security.Cryptography.CngPropertyOptions]::None))
    $key = [System.Security.Cryptography.CngKey]::Create([System.Security.Cryptography.CngAlgorithm]::Rsa,$keyName,$creation)
    try {
      $rsa = [System.Security.Cryptography.RSACng]::new($key)
      $dn = [System.Security.Cryptography.X509Certificates.X500DistinguishedName]::new($Subject)
      $req = [System.Security.Cryptography.X509Certificates.CertificateRequest]::new(
        $dn,
        $rsa,
        [System.Security.Cryptography.HashAlgorithmName]::SHA256,
        [System.Security.Cryptography.RSASignaturePadding]::Pkcs1
      )
      $req.CertificateExtensions.Add([System.Security.Cryptography.X509Certificates.X509BasicConstraintsExtension]::new($false,$false,0,$false))
      $req.CertificateExtensions.Add([System.Security.Cryptography.X509Certificates.X509KeyUsageExtension]::new([System.Security.Cryptography.X509Certificates.X509KeyUsageFlags]::DigitalSignature,$false))
      $cert = $req.CreateSelfSigned((Get-Date).AddMinutes(-5),(Get-Date).AddYears($Years))
      $store.Add($cert)
      $existing = $store.Certificates | Where-Object { $_.Thumbprint -eq $cert.Thumbprint } | Select-Object -First 1
    } finally {
      if($rsa){ $rsa.Dispose() }
      if($key){ $key.Dispose() }
    }
  }

  New-Item -ItemType Directory -Force -Path (Split-Path $CerPath) | Out-Null
  [System.IO.File]::WriteAllBytes($CerPath,$existing.Export([System.Security.Cryptography.X509Certificates.X509ContentType]::Cert))

  [pscustomobject]@{
    status='success'
    subject=$existing.Subject
    thumbprint=$existing.Thumbprint
    not_after=$existing.NotAfter.ToString('o')
    has_private_key=$existing.HasPrivateKey
    store='CurrentUser/My'
    cer_path=$CerPath
  } | ConvertTo-Json -Depth 4
}
finally {
  $store.Close()
}
