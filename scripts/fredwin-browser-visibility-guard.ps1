param(
    [int]$MinAgeSeconds = 20,
    [switch]$DryRun
)
$ErrorActionPreference = 'SilentlyContinue'
$BrowserNames = @('chrome.exe','opera.exe','msedge.exe','firefox.exe')
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'fredwin-browser-visibility-guard.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Write-GuardLog([string]$Message) {
    $line = ('{0} - {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
    $line | Out-File -FilePath $LogFile -Append -Encoding utf8
    Write-Output $Message
}

$rows = @(Get-CimInstance Win32_Process)
$roots = @($rows | Where-Object {
    ($BrowserNames -contains $_.Name) -and
    ([string]$_.CommandLine -notmatch '(?i)--type=')
})

foreach ($root in $roots) {
    $proc = Get-Process -Id $root.ProcessId -ErrorAction SilentlyContinue
    if (-not $proc) { continue }
    $age = ((Get-Date) - $proc.StartTime).TotalSeconds
    if ($age -lt $MinAgeSeconds) { continue }
    $tree = @([int]$root.ProcessId)
    for ($i = 0; $i -lt $tree.Count; $i++) {
        $parentId = $tree[$i]
        $children = @($rows | Where-Object { [int]$_.ParentProcessId -eq $parentId })
        foreach ($child in $children) {
            $childId = [int]$child.ProcessId
            if ($tree -notcontains $childId) { $tree += $childId }
        }
    }

    $visible = $false
    foreach ($treeId in $tree) {
        $treeProc = Get-Process -Id $treeId -ErrorAction SilentlyContinue
        if ($treeProc -and [int64]$treeProc.MainWindowHandle -ne 0) {
            $visible = $true
            break
        }
    }
    if ($visible) { continue }

    $cmd = ([string]$root.CommandLine -replace '\s+',' ').Trim()
    if ($DryRun) {
        Write-GuardLog ("WOULD_KILL_HIDDEN_BROWSER name={0} pid={1} age={2:N0}s cmd={3}" -f $root.Name,$root.ProcessId,$age,$cmd)
    } else {
        & "$env:SystemRoot\System32\taskkill.exe" /PID $root.ProcessId /T /F *> $null
        Write-GuardLog ("KILLED_HIDDEN_BROWSER name={0} pid={1} age={2:N0}s cmd={3}" -f $root.Name,$root.ProcessId,$age,$cmd)
    }
}
exit 0
