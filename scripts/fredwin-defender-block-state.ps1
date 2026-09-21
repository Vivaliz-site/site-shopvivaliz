$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes

$Sender = 'NAORESPONDA@DEV.SHOPVIVALIZ.COM.BR'
$process = Get-Process opera -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 } | Select-Object -First 1
if (-not $process) { Write-Output 'STATE=NO_OPERA_WINDOW'; exit 2 }
$root = [System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
if (-not $root) { Write-Output 'STATE=NO_UIA_ROOT'; exit 3 }

function Get-All($Root) {
    return $Root.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
}
function Visible-By-Name($Root, [string]$Name, [string]$TypeName = '') {
    $items = @()
    $all = Get-All $Root
    for ($i=0; $i -lt $all.Count; $i++) {
        $e = $all.Item($i)
        try {
            if ($e.Current.IsOffscreen -or -not $e.Current.IsEnabled) { continue }
            if ($e.Current.Name -ne $Name) { continue }
            if ($TypeName -and $e.Current.ControlType.ProgrammaticName -ne $TypeName) { continue }
            $items += $e
        } catch {}
    }
    return $items
}
function Invoke-If-Visible($Root, [string]$Name) {
    $items = @(Visible-By-Name $Root $Name 'ControlType.Button')
    if ($items.Count -lt 1) { return $false }
    $p = $null
    if ($items[0].TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$p)) {
        $p.Invoke()
        Write-Output ('DISMISSED=' + $Name)
        Start-Sleep -Seconds 2
        return $true
    }
    return $false
}

$tabs = @(Visible-By-Name $root 'Entidades restritas - Microsoft Defender' 'ControlType.TabItem')
if ($tabs.Count -lt 1) { Write-Output 'STATE=DEFENDER_TAB_NOT_FOUND'; exit 4 }
$tab = $null; $maxX = -999999
foreach ($candidate in $tabs) {
    $x = $candidate.Current.BoundingRectangle.X
    if ($x -gt $maxX) { $maxX = $x; $tab = $candidate }
}
$pattern = $null
if ($tab.TryGetCurrentPattern([System.Windows.Automation.SelectionItemPattern]::Pattern, [ref]$pattern)) {
    $pattern.Select()
} else {
    Write-Output 'STATE=DEFENDER_TAB_NOT_SELECTABLE'; exit 5
}
Start-Sleep -Seconds 4
$process = Get-Process opera -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 } | Select-Object -First 1
$root = [System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
Invoke-If-Visible $root 'Got it' | Out-Null
Invoke-If-Visible $root 'dismiss' | Out-Null
Start-Sleep -Seconds 2
$process = Get-Process opera -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 } | Select-Object -First 1
$root = [System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
$all = Get-All $root
$senderVisible = $false
$unblockVisible = $false
$confirmVisible = $false
$details = @()
for ($i=0; $i -lt $all.Count; $i++) {
    $e=$all.Item($i)
    try {
        if ($e.Current.IsOffscreen) { continue }
        $n=$e.Current.Name
        if (-not $n) { continue }
        if ($n -ieq $Sender) { $senderVisible = $true }
        if ($n -eq 'Desbloquear') { $unblockVisible = $true }
        if ($n -match 'Tem certeza.*desbloquear') { $confirmVisible = $true }
        if ($n -match '^0 item|^1 item|Nenhum|restrit|desbloque|No restricted|Restricted entities|Usu.rio restrito') { $details += $n }
    } catch {}
}
Write-Output ('WINDOW=' + $process.MainWindowTitle)
Write-Output ('SENDER_VISIBLE=' + $senderVisible.ToString().ToLower())
Write-Output ('UNBLOCK_VISIBLE=' + $unblockVisible.ToString().ToLower())
Write-Output ('CONFIRM_VISIBLE=' + $confirmVisible.ToString().ToLower())
foreach ($d in ($details | Select-Object -Unique)) { Write-Output ('DETAIL=' + $d) }
if ($senderVisible) { Write-Output 'STATE=STILL_RESTRICTED_OR_PROPAGATING'; exit 10 }
Write-Output 'STATE=NOT_VISIBLE_IN_RESTRICTED_ENTITIES'
exit 0
