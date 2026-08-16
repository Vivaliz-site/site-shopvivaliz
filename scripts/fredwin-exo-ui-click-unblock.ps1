$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class FredWinUnblockMouse {
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint LEFTDOWN = 0x0002;
    public const uint LEFTUP = 0x0004;
}
'@
$log = 'C:\site-shopvivaliz\logs\fredwin-exo-ui-unblock.log'
Set-Content -LiteralPath $log -Value ('START=' + (Get-Date).ToString('o')) -Encoding UTF8

function Invoke-UiElement {
    param(
        [Parameter(Mandatory=$true)]$Element,
        [Parameter(Mandatory=$true)]$Process,
        [Parameter(Mandatory=$true)][string]$Label
    )
    $pattern = $null
    if ($Element.TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$pattern)) {
        $pattern.Invoke(); Add-Content $log ('METHOD=' + $Label + ':InvokePattern'); return
    }
    try {
        $legacy = $null
        if ($Element.TryGetCurrentPattern([System.Windows.Automation.LegacyIAccessiblePattern]::Pattern, [ref]$legacy)) {
            $legacy.DoDefaultAction(); Add-Content $log ('METHOD=' + $Label + ':LegacyIAccessiblePattern'); return
        }
    } catch { Add-Content $log ('LEGACY_ERROR=' + $Label + ':' + $_.Exception.Message) }
    $rect = $Element.Current.BoundingRectangle
    if ($rect.IsEmpty -or $rect.Width -le 0 -or $rect.Height -le 0) { throw ($Label + '_NO_CLICKABLE_BOUNDS') }
    $x = [int]($rect.X + ($rect.Width / 2)); $y = [int]($rect.Y + ($rect.Height / 2))
    [FredWinUnblockMouse]::SetForegroundWindow($Process.MainWindowHandle) | Out-Null
    Start-Sleep -Milliseconds 500
    [FredWinUnblockMouse]::SetCursorPos($x,$y) | Out-Null
    Start-Sleep -Milliseconds 200
    [FredWinUnblockMouse]::mouse_event([FredWinUnblockMouse]::LEFTDOWN,0,0,0,[UIntPtr]::Zero)
    [FredWinUnblockMouse]::mouse_event([FredWinUnblockMouse]::LEFTUP,0,0,0,[UIntPtr]::Zero)
    Add-Content $log ('METHOD=' + $Label + ':BoundingRectangleMouseClick|X=' + $x + '|Y=' + $y)
}

$senderCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, 'NAORESPONDA@DEV.SHOPVIVALIZ.COM.BR')
$unblockCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, 'Desbloquear')
$expandCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, 'Expandir')

$process = $null
$root = $null
$windows = @(Get-Process opera -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 })
Add-Content $log ('OPERA_WINDOWS=' + $windows.Count)
foreach ($candidate in $windows) {
    Add-Content $log ('CANDIDATE=' + $candidate.Id + '|' + $candidate.MainWindowTitle + '|SESSION=' + $candidate.SessionId)
    try {
        $candidateRoot = [System.Windows.Automation.AutomationElement]::FromHandle($candidate.MainWindowHandle)
        if (-not $candidateRoot) { continue }
        $sender = $candidateRoot.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $senderCond)
        if ($sender) { $process=$candidate; $root=$candidateRoot; break }
    } catch { Add-Content $log ('CANDIDATE_ERROR=' + $_.Exception.Message) }
}
if (-not $root) { Add-Content $log 'RESULT=RESTRICTED_SENDER_PAGE_NOT_ACTIVE'; exit 3 }
Add-Content $log ('WINDOW=' + $process.ProcessName + '|' + $process.MainWindowTitle + '|SESSION=' + $process.SessionId)

$target = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $unblockCond)
if (-not $target) {
    $expand = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $expandCond)
    if (-not $expand) { Add-Content $log 'RESULT=EXPAND_AND_UNBLOCK_NOT_FOUND'; exit 4 }
    Invoke-UiElement -Element $expand -Process $process -Label 'EXPAND'
    Start-Sleep -Seconds 3
    $root = [System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
    $target = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $unblockCond)
}
if (-not $target) { Add-Content $log 'RESULT=UNBLOCK_NOT_FOUND_AFTER_EXPAND'; exit 5 }
Invoke-UiElement -Element $target -Process $process -Label 'UNBLOCK'
Start-Sleep -Seconds 3
Add-Content $log 'RESULT=UNBLOCK_ACTION_CLICKED'
Add-Content $log ('END=' + (Get-Date).ToString('o'))
exit 0
