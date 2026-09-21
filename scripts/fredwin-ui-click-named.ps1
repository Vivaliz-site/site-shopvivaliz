param(
    [Parameter(Mandatory=$true)][string]$Name,
    [string]$RequireText = ''
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class FredWinNamedMouse {
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint LEFTDOWN = 0x0002;
    public const uint LEFTUP = 0x0004;
}
'@
$log = 'C:\site-shopvivaliz\logs\fredwin-ui-click-named.log'
Set-Content -LiteralPath $log -Value ('START=' + (Get-Date).ToString('o') + '|NAME=' + $Name + '|REQUIRE=' + $RequireText) -Encoding UTF8
$targetCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, $Name)
$requireCond = $null
if (-not [string]::IsNullOrWhiteSpace($RequireText)) {
    $requireCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, $RequireText)
}
$process = $null
$target = $null
$windows = @(Get-Process opera -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 })
Add-Content $log ('OPERA_WINDOWS=' + $windows.Count)
foreach ($candidate in $windows) {
    Add-Content $log ('CANDIDATE=' + $candidate.Id + '|' + $candidate.MainWindowTitle + '|SESSION=' + $candidate.SessionId)
    try {
        $root = [System.Windows.Automation.AutomationElement]::FromHandle($candidate.MainWindowHandle)
        if (-not $root) { continue }
        if ($requireCond) {
            $required = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $requireCond)
            if (-not $required) { continue }
        }
        $found = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $targetCond)
        if ($found) { $process=$candidate; $target=$found; break }
    } catch { Add-Content $log ('CANDIDATE_ERROR=' + $_.Exception.Message) }
}
if (-not $target) { Add-Content $log 'RESULT=TARGET_NOT_FOUND'; exit 3 }
Add-Content $log ('WINDOW=' + $process.MainWindowTitle + '|SESSION=' + $process.SessionId)
$pattern = $null
if ($target.TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$pattern)) {
    $pattern.Invoke(); Add-Content $log 'METHOD=InvokePattern'; Start-Sleep -Seconds 3; Add-Content $log 'RESULT=CLICKED'; exit 0
}
try {
    $legacy = $null
    if ($target.TryGetCurrentPattern([System.Windows.Automation.LegacyIAccessiblePattern]::Pattern, [ref]$legacy)) {
        $legacy.DoDefaultAction(); Add-Content $log 'METHOD=LegacyIAccessiblePattern'; Start-Sleep -Seconds 3; Add-Content $log 'RESULT=CLICKED'; exit 0
    }
} catch { Add-Content $log ('LEGACY_ERROR=' + $_.Exception.Message) }
$rect = $target.Current.BoundingRectangle
if ($rect.IsEmpty -or $rect.Width -le 0 -or $rect.Height -le 0) { Add-Content $log 'RESULT=NO_CLICKABLE_BOUNDS'; exit 4 }
$x=[int]($rect.X + ($rect.Width/2)); $y=[int]($rect.Y + ($rect.Height/2))
[FredWinNamedMouse]::SetForegroundWindow($process.MainWindowHandle) | Out-Null
Start-Sleep -Milliseconds 400
[FredWinNamedMouse]::SetCursorPos($x,$y) | Out-Null
Start-Sleep -Milliseconds 200
[FredWinNamedMouse]::mouse_event([FredWinNamedMouse]::LEFTDOWN,0,0,0,[UIntPtr]::Zero)
[FredWinNamedMouse]::mouse_event([FredWinNamedMouse]::LEFTUP,0,0,0,[UIntPtr]::Zero)
Add-Content $log ('METHOD=BoundingRectangleMouseClick|X=' + $x + '|Y=' + $y)
Start-Sleep -Seconds 3
Add-Content $log 'RESULT=CLICKED'
exit 0
