<#
ShopVivaliz - Routine Abandonment Monitor
Deterministico, sem chamadas de IA (ver CLAUDE.md: "Nunca usar IA paga em cron/timer,
daemon, watcher, autorepair, polling ou loop recorrente").

Objetivo: detectar tarefas agendadas "ShopVivaliz*" que parecem ter sido criadas por um
agente para uma sondagem/uma tentativa pontual (trigger de horario unico, sem recorrencia)
e que continuam habilitadas muito tempo depois da ultima execucao -- o mesmo padrao das
tarefas de automacao de consentimento OAuth do Gmail encontradas e desativadas em 2026-09-02
(ver docs/ROUTINE-ABANDONMENT-MONITOR.md).

Nao apaga, nao desabilita e nao mata processo nenhum. Apenas registra e sinaliza.
#>
param(
    [int]$StaleHours = 4
)

$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$StatusDir = Join-Path $Repo '.agent-status'
$LogFile = Join-Path $LogDir 'routine-abandonment-monitor.log'
$StatusFile = Join-Path $StatusDir 'routine-abandonment-monitor.json'

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
New-Item -ItemType Directory -Force -Path $StatusDir | Out-Null

function Write-MonitorLog([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

# Rotinas 24h/watchdog conhecidas e documentadas -- nunca sinalizar como abandonadas.
# Ver docs/DESKTOP-COMMANDER-24H.md e docs/FRED-WIN-PRIVATE-RELAY.md.
$Allowlist = @(
    'ShopVivaliz Desktop Commander 24h'
    'ShopVivaliz Desktop Commander Task Guardian'
    'ShopVivaliz Fred-Win Relay 24h'
    'ShopVivaliz Amazon Returns Seller Central Bridge'
    'ShopVivaliz Auto Sync'
    'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'
)

$allTasks = Get-ScheduledTask | Where-Object { $_.TaskName -like 'ShopVivaliz*' }
$flagged = @()
$reviewed = @()

foreach ($task in $allTasks) {
    $info = Get-ScheduledTaskInfo -TaskName $task.TaskName -TaskPath $task.TaskPath -ErrorAction SilentlyContinue
    $entry = [ordered]@{
        task_name       = $task.TaskName
        state           = [string]$task.State
        hidden          = [bool]$task.Settings.Hidden
        last_run_time   = if ($info -and $info.LastRunTime) { $info.LastRunTime.ToString('o') } else { $null }
        next_run_time   = if ($info -and $info.NextRunTime) { $info.NextRunTime.ToString('o') } else { $null }
        last_result     = if ($info) { $info.LastTaskResult } else { $null }
        allowlisted     = $Allowlist -contains $task.TaskName
    }
    $reviewed += $entry

    if ($entry.allowlisted) { continue }
    if ($task.State -eq 'Disabled') { continue }

    $isStaleOneShot = $false
    if ($info -and $info.LastRunTime -and -not $info.NextRunTime) {
        $ageHours = (New-TimeSpan -Start $info.LastRunTime -End (Get-Date)).TotalHours
        if ($ageHours -ge $StaleHours) { $isStaleOneShot = $true }
    }

    if ($isStaleOneShot) {
        $flagged += [ordered]@{
            task_name     = $task.TaskName
            reason        = 'one_shot_trigger_still_enabled_after_last_run'
            last_run_time = $entry.last_run_time
            age_hours     = [math]::Round($ageHours, 1)
            hidden        = $entry.hidden
        }
    }
}

foreach ($f in $flagged) {
    Write-MonitorLog ("ABANDONED_CANDIDATE task='{0}' reason={1} last_run={2} age_hours={3}" -f $f.task_name, $f.reason, $f.last_run_time, $f.age_hours)
}
if ($flagged.Count -eq 0) {
    Write-MonitorLog ("OK reviewed={0} allowlisted={1} flagged=0" -f $reviewed.Count, ($Allowlist.Count))
}

$status = [ordered]@{
    generated_at    = (Get-Date).ToString('o')
    host            = $env:COMPUTERNAME
    ok              = ($flagged.Count -eq 0)
    status          = if ($flagged.Count -eq 0) { 'ok' } else { 'attention' }
    stale_threshold_hours = $StaleHours
    reviewed_count  = $reviewed.Count
    allowlist_count = $Allowlist.Count
    flagged         = $flagged
    reviewed        = $reviewed
}
$status | ConvertTo-Json -Depth 6 | Out-File -FilePath $StatusFile -Encoding utf8

if ($flagged.Count -gt 0) {
    Write-Output ("ATTENTION: {0} candidate(s) de rotina abandonada. Ver {1}" -f $flagged.Count, $StatusFile)
    exit 1
} else {
    Write-Output "OK: nenhuma rotina abandonada detectada."
    exit 0
}
