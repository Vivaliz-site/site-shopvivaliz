$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
$guard=Join-Path $root 'scripts\ai-session-orphan-guard.ps1'
$boot=Join-Path $root 'scripts\ai-session-boot-cleanup.ps1'
$installer=Join-Path $root 'scripts\install-fredwin-ai-session-guards.ps1'
foreach($p in @($guard,$boot,$installer)){if(-not (Test-Path $p)){throw "missing file: $p"}}

$tmp=Join-Path ([IO.Path]::GetTempPath()) ('ai-guard-'+[guid]::NewGuid().ToString('N')+'.txt')
@(
 '100|4|explorer.exe|C:\Windows\explorer.exe',
 '9001|9999|claude.exe|C:\Users\FRED\.local\bin\claude.exe --dangerously-skip-permissions',
 '9002|100|node.exe|C:\Users\FRED\AppData\Local\npm-cache\node_modules\@wonderwhy-er\desktop-commander\dist\index.js remote --persist-session',
 '9003|100|codex.exe|C:\Users\FRED\AppData\Local\Programs\OpenAI\Codex\bin\codex.exe',
 '9004|8888|codex.exe|C:\Users\FRED\AppData\Local\Programs\OpenAI\Codex\bin\codex.exe'
) | Set-Content -Path $tmp -Encoding utf8
try{
  $out=& $guard -DryRun -FixturePath $tmp
  $text=$out -join "`n"
  if($text -notmatch 'pid=9001'){throw 'standalone Claude orphan not detected'}
  if($text -notmatch 'pid=9004'){throw 'standalone Codex orphan not detected'}
  if($text -match 'pid=9002'){throw 'Desktop Commander must be excluded'}
  if($text -match 'pid=9003'){throw 'live Codex with a parent must not be targeted'}
}finally{Remove-Item -Force $tmp -ErrorAction SilentlyContinue}

$bootSource=Get-Content $boot -Raw
if($bootSource -notmatch 'ai-session-orphan-guard\.ps1'){throw 'boot cleanup must delegate to orphan guard'}
if($bootSource -match '\.Kill\(|Stop-Process'){throw 'boot cleanup must not kill every AI process directly'}
$installSource=Get-Content $installer -Raw
foreach($needle in @('ShopVivaliz AI Session Orphan Guard','ShopVivaliz AI Session Boot Cleanup','ORPHANS_ONLY')){if(-not $installSource.Contains($needle)){throw "installer contract missing: $needle"}}
Write-Output 'fredwin-ai-session-guards-test: OK'
