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
$process = Get-Process -ErrorAction SilentlyContinue | Where-Object {
    $_.MainWindowHandle -ne 0 -and $_.MainWindowTitle -match 'Segurança e Conformidade|Entidades restritas|Microsoft Defender'
} | Select-Object -First 1
if (-not $process) { Add-Content $log 'RESULT=NO_BROWSER_WINDOW'; exit 2 }
Add-Content $log ('WINDOW=' + $process.ProcessName + '|' + $process.MainWindowTitle + '|SESSION=' + $process.SessionId)
$root = [System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
$nameCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, 'Desbloquear')
$target = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $nameCond)
if (-not $target) { Add-Content $log 'RESULT=UNBLOCK_NOT_FOUND'; exit 3 }
$done = $false
$pattern = $null
if ($target.TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$pattern)) {
    $pattern.Invoke(); Add-Content $log 'METHOD=InvokePattern'; $done = $true
}
if (-not $done) {
    try {
        $legacy = $null
        if ($target.TryGetCurrentPattern([System.Windows.Automation.LegacyIAccessiblePattern]::Pattern, [ref]$legacy)) {
            $legacy.DoDefaultAction(); Add-Content $log 'METHOD=LegacyIAccessiblePattern'; $done = $true
        }
    } catch { Add-Content $log ('LEGACY_ERROR=' + $_.Exception.Message) }
}
if (-not $done) {
    $rect = $target.Current.BoundingRectangle
    if ($rect.IsEmpty -or $rect.Width -le 0 -or $rect.Height -le 0) { Add-Content $log 'RESULT=UNBLOCK_NO_CLICKABLE_BOUNDS'; exit 4 }
    $x = [int]($rect.X + ($rect.Width / 2)); $y = [int]($rect.Y + ($rect.Height / 2))
    [FredWinUnblockMouse]::SetForegroundWindow($process.MainWindowHandle) | Out-Null
    Start-Sleep -Milliseconds 500
    [FredWinUnblockMouse]::SetCursorPos($x,$y) | Out-Null
    Start-Sleep -Milliseconds 200
    [FredWinUnblockMouse]::mouse_event([FredWinUnblockMouse]::LEFTDOWN,0,0,0,[UIntPtr]::Zero)
    [FredWinUnblockMouse]::mouse_event([FredWinUnblockMouse]::LEFTUP,0,0,0,[UIntPtr]::Zero)
    Add-Content $log ('METHOD=BoundingRectangleMouseClick|X=' + $x + '|Y=' + $y)
    $done = $true
}
Start-Sleep -Seconds 3
if ($done) { Add-Content $log 'RESULT=UNBLOCK_ACTION_CLICKED'; exit 0 }
Add-Content $log 'RESULT=FAILED'; exit 5
