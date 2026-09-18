param(
    [string]$Path = (Join-Path $env:APPDATA 'AuditoriaPostRestart.ps1')
)

$ErrorActionPreference = 'Stop'

function Test-PowerShellSyntax {
    param([string]$Text)
    $tokens = $null
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseInput(
        $Text,
        [ref]$tokens,
        [ref]$errors
    )
    return @($errors)
}

if (-not (Test-Path -LiteralPath $Path)) {
    Write-Output 'AUDITORIA_POST_RESTART_TARGET_MISSING=true'
    exit 0
}

$original = [System.IO.File]::ReadAllText($Path)
$working = $original
$removed = New-Object System.Collections.Generic.List[string]

for ($attempt = 0; $attempt -lt 5; $attempt++) {
    $errors = @(Test-PowerShellSyntax -Text $working)
    if ($errors.Count -eq 0) { break }

    $candidate = $errors |
        Where-Object {
            $_.Extent.Text -eq '}' -and
            $_.ErrorId -match 'UnexpectedToken'
        } |
        Sort-Object { $_.Extent.StartOffset } |
        Select-Object -First 1

    if (-not $candidate) { break }

    $start = [int]$candidate.Extent.StartOffset
    $length = [int]($candidate.Extent.EndOffset - $candidate.Extent.StartOffset)
    if ($length -ne 1 -or $start -lt 0 -or $start -ge $working.Length) { break }

    [void]$removed.Add(('{0}:{1}' -f $candidate.Extent.StartLineNumber, $candidate.Extent.StartColumnNumber))
    $working = $working.Remove($start, $length)
}

$finalErrors = @(Test-PowerShellSyntax -Text $working)
if ($finalErrors.Count -gt 0) {
    Write-Output ('AUDITORIA_POST_RESTART_REPAIRED=false parser_errors=' + $finalErrors.Count)
    exit 3
}

if ($working -eq $original) {
    Write-Output 'AUDITORIA_POST_RESTART_ALREADY_VALID=true'
    exit 0
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = $Path + '.bak-' + $stamp
Copy-Item -LiteralPath $Path -Destination $backup -Force

$utf8Bom = New-Object System.Text.UTF8Encoding($true)
[System.IO.File]::WriteAllText($Path, $working, $utf8Bom)

$verify = [System.IO.File]::ReadAllText($Path)
$verifyErrors = @(Test-PowerShellSyntax -Text $verify)
if ($verifyErrors.Count -gt 0) {
    Copy-Item -LiteralPath $backup -Destination $Path -Force
    Write-Output 'AUDITORIA_POST_RESTART_REPAIRED=false verification_failed=true'
    exit 4
}

Write-Output ('AUDITORIA_POST_RESTART_REPAIRED=true removed_unexpected_closing_braces=' + $removed.Count)
Write-Output ('AUDITORIA_POST_RESTART_LOCATIONS=' + ($removed -join ','))
