#!/usr/bin/env pwsh
<#
.SYNOPSIS
Sincroniza commits da branch main com origin/main sem criar commits ou esconder conflitos.
#>
param(
    [int]$IntervalMinutes = 30,
    [switch]$OneTime,
    [string]$RepositoryPath = ""
)

$ErrorActionPreference = "Stop"
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($RepositoryPath)) { $RepositoryPath = Split-Path -Parent $scriptRoot }
$repo = (Resolve-Path -LiteralPath $RepositoryPath).Path
$logsDir = Join-Path $repo "logs"
New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
$logFile = Join-Path $logsDir "local-sync-$(Get-Date -Format 'yyyy-MM-dd').log"

function Write-SyncLog {
    param([string]$Message, [string]$Level = "INFO")
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] [$Level] $Message"
    Write-Host $line
    Add-Content -LiteralPath $logFile -Value $line -Encoding UTF8
}

function Invoke-Git {
    param([string[]]$Arguments)
    $output = @(& git -C $repo @Arguments 2>&1)
    $exitCode = $LASTEXITCODE
    foreach ($line in $output) { Write-SyncLog "git: $line" }
    [pscustomobject]@{ ExitCode = $exitCode; Output = $output }
}

function Git-Text {
    param([string[]]$Arguments)
    $result = Invoke-Git -Arguments $Arguments
    if ($result.ExitCode -ne 0) { throw "git $($Arguments -join ' ') falhou com codigo $($result.ExitCode)" }
    (($result.Output -join "`n").Trim())
}

function Invoke-SyncOnce {
    $lockStream = $null
    try {
        $gitDir = Git-Text -Arguments @("rev-parse", "--git-dir")
        if (-not [IO.Path]::IsPathRooted($gitDir)) { $gitDir = Join-Path $repo $gitDir }
        $lockPath = Join-Path $gitDir "shopvivaliz-agent-edit.lock"
        $lockStream = [IO.File]::Open($lockPath, "OpenOrCreate", "ReadWrite", "ReadWrite")
        try { $lockStream.Lock(0, 1) } catch {
            Write-SyncLog "Sincronizacao adiada: outro agente esta editando o repositorio." "WARN"
            return 5
        }

        $branch = Git-Text -Arguments @("branch", "--show-current")
        if ($branch -ne "main") {
            Write-SyncLog "Bloqueado: branch atual '$branch'; esperado 'main'." "ERROR"
            return 2
        }

        $fetch = Invoke-Git -Arguments @("fetch", "--prune", "origin", "main")
        if ($fetch.ExitCode -ne 0) { throw "git fetch falhou" }

        $status = Git-Text -Arguments @(
            "status", "--porcelain", "--untracked-files=normal", "--", ".", ":(exclude)logs/**"
        )
        if ($status) {
            Write-SyncLog "Bloqueado: existem alteracoes locais; nenhum arquivo foi commitado automaticamente." "ERROR"
            foreach ($line in ($status -split "`n")) { Write-SyncLog $line "ERROR" }
            return 3
        }

        $counts = Git-Text -Arguments @("rev-list", "--left-right", "--count", "HEAD...origin/main")
        $parts = $counts -split "\s+"
        $ahead = [int]$parts[0]
        $behind = [int]$parts[1]
        Write-SyncLog "Estado Git: ahead=$ahead behind=$behind"

        if ($ahead -gt 0 -and $behind -gt 0) {
            Write-SyncLog "Bloqueado: historico divergiu; exige revisao/rebase manual." "ERROR"
            return 4
        }
        if ($behind -gt 0) {
            $pull = Invoke-Git -Arguments @("pull", "--ff-only", "origin", "main")
            if ($pull.ExitCode -ne 0) { throw "git pull --ff-only falhou" }
            Write-SyncLog "Fast-forward concluido com sucesso." "SUCCESS"
        } elseif ($ahead -gt 0) {
            $push = Invoke-Git -Arguments @("push", "origin", "main")
            if ($push.ExitCode -ne 0) { throw "git push falhou" }
            Write-SyncLog "Push concluido com sucesso." "SUCCESS"
        } else {
            Write-SyncLog "Repositorio ja sincronizado." "SUCCESS"
        }
        return 0
    } catch {
        Write-SyncLog "Falha: $($_.Exception.Message)" "ERROR"
        return 1
    } finally {
        if ($null -ne $lockStream) {
            try { $lockStream.Unlock(0, 1) } catch {}
            $lockStream.Dispose()
        }
    }
}

do {
    $code = Invoke-SyncOnce
    if ($OneTime) { exit $code }
    Write-SyncLog "Proximo ciclo em $IntervalMinutes minuto(s)."
    Start-Sleep -Seconds ([Math]::Max(1, $IntervalMinutes * 60))
} while ($true)
