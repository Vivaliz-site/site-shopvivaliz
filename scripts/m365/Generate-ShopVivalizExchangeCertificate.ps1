param(
  [string]$Subject = 'CN=ShopVivalizExchangeAuth',
  [int]$Years = 2,
  [string]$CerPath = 'C:\Certs\ShopVivalizExchangeAuth.cer'
)
$ErrorActionPreference='Stop'
$store='Cert:\CurrentUser\My'
$existing = Get-ChildItem $store | Where-Object { $_.Subject -eq $Subject -and $_.HasPrivateKey -and $_.NotAfter -gt (Get-Date).AddDays(30) } | Sort-Object NotAfter -Descending | Select-Object -First 1
if(-not $existing){
  $existing = New-SelfSignedCertificate -Subject $Subject -CertStoreLocation $store -KeyAlgorithm RSA -KeyLength 2048 -HashAlgorithm SHA256 -KeyExportPolicy NonExportable -KeySpec Signature -NotAfter (Get-Date).AddYears($Years)
}
New-Item -ItemType Directory -Force -Path (Split-Path $CerPath) | Out-Null
Export-Certificate -Cert $existing -FilePath $CerPath -Force | Out-Null
[pscustomobject]@{
  status='success';
  subject=$existing.Subject;
  thumbprint=$existing.Thumbprint;
  not_after=$existing.NotAfter.ToString('o');
  has_private_key=$existing.HasPrivateKey;
  store=$store;
  cer_path=$CerPath
} | ConvertTo-Json -Depth 4
