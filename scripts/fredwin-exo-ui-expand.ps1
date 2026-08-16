$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class FredWinMouse {
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint LEFTDOWN = 0x0002;
    public const uint LEFTUP = 0x0004;
}
'@

$log = 'C:\site-shopvivaliz\logs\fredwin-exo-ui-expand.log'
New-Item -ItemType Directory -Force -Path (Split-Path $log) | Out-Null
Set-Content -LiteralPath $log -Value ('START=' + (Get-Date).ToString('o')) -Encoding UTF8

$process = Get-Process -ErrorAction SilentlyContinue | Where-Object {
    $_.MainWindowHandle -ne 0 -and $_.MainWindowTitle -match 'Segurança e Conformidade|Entidades restritas|Microsoft Defender|Exchange admin center'
} | Select-Object -First 1
if (-not $process) {
    Add-Content $log 'RESULT=NO_BROWSER_WINDOW'
    exit 2
}
Add-Content $log ('WINDOW=' + $process.ProcessName + '|' + $process.MainWindowTitle + '|SESSION=' + $process.SessionId)

$root = [System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
if (-not $root) {
    Add-Content $log 'RESULT=NO_AUTOMATION_ROOT'
    exit 3
}

$nameCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, 'Expandir')
$expand = $root.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $nameCond)
if (-not $expand) {
    Add-Content $log 'RESULT=EXPAND_NOT_FOUND'
    exit 4
}

$done = $false
$pattern = $null
if ($expand.TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$pattern)) {
    $pattern.Invoke()
    Add-Content $log 'METHOD=InvokePattern'
    $done = $true
}

if (-not $done) {
    try {
        $legacy = $null
        if ($expand.TryGetCurrentPattern([System.Windows.Automation.LegacyIAccessiblePattern]::Pattern, [ref]$legacy)) {
            $legacy.DoDefaultAction()
            Add-Content $log 'METHOD=LegacyIAccessiblePattern'
            $done = $true
        }
    } catch {
        Add-Content $log ('LEGACY_ERROR=' + $_.Exception.Message)
    }
}

if (-not $done) {
    $rect = $expand.Current.BoundingRectangle
    if ($rect.IsEmpty -or $rect.Width -le 0 -or $rect.Height -le 0) {
        Add-Content $log 'RESULT=EXPAND_NO_CLICKABLE_BOUNDS'
        exit 5
    }
    $x = [int]($rect.X + ($rect.Width / 2))
    $y = [int]($rect.Y + ($rect.Height / 2))
    [FredWinMouse]::SetForegroundWindow($process.MainWindowHandle) | Out-Null
    Start-Sleep -Milliseconds 500
    [FredWinMouse]::SetCursorPos($x, $y) | Out-Null
    Start-Sleep -Milliseconds 200
    [FredWinMouse]::mouse_event([FredWinMouse]::LEFTDOWN, 0, 0, 0, [UIntPtr]::Zero)
    [FredWinMouse]::mouse_event([FredWinMouse]::LEFTUP, 0, 0, 0, [UIntPtr]::Zero)
    Add-Content $log ('METHOD=BoundingRectangleMouseClick|X=' + $x + '|Y=' + $y)
    $done = $true
}

Start-Sleep -Seconds 3
if ($done) {
    Add-Content $log 'RESULT=EXPANDED'
    Add-Content $log ('END=' + (Get-Date).ToString('o'))
    exit 0
}
Add-Content $log 'RESULT=FAILED'
exit 6
