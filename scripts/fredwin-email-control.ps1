param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('hard-stop-repair','graph-browser-auth','graph-template-test','audit-9950','start-authorized-send','status')]
    [string]$Mode
)

$ErrorActionPreference = 'Stop'
$Repo = 'C:\mei-mg-email'
$SiteRepo = 'C:\site-shopvivaliz'
$Python = Join-Path $Repo '.venv\Scripts\python.exe'
$LogDir = Join-Path $Repo 'logs'
$SmtpGuard = Join-Path $SiteRepo 'scripts\fredwin-email-smtp-guard.ps1'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Write-Step([string]$Message) {
    Write-Output ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
}

function Sync-EmailRepo {
    Set-Location $Repo
    git fetch origin
    git reset --hard origin/main
    if ($LASTEXITCODE -ne 0) { throw 'git sync failed' }
}

function Invoke-SmtpGuard {
    if (Test-Path $SmtpGuard) {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $SmtpGuard
        if ($LASTEXITCODE -ne 0) { throw 'SMTP guard failed' }
    }
}

function Stop-LegacyEmailProcesses {
    Write-Step 'Stopping legacy mei-mg-email/Gmail/SMTP processes'
    $targets = Get-CimInstance Win32_Process | Where-Object {
        $cmd = [string]$_.CommandLine
        if ([string]::IsNullOrWhiteSpace($cmd)) { return $false }
        (($cmd -match 'mei-mg-email') -and ($cmd -match '(?i)gmail|smtp|worker|disparar|enviar')) -or
        ($cmd -match '(?i)enviar_teste_gmail|gmail.*smtp|smtp\.gmail\.com|Teste Gmail SMTP')
    }
    foreach ($p in $targets) {
        if ($p.ProcessId -eq $PID) { continue }
        Write-Output ("stopping_pid={0};name={1}" -f $p.ProcessId,$p.Name)
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }

    $tasks = Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object {
        $joined = (($_.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' ')
        $joined -match '(?i)mei-mg-email.*(gmail|smtp)|enviar_teste_gmail|gmail.*smtp|smtp\.gmail\.com|Teste Gmail SMTP'
    }
    foreach ($t in $tasks) {
        Write-Output ("removing_task=" + $t.TaskName)
        Stop-ScheduledTask -TaskName $t.TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $t.TaskName -Confirm:$false -ErrorAction SilentlyContinue
    }
}

function Sanitize-Env {
    $envPath = Join-Path $Repo '.env'
    if (!(Test-Path $envPath)) { throw '.env not found' }
    $lines = Get-Content $envPath
    $clean = $lines | Where-Object {
        $_ -notmatch '^\s*(GMAIL_|SMTP_|MICROSOFT_SMTP_)' -and
        $_ -notmatch '^\s*EMAIL_PROVIDER\s*=' -and
        $_ -notmatch '^\s*MICROSOFT_GRAPH_USER\s*=' -and
        $_ -notmatch '^\s*MAIL_FROM\s*=' -and
        $_ -notmatch '^\s*MAIL_FROM_NAME\s*='
    }
    $clean += 'EMAIL_PROVIDER=microsoft_graph'
    $clean += 'MICROSOFT_GRAPH_USER=naoresponda@dev.shopvivaliz.com.br'
    $clean += 'MAIL_FROM=Contabilidade Melo <naoresponda@dev.shopvivaliz.com.br>'
    $clean += 'MAIL_FROM_NAME=Contabilidade Melo'
    Set-Content -Path $envPath -Value $clean -Encoding UTF8
    $remaining = (Get-Content $envPath | Where-Object { $_ -match '^\s*(GMAIL_|SMTP_|MICROSOFT_SMTP_)' }).Count
    Write-Output ("smtp_keys_remaining=" + $remaining)
    if ($remaining -ne 0) { throw 'SMTP keys remain in .env' }
}

function Ensure-Database {
    Set-Location $Repo
    docker compose up -d db
    if ($LASTEXITCODE -ne 0) { throw 'database start failed' }
    Start-Sleep -Seconds 5
    docker compose run --rm flyway -connectRetries=30 migrate
    if ($LASTEXITCODE -ne 0) {
        docker compose logs --no-color flyway
        throw 'Flyway migrate failed'
    }
    $column = docker exec mei-mg-email-db psql -U postgres -d mei_mg_email -Atc "select exists(select 1 from information_schema.columns where table_schema='mei_email' and table_name='empresas' and column_name='marketing_autorizado')"
    Write-Output ('marketing_autorizado_column=' + $column)
    if (($column | Out-String).Trim() -ne 't') { throw 'database schema verification failed' }
}

function Run-Tests {
    Set-Location $Repo
    & $Python -m pytest -q --disable-warnings --maxfail=1
    if ($LASTEXITCODE -ne 0) { throw 'pytest failed' }
}

function Start-InteractiveGraphAuth {
    Sync-EmailRepo
    Invoke-SmtpGuard
    Sanitize-Env
    $task = 'ShopVivalizGraphBrowserAuth'
    $out = Join-Path $LogDir 'graph-browser-auth.out.log'
    $err = Join-Path $LogDir 'graph-browser-auth.err.log'
    Remove-Item $out,$err -Force -ErrorAction SilentlyContinue
    $user = (Get-CimInstance Win32_ComputerSystem).UserName
    if ([string]::IsNullOrWhiteSpace($user)) { throw 'No interactive Windows user is logged in' }
    $args = "-NoProfile -ExecutionPolicy Bypass -Command `"Set-Location '$Repo'; & '$Python' '.\scripts\autorizar_microsoft_graph_browser.py' 1>>'$out' 2>>'$err'`""
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $args
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Principal $principal -Force | Out-Null
    Start-ScheduledTask -TaskName $task
    Write-Output ('graph_auth_task_started_for=' + $user)
    Write-Output ('graph_auth_out_log=' + $out)
    Write-Output 'GRAPH_BROWSER_AUTH_LAUNCHED'
}

function Run-GraphTemplateTest {
    Sync-EmailRepo
    Invoke-SmtpGuard
    Sanitize-Env
    & $Python '.\scripts\auditar_graph_token.py'
    if ($LASTEXITCODE -ne 0) { throw 'Graph token audit failed' }
    & $Python '.\scripts\enviar_teste_microsoft_graph.py'
    if ($LASTEXITCODE -ne 0) { throw 'Graph HTML template test failed' }
}

function Run-Audit9950 {
    Sync-EmailRepo
    Invoke-SmtpGuard
    Sanitize-Env
    Ensure-Database
    & $Python '.\scripts\auditar_exchange_10000.py'
    if ($LASTEXITCODE -ne 0) { throw '9950 readiness audit failed' }
}

function Start-AuthorizedSend {
    Run-Audit9950
    & $Python '.\scripts\disparar_10000_mei_mg.py'
    if ($LASTEXITCODE -ne 0) { throw 'campaign capacity controller failed' }
}

switch ($Mode) {
    'hard-stop-repair' {
        Invoke-SmtpGuard
        Stop-LegacyEmailProcesses
        Sync-EmailRepo
        Sanitize-Env
        Ensure-Database
        Run-Tests
        Write-Output 'HARD_STOP_REPAIR_READY'
    }
    'graph-browser-auth' { Start-InteractiveGraphAuth }
    'graph-template-test' { Run-GraphTemplateTest }
    'audit-9950' { Run-Audit9950 }
    'start-authorized-send' { Start-AuthorizedSend }
    'status' {
        Write-Step 'Current email runtime status'
        Get-CimInstance Win32_Process | Where-Object { ([string]$_.CommandLine) -match '(?i)mei-mg-email|gmail.*smtp|smtp\.gmail\.com|Teste Gmail SMTP' } | Select-Object ProcessId,Name,CommandLine
        Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object { (($_.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' ') -match '(?i)mei-mg-email|gmail.*smtp|smtp\.gmail\.com|Teste Gmail SMTP' } | Select-Object TaskName,State
        Set-Location $Repo
        docker compose ps
    }
}