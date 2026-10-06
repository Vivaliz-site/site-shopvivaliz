param(
    [ValidateSet('Dispatch','Worker')]
    [string]$Mode = 'Dispatch',
    [string]$RequestPath,
    [string]$ResponsePath,
    [string]$ScreenshotPath
)

$ErrorActionPreference = 'Stop'
$Root = 'C:\ProgramData\ShopVivaliz\RemoteDesktopBridge'
$TaskPrefix = 'ShopVivaliz Native Desktop Bridge'

function Write-JsonResponse([hashtable]$Value) {
    $json = $Value | ConvertTo-Json -Compress -Depth 6
    [Console]::Out.Write($json)
}

function Set-PrivateFileAcl([string]$Path, [string]$UserName) {
    $acl = New-Object System.Security.AccessControl.FileSecurity
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($identity in @('SYSTEM', 'BUILTIN\Administrators', $UserName)) {
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            $identity,
            'FullControl',
            'Allow'
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Write-WorkerResponse([hashtable]$Value) {
    if (-not $ResponsePath) { throw 'response_path_required' }
    $Value | ConvertTo-Json -Compress -Depth 6 | Set-Content -LiteralPath $ResponsePath -Encoding UTF8
}

function Invoke-Worker {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class ShopVivalizDesktopInput {
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint flags, uint dx, uint dy, uint data, UIntPtr extraInfo);
}
"@

    if (-not $RequestPath -or -not (Test-Path -LiteralPath $RequestPath)) {
        throw 'request_path_missing'
    }
    $request = Get-Content -LiteralPath $RequestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $action = [string]$request.action
    $bounds = [System.Windows.Forms.SystemInformation]::VirtualScreen
    $base = @{
        ok = $true
        action = $action
        left = [int]$bounds.Left
        top = [int]$bounds.Top
        width = [int]$bounds.Width
        height = [int]$bounds.Height
        session_id = [System.Diagnostics.Process]::GetCurrentProcess().SessionId
    }

    switch ($action) {
        'health' {
            Write-WorkerResponse $base
            return
        }
        'screenshot' {
            if (-not $ScreenshotPath) { throw 'screenshot_path_required' }
            $bitmap = New-Object System.Drawing.Bitmap $bounds.Width, $bounds.Height
            $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
            try {
                $graphics.CopyFromScreen($bounds.Location, [System.Drawing.Point]::Empty, $bounds.Size)
                $bitmap.Save($ScreenshotPath, [System.Drawing.Imaging.ImageFormat]::Png)
            } finally {
                $graphics.Dispose()
                $bitmap.Dispose()
            }
            Write-WorkerResponse $base
            return
        }
        'click' {
            $x = [int]$request.x
            $y = [int]$request.y
            $clicks = if ($null -eq $request.clicks) { 1 } else { [int]$request.clicks }
            $button = if ([string]::IsNullOrWhiteSpace([string]$request.button)) { 'left' } else { [string]$request.button }
            if ($x -lt 0 -or $y -lt 0 -or $x -ge $bounds.Width -or $y -ge $bounds.Height) {
                throw 'desktop_click_outside_virtual_screen'
            }
            if ($clicks -lt 1 -or $clicks -gt 3) { throw 'desktop_click_count_invalid' }
            $flags = switch ($button) {
                'left'   { @(0x0002, 0x0004) }
                'right'  { @(0x0008, 0x0010) }
                'middle' { @(0x0020, 0x0040) }
                default  { throw 'desktop_invalid_mouse_button' }
            }
            $absoluteX = $bounds.Left + $x
            $absoluteY = $bounds.Top + $y
            if (-not [ShopVivalizDesktopInput]::SetCursorPos($absoluteX, $absoluteY)) {
                throw 'desktop_cursor_move_failed'
            }
            for ($i = 0; $i -lt $clicks; $i++) {
                [ShopVivalizDesktopInput]::mouse_event([uint32]$flags[0], 0, 0, 0, [UIntPtr]::Zero)
                [ShopVivalizDesktopInput]::mouse_event([uint32]$flags[1], 0, 0, 0, [UIntPtr]::Zero)
                Start-Sleep -Milliseconds 80
            }
            $base.x = $x
            $base.y = $y
            $base.button = $button
            $base.clicks = $clicks
            Write-WorkerResponse $base
            return
        }
        'type' {
            $text = [string]$request.text
            if ([string]::IsNullOrEmpty($text)) { throw 'desktop_text_required' }
            if ($text.Length -gt 4096) { throw 'desktop_text_too_long' }
            try {
                [System.Windows.Forms.Clipboard]::SetText($text)
                [System.Windows.Forms.SendKeys]::SendWait('^v')
                if ([bool]$request.press_enter) {
                    [System.Windows.Forms.SendKeys]::SendWait('{ENTER}')
                }
            } finally {
                try { [System.Windows.Forms.Clipboard]::Clear() } catch { }
            }
            $base.typed_characters = $text.Length
            $base.press_enter = [bool]$request.press_enter
            Write-WorkerResponse $base
            return
        }
        default {
            throw 'desktop_action_invalid'
        }
    }
}

function Invoke-Dispatch {
    $raw = [Console]::In.ReadToEnd()
    if ([string]::IsNullOrWhiteSpace($raw)) { throw 'desktop_request_required' }
    if ($raw.Length -gt 20000) { throw 'desktop_request_too_large' }
    try {
        $request = $raw | ConvertFrom-Json
    } catch {
        throw 'desktop_request_invalid_json'
    }
    $action = [string]$request.action
    if ($action -notin @('health','screenshot','click','type')) { throw 'desktop_action_invalid' }

    $interactiveUser = (Get-CimInstance Win32_ComputerSystem -ErrorAction Stop).UserName
    if ([string]::IsNullOrWhiteSpace($interactiveUser)) { throw 'interactive_user_missing' }

    New-Item -ItemType Directory -Force -Path $Root | Out-Null
    $id = [guid]::NewGuid().ToString('N')
    $requestFile = Join-Path $Root ($id + '.request.json')
    $responseFile = Join-Path $Root ($id + '.response.json')
    $screenshotFile = Join-Path $Root ($id + '.png')
    $taskName = $TaskPrefix + ' ' + $id

    try {
        [IO.File]::WriteAllText($requestFile, $raw, (New-Object Text.UTF8Encoding($false)))
        Set-PrivateFileAcl -Path $requestFile -UserName $interactiveUser

        $scriptPath = $MyInvocation.MyCommand.Path
        $workerArguments = @(
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy', 'Bypass',
            '-Sta',
            '-File', ('"' + $scriptPath + '"'),
            '-Mode', 'Worker',
            '-RequestPath', ('"' + $requestFile + '"'),
            '-ResponsePath', ('"' + $responseFile + '"'),
            '-ScreenshotPath', ('"' + $screenshotFile + '"')
        ) -join ' '
        $taskAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $workerArguments
        $principal = New-ScheduledTaskPrincipal -UserId $interactiveUser -LogonType Interactive -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
        Register-ScheduledTask -TaskName $taskName -Action $taskAction -Principal $principal -Settings $settings -Force | Out-Null
        Start-ScheduledTask -TaskName $taskName

        $deadline = (Get-Date).AddSeconds(25)
        while ((Get-Date) -lt $deadline) {
            if (Test-Path -LiteralPath $responseFile) { break }
            Start-Sleep -Milliseconds 100
        }
        if (-not (Test-Path -LiteralPath $responseFile)) { throw 'desktop_worker_timeout' }

        $response = Get-Content -LiteralPath $responseFile -Raw -Encoding UTF8 | ConvertFrom-Json
        $out = [ordered]@{}
        foreach ($prop in $response.PSObject.Properties) {
            $out[$prop.Name] = $prop.Value
        }
        $out.surface = 'windows_interactive'

        if ($action -eq 'screenshot') {
            if (-not (Test-Path -LiteralPath $screenshotFile)) { throw 'desktop_screenshot_missing' }
            $bytes = [IO.File]::ReadAllBytes($screenshotFile)
            if ($bytes.Length -lt 100 -or $bytes.Length -gt 12000000) { throw 'desktop_screenshot_size_invalid' }
            $out.mime_type = 'image/png'
            $out.bytes = $bytes.Length
            $out.image_b64 = [Convert]::ToBase64String($bytes)
        }
        Write-JsonResponse $out
    } finally {
        try { Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue } catch { }
        try { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue } catch { }
        Remove-Item -LiteralPath $requestFile, $responseFile, $screenshotFile -Force -ErrorAction SilentlyContinue
    }
}

try {
    if ($Mode -eq 'Worker') {
        Invoke-Worker
    } else {
        Invoke-Dispatch
    }
} catch {
    if ($Mode -eq 'Worker') {
        try { Write-WorkerResponse @{ ok = $false; error = 'desktop_worker_failed' } } catch { }
        exit 1
    }
    Write-JsonResponse @{ ok = $false; error = ([string]$_.Exception.Message) }
    exit 0
}
