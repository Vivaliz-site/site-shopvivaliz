$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class FredWinConfirmMouse {
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint LEFTDOWN = 0x0002;
    public const uint LEFTUP = 0x0004;
}
'@
$log='C:\site-shopvivaliz\logs\fredwin-exo-ui-confirm-visual.log'
Set-Content -LiteralPath $log -Value ('START=' + (Get-Date).ToString('o')) -Encoding UTF8
$process = Get-Process opera -ErrorAction SilentlyContinue | Where-Object {
    $_.MainWindowHandle -ne 0 -and $_.MainWindowTitle -match 'Entidades restritas.*Microsoft Defender'
} | Select-Object -First 1
if (-not $process) { Add-Content $log 'RESULT=DEFENDER_WINDOW_NOT_FOUND'; exit 2 }
Add-Content $log ('WINDOW=' + $process.MainWindowTitle + '|SESSION=' + $process.SessionId)
$root=[System.Windows.Automation.AutomationElement]::FromHandle($process.MainWindowHandle)
if (-not $root) { Add-Content $log 'RESULT=NO_AUTOMATION_ROOT'; exit 3 }
$docCond=New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty,[System.Windows.Automation.ControlType]::Document)
$docs=$root.FindAll([System.Windows.Automation.TreeScope]::Descendants,$docCond)
$doc=$null
foreach($d in $docs){
    $r=$d.Current.BoundingRectangle
    Add-Content $log ('DOC=' + $d.Current.Name + '|X=' + [int]$r.X + '|Y=' + [int]$r.Y + '|W=' + [int]$r.Width + '|H=' + [int]$r.Height)
    if($r.Width -gt 900 -and $r.Height -gt 400){ $doc=$d; break }
}
if(-not $doc){ Add-Content $log 'RESULT=DOCUMENT_VIEWPORT_NOT_FOUND'; exit 5 }
$rect=$doc.Current.BoundingRectangle
# Guarded by the active Defender Restricted Entities window and the immediately
# revalidated screenshot of the confirmation modal. The affirmative button
# center is approximately 60.0% across and 59.6% down the page viewport.
$x=[int]($rect.X + ($rect.Width * 0.6000))
$y=[int]($rect.Y + ($rect.Height * 0.5960))
Add-Content $log ('CLICK_TARGET=X=' + $x + '|Y=' + $y + '|DOCW=' + [int]$rect.Width + '|DOCH=' + [int]$rect.Height)
[FredWinConfirmMouse]::SetForegroundWindow($process.MainWindowHandle) | Out-Null
Start-Sleep -Milliseconds 700
[FredWinConfirmMouse]::SetCursorPos($x,$y) | Out-Null
Start-Sleep -Milliseconds 300
[FredWinConfirmMouse]::mouse_event([FredWinConfirmMouse]::LEFTDOWN,0,0,0,[UIntPtr]::Zero)
[FredWinConfirmMouse]::mouse_event([FredWinConfirmMouse]::LEFTUP,0,0,0,[UIntPtr]::Zero)
Start-Sleep -Seconds 4
Add-Content $log 'RESULT=CONFIRM_CLICKED'
Add-Content $log ('END=' + (Get-Date).ToString('o'))
