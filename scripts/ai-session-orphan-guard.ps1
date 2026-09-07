param([switch]$DryRun,[string]$FixturePath='')
$ErrorActionPreference='SilentlyContinue'

function Get-AIKind($p){
  $name=[string]$p.Name
  $cmd=[string]$p.CommandLine
  if($cmd -match '(?i)desktop-commander|extension-host|chrome-native-host|chatgpt classic|cowork-svc'){return $null}

  if($name -ieq 'claude.exe'){
    if($cmd -match '(?i)@anthropic-ai[\\/]claude-code|claude-code[\\/]bin[\\/]claude' -or
       $cmd -like '*\.local\bin\claude.exe*' -or
       $cmd -like '*\Microsoft\WinGet\Links\claude.exe*'){
      return 'claude'
    }
  }
  if($name -ieq 'codex.exe'){
    if($cmd -match '(?i)@openai[\\/]codex' -or
       $cmd -like '*\Programs\OpenAI\Codex\bin\codex.exe*'){
      return 'codex'
    }
  }
  if($name -ieq 'node.exe' -and $cmd -match '(?i)@anthropic-ai[\\/]claude-code|claude-code[\\/]bin[\\/]claude'){return 'claude'}
  if($name -ieq 'node.exe' -and $cmd -match '(?i)@openai[\\/]codex'){return 'codex'}
  return $null
}

function Get-Rows {
  if($FixturePath){
    foreach($line in Get-Content $FixturePath){
      if(-not $line.Trim()){continue}
      $a=$line.Split('|',4)
      [pscustomobject]@{ProcessId=[int]$a[0];ParentProcessId=[int]$a[1];Name=$a[2];CommandLine=$a[3]}
    }
  } else {
    Get-CimInstance Win32_Process
  }
}

$rows=@(Get-Rows)
$pidSet=@{}
foreach($p in $rows){$pidSet[[int]$p.ProcessId]=$true}
$ai=@{}
foreach($p in $rows){
  $k=Get-AIKind $p
  if($k){$ai[[int]$p.ProcessId]=[pscustomobject]@{P=$p;Kind=$k}}
}
$targets=@{}
foreach($id in @($ai.Keys)){
  $pp=[int]$ai[$id].P.ParentProcessId
  if($pp -gt 0 -and -not $pidSet.ContainsKey($pp)){$targets[$id]=$true}
}
$changed=$true
while($changed){
  $changed=$false
  foreach($id in @($ai.Keys)){
    if($targets.ContainsKey($id)){continue}
    $pp=[int]$ai[$id].P.ParentProcessId
    if($targets.ContainsKey($pp)){$targets[$id]=$true;$changed=$true}
  }
}
foreach($id in @($targets.Keys | Sort-Object)){
  $x=$ai[$id]
  $p=$x.P
  $line="host=$env:COMPUTERNAME pid=$id kind=$($x.Kind) ppid=$($p.ParentProcessId) cmd=$($p.CommandLine)"
  if($DryRun){
    Write-Output "WOULD_KILL_ORPHAN $line"
  } else {
    $line | Out-File (Join-Path $env:LOCALAPPDATA 'ShopVivaliz\ai-session-orphan-guard.log') -Append -Encoding utf8
    Stop-Process -Id $id -Force -ErrorAction SilentlyContinue
    Write-Output "KILL_ORPHAN $line"
  }
}
if($targets.Count -eq 0){Write-Output 'NO_AI_ORPHANS'}
exit 0
