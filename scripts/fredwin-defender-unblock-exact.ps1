$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class FredWinGuardedMouse {
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint flags, uint dx, uint dy, uint data, UIntPtr extra);
    public const uint LEFTDOWN = 0x0002;
    public const uint LEFTUP = 0x0004;
}
'@

$Sender = 'NAORESPONDA@DEV.SHOPVIVALIZ.COM.BR'
$Log = 'C:\site-shopvivaliz\logs\fredwin-defender-unblock-exact.log'
Set-Content -LiteralPath $Log -Value ('START=' + (Get-Date).ToString('o')) -Encoding UTF8

function Log([string]$Text) {
    Add-Content -LiteralPath $Log -Value $Text -Encoding UTF8
    Write-Output $Text
}

function Get-OperaWindow {
    return Get-Process opera -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 } | Select-Object -First 1
}

function Get-Root($Process) {
    return [System.Windows.Automation.AutomationElement]::FromHandle($Process.MainWindowHandle)
}

function Get-All($Root) {
    return $Root.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
}

function Find-VisibleRegex($Root, [string]$Pattern, [string]$TypeName = '') {
    $all = Get-All $Root
    for ($i = 0; $i -lt $all.Count; $i++) {
        $e = $all.Item($i)
        try {
            $name = $e.Current.Name
            if (-not $name) { continue }
            if ($name -notmatch $Pattern) { continue }
            if ($e.Current.IsOffscreen) { continue }
            if (-not $e.Current.IsEnabled) { continue }
            if ($TypeName -and $e.Current.ControlType.ProgrammaticName -ne $TypeName) { continue }
            return $e
        } catch {}
    }
    return $null
}

function Find-All-VisibleRegex($Root, [string]$Pattern, [string]$TypeName = '') {
    $matches = @()
    $all = Get-All $Root
    for ($i = 0; $i -lt $all.Count; $i++) {
        $e = $all.Item($i)
        try {
            $name = $e.Current.Name
            if (-not $name) { continue }
            if ($name -notmatch $Pattern) { continue }
            if ($e.Current.IsOffscreen) { continue }
            if (-not $e.Current.IsEnabled) { continue }
            if ($TypeName -and $e.Current.ControlType.ProgrammaticName -ne $TypeName) { continue }
            $matches += $e
        } catch {}
    }
    return $matches
}

function Click-Element($Process, $Element, [string]$Label) {
    if (-not $Element) { throw "Element not found: $Label" }
    $name = $Element.Current.Name
    $type = $Element.Current.ControlType.ProgrammaticName
    $aid = $Element.Current.AutomationId
    $rect = $Element.Current.BoundingRectangle
    Log ('CLICK_ATTEMPT|LABEL=' + $Label + '|NAME=' + $name + '|TYPE=' + $type + '|AID=' + $aid + '|X=' + [math]::Round($rect.X) + '|Y=' + [math]::Round($rect.Y) + '|W=' + [math]::Round($rect.Width) + '|H=' + [math]::Round($rect.Height))
    [FredWinGuardedMouse]::SetForegroundWindow($Process.MainWindowHandle) | Out-Null
    Start-Sleep -Milliseconds 400
    $pattern = $null
    try {
        if ($Element.TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$pattern)) {
            $pattern.Invoke()
            Log ('CLICK_OK|METHOD=InvokePattern|LABEL=' + $Label)
            return
        }
    } catch { Log ('CLICK_INVOKE_ERROR|LABEL=' + $Label + '|' + $_.Exception.Message) }
    $pattern = $null
    try {
        if ($Element.TryGetCurrentPattern([System.Windows.Automation.SelectionItemPattern]::Pattern, [ref]$pattern)) {
            $pattern.Select()
            Log ('CLICK_OK|METHOD=SelectionItemPattern|LABEL=' + $Label)
            return
        }
    } catch { Log ('CLICK_SELECT_ERROR|LABEL=' + $Label + '|' + $_.Exception.Message) }
    $pattern = $null
    try {
        if ($Element.TryGetCurrentPattern([System.Windows.Automation.LegacyIAccessiblePattern]::Pattern, [ref]$pattern)) {
            $pattern.DoDefaultAction()
            Log ('CLICK_OK|METHOD=LegacyIAccessiblePattern|LABEL=' + $Label)
            return
        }
    } catch { Log ('CLICK_LEGACY_ERROR|LABEL=' + $Label + '|' + $_.Exception.Message) }
    if ($rect.IsEmpty -or $rect.Width -le 0 -or $rect.Height -le 0) { throw "No clickable bounds: $Label" }
    $x = [int]($rect.X + ($rect.Width / 2))
    $y = [int]($rect.Y + ($rect.Height / 2))
    [FredWinGuardedMouse]::SetCursorPos($x, $y) | Out-Null
    Start-Sleep -Milliseconds 200
    [FredWinGuardedMouse]::mouse_event([FredWinGuardedMouse]::LEFTDOWN,0,0,0,[UIntPtr]::Zero)
    [FredWinGuardedMouse]::mouse_event([FredWinGuardedMouse]::LEFTUP,0,0,0,[UIntPtr]::Zero)
    Log ('CLICK_OK|METHOD=BoundingRectangleMouse|LABEL=' + $Label + '|X=' + $x + '|Y=' + $y)
}

function Refresh-Context {
    $script:Process = Get-OperaWindow
    if (-not $script:Process) { throw 'Opera main window not found' }
    $script:Root = Get-Root $script:Process
    if (-not $script:Root) { throw 'Opera UIA root not found' }
    Log ('WINDOW|TITLE=' + $script:Process.MainWindowTitle + '|PID=' + $script:Process.Id + '|SESSION=' + $script:Process.SessionId)
}

Refresh-Context
$tabs = Find-All-VisibleRegex $Root '^Entidades restritas - Microsoft Defender$' 'ControlType.TabItem'
if ($tabs.Count -lt 1) { throw 'Defender restricted entities tab not found' }
$tab = $tabs | Sort-Object { $_.Current.BoundingRectangle.X } -Descending | Select-Object -First 1
Click-Element $Process $tab 'DefenderTab'
Start-Sleep -Seconds 3
Refresh-Context

$senderVisible = Find-VisibleRegex $Root ([regex]::Escape($Sender))
if (-not $senderVisible) {
    Log 'RESULT=SENDER_NOT_VISIBLE_AFTER_TAB_SELECT'
    exit 12
}
Log 'GUARD=SENDER_VISIBLE'

function Click-And-Refresh([string]$Pattern, [string]$TypeName, [string]$Label, [int]$SleepSeconds = 2) {
    $element = Find-VisibleRegex $script:Root $Pattern $TypeName
    if (-not $element) { return $false }
    Click-Element $script:Process $element $Label
    Start-Sleep -Seconds $SleepSeconds
    Refresh-Context
    return $true
}

$confirmText = Find-VisibleRegex $Root 'Tem certeza.*desbloquear.*usu.rio'
$simButton = Find-VisibleRegex $Root '^Sim$' 'ControlType.Button'
if ($confirmText -and $simButton) {
    Click-Element $Process $simButton 'ConfirmSim'
    Start-Sleep -Seconds 5
    Log 'RESULT=UNBLOCK_CONFIRM_CLICKED_FROM_CONFIRM_DIALOG'
    exit 0
}

if (Click-And-Refresh '^Enviar$' 'ControlType.Button' 'Enviar' 2) {
    $senderGuard2 = Find-VisibleRegex $Root ([regex]::Escape($Sender))
    if (-not $senderGuard2) { throw 'Sender guard lost after Enviar' }
    $confirmText = Find-VisibleRegex $Root 'Tem certeza.*desbloquear.*usu.rio'
    $simButton = Find-VisibleRegex $Root '^Sim$' 'ControlType.Button'
    if ($confirmText -and $simButton) {
        Click-Element $Process $simButton 'ConfirmSim'
        Start-Sleep -Seconds 5
        Log 'RESULT=UNBLOCK_CONFIRM_CLICKED_AFTER_ENVIAR'
        exit 0
    }
}

if (Click-And-Refresh '^Pr.xima$' 'ControlType.Button' 'Proxima' 2) {
    $senderGuard3 = Find-VisibleRegex $Root ([regex]::Escape($Sender))
    if (-not $senderGuard3) { throw 'Sender guard lost after Proxima' }
    if (-not (Click-And-Refresh '^Enviar$' 'ControlType.Button' 'Enviar' 2)) { throw 'Enviar not found after Proxima' }
    $confirmText = Find-VisibleRegex $Root 'Tem certeza.*desbloquear.*usu.rio'
    $simButton = Find-VisibleRegex $Root '^Sim$' 'ControlType.Button'
    if ($confirmText -and $simButton) {
        Click-Element $Process $simButton 'ConfirmSim'
        Start-Sleep -Seconds 5
        Log 'RESULT=UNBLOCK_CONFIRM_CLICKED_AFTER_PROXIMA_ENVIAR'
        exit 0
    }
}

$expand = Find-VisibleRegex $Root '^Expandir$' 'ControlType.Button'
if ($expand) {
    Click-Element $Process $expand 'Expandir'
    Start-Sleep -Seconds 2
    Refresh-Context
    $senderGuard4 = Find-VisibleRegex $Root ([regex]::Escape($Sender))
    if (-not $senderGuard4) { throw 'Sender guard lost after Expandir' }
    if (-not (Click-And-Refresh '^Desbloquear$' '' 'Desbloquear' 2)) { throw 'Desbloquear action not found' }
    $senderGuard5 = Find-VisibleRegex $Root ([regex]::Escape($Sender))
    if (-not $senderGuard5) { throw 'Sender guard lost after Desbloquear' }
    if (Click-And-Refresh '^Pr.xima$' 'ControlType.Button' 'Proxima' 2) { }
    if (-not (Click-And-Refresh '^Enviar$' 'ControlType.Button' 'Enviar' 2)) { throw 'Enviar not found in unblock wizard' }
    $confirmText = Find-VisibleRegex $Root 'Tem certeza.*desbloquear.*usu.rio'
    $simButton = Find-VisibleRegex $Root '^Sim$' 'ControlType.Button'
    if (-not ($confirmText -and $simButton)) { throw 'Final confirmation dialog not found' }
    Click-Element $Process $simButton 'ConfirmSim'
    Start-Sleep -Seconds 5
    Log 'RESULT=UNBLOCK_CONFIRM_CLICKED_FROM_LIST'
    exit 0
}

Log 'RESULT=NO_RECOGNIZED_UNBLOCK_STATE'
exit 13
