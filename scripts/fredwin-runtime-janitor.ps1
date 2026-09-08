param(
    [switch]$DryRun,
    [switch]$InstallTask,
    [string]$FixturePath = '',
    [string]$Now = '',
    [int]$StaleDcMinutes = 10,
    [int]$TempHeadlessMinutes = 10
)
$ErrorActionPreference = 'SilentlyContinue'
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'fredwin-runtime-janitor.log'
$NowTime = if ($Now) { [datetime]::Parse($Now) } else { Get-Date }
if (-not $DryRun) { New-Item -ItemType Directory -Force -Path $LogDir | Out-Null }

function Write-JanitorLog([string]$Message) {
    $line = ('{0} - {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
    if (-not $DryRun) { $line | Out-File -FilePath $LogFile -Append -Encoding utf8 }
    Write-Output $Message
}

function Get-Rows {
    if ($FixturePath) {
        foreach ($line in Get-Content -LiteralPath $FixturePath) {
            if (-not $line.Trim()) { continue }
            $a = $line.Split('|',5)
            [pscustomobject]@{ProcessId=[int]$a[0];ParentProcessId=[int]$a[1];Name=$a[2];CreationDate=[datetime]::Parse($a[3]);CommandLine=$a[4];ExecutablePath=''}
        }
        return
    }
    Get-CimInstance Win32_Process
}

function Get-AgeMinutes($p) {
    try { return ($NowTime - [datetime]$p.CreationDate).TotalMinutes } catch { return 999999 }
}

function Test-ManagedCommand([string]$cmd) {
    if ($cmd -match '(?i)fredwin-desktop-commander-runner\.ps1') { return $true }
    if ($cmd -match '(?i)fredwin-desktop-commander-supervisor\.ps1') { return $true }
    if ($cmd -match '(?i)fredwin-remote-bootstrap\.ps1') { return $true }
    if ($cmd -match '(?i)ssh-tunnel-service-managed\.ps1') { return $true }
    if ($cmd -match '(?i)mcp-server\.py.*--port\s+5557') { return $true }
    if ($cmd -match '(?i)run-browser-dispatcher\.ps1') { return $true }
    if ($cmd -match '(?i)desktop-commander.*remote.*--persist-session') { return $true }
    return $false
}

function Stop-Tree([int]$ProcessId,[string]$Reason,[string]$Cmd) {
    $safeCmd = ($Cmd -replace '\s+',' ').Trim()
    if ($safeCmd.Length -gt 260) { $safeCmd = $safeCmd.Substring(0,260) }
    if ($DryRun) {
        Write-JanitorLog ("WOULD_KILL_{0} pid={1} cmd={2}" -f $Reason,$ProcessId,$safeCmd)
        return
    }
    & "$env:SystemRoot\System32\taskkill.exe" /PID $ProcessId /T /F *> $null
    Write-JanitorLog ("KILLED_{0} pid={1} cmd={2}" -f $Reason,$ProcessId,$safeCmd)
}

function Install-JanitorTask {
    $name = 'ShopVivaliz Runtime Janitor'
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $args = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $PSCommandPath + '"'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $args -WorkingDirectory 'C:\site-shopvivaliz'
    $startup = New-ScheduledTaskTrigger -AtStartup
    $repeat = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Minutes 3)
    $settings.Hidden = $true
    Register-ScheduledTask -TaskName $name -Action $action -Trigger @($startup,$repeat) -Principal $principal -Settings $settings -Description 'Removes stale Desktop Commander command trees, orphan/temp headless browsers, stale temp profiles, and unwanted background startup state.' -Force | Out-Null
    Write-JanitorLog 'RUNTIME_JANITOR_TASK_INSTALLED=true'
}

if ($InstallTask) { Install-JanitorTask }

$rows = @(Get-Rows)
$byPid = @{}
foreach ($p in $rows) { $byPid[[int]$p.ProcessId] = $p }

$dcServerIds = @($rows | Where-Object {
    $_.Name -ieq 'node.exe' -and
    ([string]$_.CommandLine -match '(?i)desktop-commander[\\/]dist[\\/]index\.js') -and
    ([string]$_.CommandLine -notmatch '(?i)\bremote\b.*--persist-session')
} | ForEach-Object { [int]$_.ProcessId })

$staleShells = @($rows | Where-Object {
    $name = [string]$_.Name
    $cmd = [string]$_.CommandLine
    ($dcServerIds -contains [int]$_.ParentProcessId) -and
    ($name -match '^(?i)(powershell|pwsh|cmd|bash|sh|ssh)\.exe$') -and
    ((Get-AgeMinutes $_) -ge $StaleDcMinutes) -and
    -not (Test-ManagedCommand $cmd)
})
foreach ($p in $staleShells) {
    Stop-Tree -ProcessId ([int]$p.ProcessId) -Reason 'STALE_DC_SESSION' -Cmd ([string]$p.CommandLine)
}

$orphanShells = @($rows | Where-Object {
    $name = [string]$_.Name
    $cmd = [string]$_.CommandLine
    ([int]$_.ParentProcessId -gt 0) -and
    (-not $byPid.ContainsKey([int]$_.ParentProcessId)) -and
    ($name -match '^(?i)(powershell|pwsh|cmd|bash|sh|ssh)\.exe$') -and
    ((Get-AgeMinutes $_) -ge $StaleDcMinutes) -and
    -not (Test-ManagedCommand $cmd)
})
foreach ($p in $orphanShells) {
    Stop-Tree -ProcessId ([int]$p.ProcessId) -Reason 'ORPHAN_SHELL' -Cmd ([string]$p.CommandLine)
}

$headlessRoots = @($rows | Where-Object {
    $cmd = [string]$_.CommandLine
    (($_.Name -ieq 'chrome.exe') -or ($_.Name -ieq 'opera.exe')) -and
    ($cmd -match '(?i)--headless(?:=new)?') -and
    ($cmd -notmatch '(?i)--type=')
})
foreach ($p in $headlessRoots) {
    $cmd = [string]$p.CommandLine
    $parentAlive = $byPid.ContainsKey([int]$p.ParentProcessId)
    if (-not $parentAlive) {
        Stop-Tree -ProcessId ([int]$p.ProcessId) -Reason 'ORPHAN_HEADLESS' -Cmd $cmd
        continue
    }
    $isTempProfile = $cmd -match '(?i)--user-data-dir=(?:"?C:\\Temp\\)'
    $isShopVivalizTransientProfile =
        ($cmd -match '(?i)--user-data-dir=(?:"?C:\\ShopVivaliz\\)') -and
        ($cmd -match '(?i)profile') -and
        ($cmd -notmatch '(?i)C:\\ShopVivaliz\\amazon-returns-bridge\\profile')
    if ($isTempProfile -and (Get-AgeMinutes $p) -ge $TempHeadlessMinutes) {
        Stop-Tree -ProcessId ([int]$p.ProcessId) -Reason 'TEMP_HEADLESS' -Cmd $cmd
    } elseif ($isShopVivalizTransientProfile -and (Get-AgeMinutes $p) -ge $TempHeadlessMinutes) {
        Stop-Tree -ProcessId ([int]$p.ProcessId) -Reason 'TRANSIENT_HEADLESS' -Cmd $cmd
    }
}

if (-not $FixturePath) {
    try {
        Remove-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'Opera Developer' -ErrorAction SilentlyContinue
        New-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced' -Name RestartApps -PropertyType DWord -Value 0 -Force | Out-Null
    } catch { }

    $claudeUi = @($rows | Where-Object {
        $_.Name -ne 'cowork-svc.exe' -and
        (([string]$_.ExecutablePath -match '(?i)WindowsApps\\Claude_') -or ([string]$_.Name -match '^(?i)Claude\.exe$'))
    })
    $cowork = Get-Service -Name 'CoworkVMService' -ErrorAction SilentlyContinue
    if ($cowork -and $cowork.Status -eq 'Running' -and $claudeUi.Count -eq 0) {
        if ($DryRun) { Write-JanitorLog 'WOULD_STOP_UNUSED_CLAUDE_COWORK' }
        else { Stop-Service -Name 'CoworkVMService' -Force -ErrorAction SilentlyContinue; Write-JanitorLog 'STOPPED_UNUSED_CLAUDE_COWORK' }
    }

    foreach ($dir in @(Get-ChildItem -LiteralPath 'C:\Temp' -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^(?i)(dc-|guard-|probe-)' })) {
        $referenced = @($rows | Where-Object { [string]$_.CommandLine -like ('*' + $dir.FullName + '*') }).Count -gt 0
        if (-not $referenced -and (($NowTime - $dir.LastWriteTime).TotalMinutes -ge 60)) {
            if ($DryRun) { Write-JanitorLog ('WOULD_REMOVE_STALE_TEMP_PROFILE path=' + $dir.FullName) }
            else { Remove-Item -LiteralPath $dir.FullName -Recurse -Force -ErrorAction SilentlyContinue; Write-JanitorLog ('REMOVED_STALE_TEMP_PROFILE path=' + $dir.FullName) }
        }
    }
}

if ($staleShells.Count -eq 0 -and $orphanShells.Count -eq 0 -and $headlessRoots.Count -eq 0) { Write-JanitorLog 'RUNTIME_JANITOR_HEALTHY=true' }
exit 0
