<#
Closes only interactive Claude Code/Codex CLI processes older than 24 hours.
Never closes Claude Desktop, ChatGPT desktop, MCP services or background workers.
Dry-run by default. Logs contain no command lines or secrets.
#>
param(
  [switch]$Apply,
  [int]$MaxAgeHours=24,
  [string]$FixturePath=''
)
$ErrorActionPreference='Stop'
if($MaxAgeHours -lt 1){throw 'MaxAgeHours must be positive'}
if($Apply -and $FixturePath){throw 'Fixtures may be used only for dry-run tests'}

function Get-AgentKind($process, $index) {
  $name=([string]$process.Name).ToLowerInvariant()
  $path=([string]$process.ExecutablePath).ToLowerInvariant().Replace('/','\')
  $cmd=([string]$process.CommandLine).ToLowerInvariant().Replace('/','\')
  $parent=$index[[int]$process.ParentProcessId]
  $parentName=if($parent){([string]$parent.Name).ToLowerInvariant()}else{''}
  if($path -match '\\windowsapps\\|\\claude_desktop\\'){return $null}
  if($cmd -match 'desktop-commander|extension-host|chrome-native-host|cowork-svc|--remote-control'){return $null}
  if($parentName -match '^(services|svchost|taskhostw)\.exe$'){return $null}

  if($name -eq 'claude.exe'){
    if($path -match '\\\.local\\bin\\claude\.exe$|\\winget\\links\\claude\.exe$|\\@anthropic-ai\\claude-code\\' -or
       $cmd -match '@anthropic-ai\\claude-code|\\\.local\\bin\\claude\.exe'){
      return 'claude'
    }
    return $null
  }
  if($name -eq 'codex.exe'){
    if($parentName -eq 'chatgpt.exe'){return $null}
    if($path -match '\\@openai\\codex\\|\\openai\\codex\\bin\\|\\\.codex\\bin\\' -or
       $cmd -match '@openai\\codex'){
      return 'codex'
    }
    return $null
  }
  if($name -eq 'node.exe'){
    if($cmd -match '@anthropic-ai\\claude-code'){return 'claude'}
    if($cmd -match '@openai\\codex'){return 'codex'}
  }
  return $null
}

function Get-ProcessRows {
  if($FixturePath){
    $value=Get-Content -Raw -LiteralPath $FixturePath | ConvertFrom-Json
    return @($value)
  }
  return @(Get-CimInstance Win32_Process -ErrorAction Stop)
}

$now=(Get-Date).ToUniversalTime()
$items=@(Get-ProcessRows)
$byId=@{}
foreach($p in $items){$byId[[int]$p.ProcessId]=$p}
$eligible=@{}
foreach($p in $items){
  $kind=Get-AgentKind $p $byId
  if(-not $kind){continue}
  $start=([datetime]$p.CreationDate).ToUniversalTime()
  if($start -eq [datetime]::MinValue){continue}
  $age=($now-$start).TotalHours
  if($age -gt $MaxAgeHours){
    $eligible[[int]$p.ProcessId]=[pscustomobject]@{
      Process=$p;Kind=$kind;AgeHours=$age;Start=$start
    }
  }
}

function Has-NewerAgentDescendant([int]$targetPid){
  $queue=New-Object 'System.Collections.Generic.Queue[int]'
  $queue.Enqueue($targetPid)
  $seen=@{}
  while($queue.Count -gt 0){
    $parentId=$queue.Dequeue()
    if($seen.ContainsKey($parentId)){continue}
    $seen[$parentId]=$true
    foreach($p in $items){
      if([int]$p.ParentProcessId -ne $parentId){continue}
      $childId=[int]$p.ProcessId
      $childKind=Get-AgentKind $p $byId
      if($childKind -and ($now-([datetime]$p.CreationDate).ToUniversalTime()).TotalHours -le $MaxAgeHours){
        return $true
      }
      if(-not $seen.ContainsKey($childId)){$queue.Enqueue($childId)}
    }
  }
  return $false
}

$logDir=Join-Path $env:LOCALAPPDATA 'ShopVivaliz'
$selectedCount=0
foreach($id in @($eligible.Keys | Sort-Object)){
  $x=$eligible[$id]
  if(Has-NewerAgentDescendant ([int]$id)){continue}
  $selectedCount++
  $outcome='WOULD_CLOSE'
  if($Apply){
    try{
      # Re-read PID and creation time to prevent a recycled PID from being terminated.
      $current=Get-CimInstance Win32_Process -Filter "ProcessId=$id" -ErrorAction Stop
      if(-not $current -or ([datetime]$current.CreationDate).ToUniversalTime() -ne $x.Start){
        $outcome='SKIPPED_CHANGED'
      } elseif((Get-AgentKind $current $byId) -ne $x.Kind){
        $outcome='SKIPPED_RECLASSIFIED'
      } else {
        $owner=Invoke-CimMethod -InputObject $current -MethodName GetOwner -ErrorAction Stop
        if($owner.User -ine $env:USERNAME){
          $outcome='SKIPPED_OTHER_USER'
        } else {
          Stop-Process -Id $id -ErrorAction Stop -Confirm:$false
          $outcome='CLOSED'
        }
      }
    }catch{
      $outcome='FAILED_'+$_.Exception.GetType().Name
    }
  }
  $audit=[ordered]@{
    at=(Get-Date).ToUniversalTime().ToString('o')
    host=$env:COMPUTERNAME
    pid=[int]$id
    kind=$x.Kind
    age_hours=[math]::Round($x.AgeHours,2)
    result=$outcome
  }
  $line=$audit | ConvertTo-Json -Compress
  Write-Output $line
  if($Apply){
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    Add-Content -LiteralPath (Join-Path $logDir 'agent-session-ttl.jsonl') -Value $line -Encoding UTF8
  }
}
Write-Output ('ELIGIBLE_SESSIONS='+$selectedCount+' MODE=+$(if($Apply){'apply'}else{'dry-run'}))
