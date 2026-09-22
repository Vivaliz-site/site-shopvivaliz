param(
  [Parameter(Mandatory=$true)][string]$ServerKeyFile
)

$ErrorActionPreference='Stop'
$server='100.66.174.74'
$backendId='385996639'
$siteId='386694717'
$outDir='C:\Windows\Temp\shopvivaliz-rustdesk-validation'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$serverKey=(Get-Content -LiteralPath $ServerKeyFile -Raw -Encoding UTF8).Trim()
Remove-Item -LiteralPath $ServerKeyFile -Force -ErrorAction SilentlyContinue

$exeCandidates=@(
  'C:\Program Files\RustDesk\rustdesk.exe',
  'C:\Program Files (x86)\RustDesk\rustdesk.exe',
  (Join-Path $env:LOCALAPPDATA 'RustDesk\rustdesk.exe')
)
$exe=$exeCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if(-not $exe){ throw 'RustDesk executable not found on Fred-Win' }

$configDir=Join-Path $env:APPDATA 'RustDesk\config'
$config=Join-Path $configDir 'RustDesk2.toml'
$backup=Join-Path $outDir 'RustDesk2.toml.backup'
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
$hadConfig=Test-Path $config
if($hadConfig){Copy-Item -LiteralPath $config -Destination $backup -Force}

function Stop-InteractiveRustDesk {
  $sid=(Get-Process -Id $PID).SessionId
  Get-Process rustdesk -ErrorAction SilentlyContinue |
    Where-Object {$_.SessionId -eq $sid} |
    Stop-Process -Force -ErrorAction SilentlyContinue
  Start-Sleep -Seconds 2
}

function Capture-Desktop([string]$Path) {
  Add-Type -AssemblyName System.Windows.Forms
  Add-Type -AssemblyName System.Drawing
  $bounds=[System.Windows.Forms.SystemInformation]::VirtualScreen
  $bitmap=New-Object System.Drawing.Bitmap $bounds.Width,$bounds.Height
  $graphics=[System.Drawing.Graphics]::FromImage($bitmap)
  $graphics.CopyFromScreen($bounds.Left,$bounds.Top,0,0,$bitmap.Size)
  $bitmap.Save($Path,[System.Drawing.Imaging.ImageFormat]::Png)
  $graphics.Dispose()
  $bitmap.Dispose()
}

try {
  Stop-InteractiveRustDesk
  $configText = "rendezvous_server = '" + $server + ":21116'`r`nnat_type = 1`r`nserial = 0`r`n`r`n[options]`r`ncustom-rendezvous-server = '" + $server + "'`r`nkey = '" + $serverKey + "'`r`nrelay-server = ''`r`napi-server = ''`r`n"
  Set-Content -LiteralPath $config -Value $configText -Encoding UTF8

  $tcp21116=Test-NetConnection $server -Port 21116 -WarningAction SilentlyContinue
  $tcp21117=Test-NetConnection $server -Port 21117 -WarningAction SilentlyContinue
  $result=@(
    "FRED_HOST=$env:COMPUTERNAME",
    "RUSTDESK_EXE=$exe",
    "TCP_21116=$($tcp21116.TcpTestSucceeded)",
    "TCP_21117=$($tcp21117.TcpTestSucceeded)"
  )

  Start-Process -FilePath $exe -ArgumentList @('--connect',$backendId)
  Start-Sleep -Seconds 15
  Capture-Desktop (Join-Path $outDir 'backend.png')
  $result+='BACKEND_ATTEMPT=CAPTURED'
  Stop-InteractiveRustDesk

  Start-Process -FilePath $exe -ArgumentList @('--connect',$siteId)
  Start-Sleep -Seconds 15
  Capture-Desktop (Join-Path $outDir 'site.png')
  $result+='SITE_ATTEMPT=CAPTURED'
  Stop-InteractiveRustDesk

  $result | Set-Content -LiteralPath (Join-Path $outDir 'result.txt') -Encoding UTF8
}
finally {
  if($hadConfig -and (Test-Path $backup)){
    Copy-Item -LiteralPath $backup -Destination $config -Force
  } elseif(Test-Path $config) {
    Remove-Item -LiteralPath $config -Force
  }
  Remove-Item -LiteralPath $ServerKeyFile -Force -ErrorAction SilentlyContinue
}
