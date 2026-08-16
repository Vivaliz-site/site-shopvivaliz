$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes

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
    $buttons = $root.FindAll([System.Windows.Automation.TreeScope]::Descendants, (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty, [System.Windows.Automation.ControlType]::Button)))
    foreach ($b in $buttons) {
        if ($b.Current.Name) { Add-Content $log ('BUTTON=' + $b.Current.Name) }
    }
    exit 4
}

$pattern = $null
if (-not $expand.TryGetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern, [ref]$pattern)) {
    Add-Content $log 'RESULT=EXPAND_NO_INVOKE_PATTERN'
    exit 5
}
$pattern.Invoke()
Start-Sleep -Seconds 3
Add-Content $log 'RESULT=EXPANDED'
Add-Content $log ('END=' + (Get-Date).ToString('o'))
