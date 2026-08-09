param(
  [string]$TenantId='cc55b801-12c2-4ea2-a930-d639aa8988a4',
  [string]$ClientId='a5e400f0-969e-4fbe-be61-d390cb112517',
  [string]$Sender='naoresponda@dev.shopvivaliz.com.br'
)
$ErrorActionPreference='Stop'
$cert=Get-ChildItem Cert:\CurrentUser\My -ErrorAction SilentlyContinue | Where-Object { $_.Subject -eq 'CN=ShopVivalizExchangeAuth' -and $_.HasPrivateKey } | Sort-Object NotAfter -Descending | Select-Object -First 1
$graph=$null
if($cert){
  try { $graph = & "$PSScriptRoot\Test-ShopVivalizGraphAppOnly.ps1" -TenantId $TenantId -ClientId $ClientId -Thumbprint $cert.Thumbprint | ConvertFrom-Json } catch { $graph=[pscustomobject]@{status='error';error=$_.Exception.Message;has_mail_send=$false} }
}
$legacy=@()
$roots=@('C:\site-shopvivaliz','C:\mei-mg-email')
foreach($r in $roots){
  if(Test-Path $r){
    $legacy += Get-ChildItem $r -File -Recurse -ErrorAction SilentlyContinue | Where-Object { $_.Length -lt 2097152 } | Select-String -Pattern 'GMAIL_APP_PASSWORD|GMAIL_ADDRESS|MICROSOFT_SMTP_|smtp.gmail.com' -SimpleMatch -ErrorAction SilentlyContinue | Select-Object -First 20 Path,LineNumber,Line
  }
}
[pscustomobject]@{
  entra_app_ready=([bool]$ClientId);
  tenant_id=$TenantId;
  client_id=$ClientId;
  sender=$Sender;
  certificate_ready=([bool]$cert);
  certificate_thumbprint=if($cert){$cert.Thumbprint}else{$null};
  certificate_has_private_key=if($cert){$cert.HasPrivateKey}else{$false};
  certificate_valid_until=if($cert){$cert.NotAfter.ToString('o')}else{$null};
  graph_app_only_ready=($graph -and $graph.status -eq 'success');
  mail_send_ready=($graph -and $graph.has_mail_send -eq $true);
  graph_roles=if($graph){@($graph.roles)}else{@()};
  exchange_app_only_ready=$false;
  exchange_validation='NOT_VALIDATED';
  legacy_smtp_references=@($legacy);
  smtp_disabled=(@($legacy).Count -eq 0);
  all_ready=($cert -and $graph -and $graph.has_mail_send -eq $true -and @($legacy).Count -eq 0)
} | ConvertTo-Json -Depth 8
