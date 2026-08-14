$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$result = [ordered]@{
    ok = $false
    stage = 'start'
    repo = $null
    base_sha = $null
    changed_files = @()
    php_lint = $false
    js_check = $false
    quality_passed = $false
    quality_tail = ''
    error = $null
}

$repo = $null
$worktree = $null
$transform = $null

function Emit-Result {
    param([hashtable]$Value)
    $json = $Value | ConvertTo-Json -Depth 8 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    Write-Output ('SV_AUDIT_VALIDATE_B64=' + [Convert]::ToBase64String($bytes))
}

try {
    $repo = @('C:\site-shopvivaliz', 'C:\Users\FRED\site-shopvivaliz') |
        Where-Object { Test-Path (Join-Path $_ '.git') } |
        Select-Object -First 1
    if (-not $repo) { throw 'Repositorio ShopVivaliz nao encontrado no Fred-Win.' }
    $result.repo = $repo

    $result.stage = 'fetch'
    & git -C $repo fetch origin main ops/fredwin-auditoria-bootstrap-20260814 --prune
    if ($LASTEXITCODE -ne 0) { throw 'git fetch falhou.' }
    $baseSha = (& git -C $repo rev-parse origin/main).Trim()
    $result.base_sha = $baseSha

    $worktree = Join-Path $env:TEMP ('shopvivaliz-auditoria-' + [Guid]::NewGuid().ToString('N'))
    & git -C $repo worktree add --detach $worktree origin/main
    if ($LASTEXITCODE -ne 0) { throw 'git worktree add falhou.' }

    Push-Location $worktree
    try {
        $result.stage = 'preflight'
        $task = 'auditoria-completa-20260814'
        $packetLines = & py -3 scripts/agent-docs-gate.py read --agent chatgpt --task $task
        if ($LASTEXITCODE -ne 0) { throw 'agent-docs-gate read falhou.' }
        $packet = ($packetLines -join "`n") | ConvertFrom-Json
        & py -3 scripts/agent-docs-gate.py acknowledge --agent chatgpt --task $task --digest $packet.digest | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'agent-docs-gate acknowledge falhou.' }
        & py -3 scripts/agent-docs-gate.py verify --agent chatgpt --task $task | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'agent-docs-gate verify falhou.' }

        $result.stage = 'reconstruct'
        $transform = Join-Path $env:TEMP ('sv-audit-transform-' + [Guid]::NewGuid().ToString('N') + '.py')
        $transformContent = & git -C $repo show 'origin/ops/fredwin-auditoria-bootstrap-20260814:scripts/tmp-auditoria-reconstruct-20260814.py'
        if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel ler transformador da branch bootstrap.' }
        [IO.File]::WriteAllText($transform, ($transformContent -join "`n"), (New-Object Text.UTF8Encoding($false)))
        & py -3 $transform $worktree | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'Reconstrucao da auditoria falhou.' }

        $changed = @(& git diff --name-only)
        $expected = @(
            '.htaccess',
            'admin/settings.php',
            'admin/settings-frete.php',
            'api/liz-general.php',
            'api/liz-intelligent-knowledge.php',
            'api/ml/webhook.php',
            'api/olist/webhook.php',
            'api/tiny/stock-webhook.php',
            'api/webhook-mercadopago.php',
            'includes/pdo-database.php',
            'includes/secure-session.php',
            'includes/site-settings.php',
            'includes/webhook-job-dispatcher.php',
            'includes/webhook-secret.php',
            'index.php',
            'js/admin-dashboard.js'
        ) | Sort-Object
        $actual = @($changed | Sort-Object)
        $result.changed_files = $actual
        if (($actual -join "`n") -ne ($expected -join "`n")) {
            throw ('Conjunto de arquivos divergente. Atual=' + ($actual -join ','))
        }

        $result.stage = 'php_lint'
        $phpFiles = @(
            'includes/pdo-database.php',
            'includes/site-settings.php',
            'includes/webhook-secret.php',
            'includes/webhook-job-dispatcher.php',
            'includes/secure-session.php',
            'index.php',
            'admin/settings.php',
            'admin/settings-frete.php',
            'api/webhook-mercadopago.php',
            'api/olist/webhook.php',
            'api/tiny/stock-webhook.php',
            'api/ml/webhook.php',
            'api/liz-general.php',
            'api/liz-intelligent-knowledge.php'
        )
        foreach ($file in $phpFiles) {
            & php -l $file | Out-Host
            if ($LASTEXITCODE -ne 0) { throw ('PHP lint falhou: ' + $file) }
        }
        $result.php_lint = $true

        $result.stage = 'js_check'
        if (Get-Command node -ErrorAction SilentlyContinue) {
            & node --check js/admin-dashboard.js | Out-Host
            if ($LASTEXITCODE -ne 0) { throw 'node --check falhou para js/admin-dashboard.js' }
        }
        $result.js_check = $true

        $result.stage = 'quality_gates'
        $qualityOutput = @(& php scripts\quality\run-all.php 2>&1)
        $qualityExit = $LASTEXITCODE
        $qualityText = ($qualityOutput -join "`n")
        $result.quality_tail = (($qualityOutput | Select-Object -Last 80) -join "`n")
        $result.quality_tail | Write-Host
        if ($qualityExit -ne 0 -or $qualityText -notmatch 'All ShopVivaliz quality checks passed') {
            throw ('Quality gates falharam, exit=' + $qualityExit)
        }
        $result.quality_passed = $true

        $result.stage = 'complete'
        $result.ok = $true
    } finally {
        Pop-Location
    }
} catch {
    $result.error = $_.Exception.Message
} finally {
    if ($transform -and (Test-Path $transform)) {
        Remove-Item $transform -Force -ErrorAction SilentlyContinue
    }
    if ($worktree -and (Test-Path $worktree) -and $repo) {
        & git -C $repo worktree remove --force $worktree 2>$null | Out-Null
    }
    Emit-Result $result
}

if (-not $result.ok) { exit 1 }
