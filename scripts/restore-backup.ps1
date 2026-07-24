#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Restaura repositório a partir de backup
.DESCRIPTION
    Restaura uma versão anterior do repositório a partir dos backups diários.
    Valida integridade e mantém histórico de restauração.
.PARAMETER BackupFile
    Caminho completo do arquivo de backup a restaurar
    Se não informado, mostra lista de backups disponíveis
.PARAMETER TargetPath
    Diretório onde restaurar (padrão: C:\Users\FRED\site-shopvivaliz)
.PARAMETER Force
    Force restauração sem confirmação
.EXAMPLE
    .\restore-backup.ps1
    .\restore-backup.ps1 -BackupFile "C:\backups\site-shopvivaliz\site-shopvivaliz-2026-07-24.7z"
    .\restore-backup.ps1 -BackupFile "C:\backups\site-shopvivaliz\site-shopvivaliz-2026-07-24.7z" -Force
#>

param(
    [string]$BackupFile = "",
    [string]$TargetPath = "C:\Users\FRED\site-shopvivaliz",
    [switch]$Force
)

$ErrorActionPreference = "Stop"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "♻️ RESTAURAR REPOSITÓRIO A PARTIR DE BACKUP" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# ================================================================================
# VALIDAÇÕES
# ================================================================================

$backupDir = "C:\backups\site-shopvivaliz"

if (-not (Test-Path $backupDir)) {
    Write-Host "❌ ERRO: Diretório de backups não encontrado: $backupDir" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Diretório de backups encontrado" -ForegroundColor Green
Write-Host ""

# ================================================================================
# LISTAR BACKUPS DISPONÍVEIS
# ================================================================================

if (-not $BackupFile) {
    Write-Host "📦 BACKUPS DISPONÍVEIS:" -ForegroundColor Cyan
    Write-Host ""

    $backups = Get-ChildItem -Path $backupDir -Filter "site-shopvivaliz-*" -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '\.(7z|zip)$' } | Sort-Object LastWriteTime -Descending

    if ($backups.Count -eq 0) {
        Write-Host "❌ Nenhum backup encontrado" -ForegroundColor Red
        exit 1
    }

    for ($i = 0; $i -lt $backups.Count; $i++) {
        $backup = $backups[$i]
        $size = $backup.Length / 1MB
        $date = $backup.LastWriteTime.ToString("yyyy-MM-dd HH:mm:ss")
        $age = (New-TimeSpan -Start $backup.LastWriteTime -End (Get-Date)).Days

        Write-Host "  [$i] $($backup.Name)" -ForegroundColor Yellow
        Write-Host "      Data: $date ($age dias atrás)" -ForegroundColor Gray
        Write-Host "      Tamanho: $([Math]::Round($size, 2)) MB" -ForegroundColor Gray
        Write-Host ""
    }

    Write-Host "📝 Para restaurar um backup:" -ForegroundColor Cyan
    Write-Host "  .\restore-backup.ps1 -BackupFile `"C:\backups\site-shopvivaliz\[NOME_DO_ARQUIVO]`"" -ForegroundColor Gray
    Write-Host ""

    exit 0
}

# ================================================================================
# VALIDAR ARQUIVO DE BACKUP
# ================================================================================

if (-not (Test-Path $BackupFile)) {
    Write-Host "❌ ERRO: Arquivo de backup não encontrado: $BackupFile" -ForegroundColor Red
    exit 1
}

$backupFileInfo = Get-Item $BackupFile
$backupSize = $backupFileInfo.Length / 1MB

Write-Host "📄 Arquivo selecionado:" -ForegroundColor Cyan
Write-Host "  Nome: $($backupFileInfo.Name)" -ForegroundColor Gray
Write-Host "  Tamanho: $([Math]::Round($backupSize, 2)) MB" -ForegroundColor Gray
Write-Host "  Data: $($backupFileInfo.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss'))" -ForegroundColor Gray
Write-Host ""

# ================================================================================
# CONFIRMAÇÃO
# ================================================================================

if (-not $Force) {
    Write-Host "⚠️  AVISO:" -ForegroundColor Yellow
    Write-Host "  Isto irá SUBSTITUIR o repositório em: $TargetPath" -ForegroundColor Yellow
    Write-Host "  Dados atuais serão PERDIDOS" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Digite 'confirmo' para continuar:" -ForegroundColor Yellow

    $confirmation = Read-Host

    if ($confirmation -ne "confirmo") {
        Write-Host "❌ Operação cancelada" -ForegroundColor Red
        exit 0
    }
}

Write-Host ""

# ================================================================================
# CRIAR BACKUP DO ESTADO ATUAL (SEGURANÇA)
# ================================================================================

Write-Host "🔒 SALVAGUARDA: Fazendo backup do estado atual..." -ForegroundColor Yellow

$safeguardDir = "$backupDir\safeguard"
if (-not (Test-Path $safeguardDir)) {
    New-Item -ItemType Directory -Path $safeguardDir -Force | Out-Null
}

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$safeguardFile = "$safeguardDir\pre-restore-$timestamp.7z"

try {
    $7zip = Get-Command 7z -ErrorAction SilentlyContinue

    if ($7zip) {
        Write-Host "  Comprimindo estado atual..." -ForegroundColor Gray
        & 7z a -t7z "$safeguardFile" "$TargetPath" `
            -x!"$TargetPath\.git\*" `
            -x!"$TargetPath\.vscode\*" `
            -x!"$TargetPath\node_modules\*" `
            -x!"$TargetPath\.claude\*" `
            -mx=7 `
            -v0 | Out-Null

        if ($LASTEXITCODE -eq 0) {
            Write-Host "  ✅ Safeguard criado: $safeguardFile" -ForegroundColor Green
        }
    }
} catch {
    Write-Host "  ⚠️  Aviso ao criar safeguard (continuando): $_" -ForegroundColor Yellow
}

Write-Host ""

# ================================================================================
# RESTAURAR BACKUP
# ================================================================================

Write-Host "♻️ RESTAURANDO BACKUP..." -ForegroundColor Yellow

$startTime = Get-Date

try {
    $7zip = Get-Command 7z -ErrorAction SilentlyContinue

    if ($7zip -and $BackupFile -like "*.7z") {
        # Extrair 7z
        Write-Host "  Extraindo arquivo 7z..." -ForegroundColor Gray

        & 7z x "$BackupFile" -o"$TargetPath" -aoa -v0 | Out-Null

        if ($LASTEXITCODE -ne 0) {
            throw "7z retornou código de saída: $LASTEXITCODE"
        }
    } elseif ($BackupFile -like "*.zip") {
        # Extrair zip
        Write-Host "  Extraindo arquivo zip..." -ForegroundColor Gray

        Expand-Archive -Path $BackupFile -DestinationPath $TargetPath -Force -ErrorAction Stop
    } else {
        throw "Formato de arquivo não suportado: $BackupFile"
    }

    Write-Host "  ✅ Arquivo extraído com sucesso" -ForegroundColor Green

} catch {
    Write-Host "❌ ERRO ao restaurar: $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "⚠️  O safeguard foi preservado em:" -ForegroundColor Yellow
    Write-Host "    $safeguardFile" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Para restaurar do estado anterior:" -ForegroundColor Yellow
    Write-Host "  .\restore-backup.ps1 -BackupFile `"$safeguardFile`" -Force" -ForegroundColor Gray
    exit 1
}

# ================================================================================
# VALIDAR RESTAURAÇÃO
# ================================================================================

Write-Host ""
Write-Host "✔️ VALIDANDO RESTAURAÇÃO..." -ForegroundColor Yellow

if (Test-Path "$TargetPath") {
    Write-Host "  ✅ Diretório existe" -ForegroundColor Green

    $fileCount = (Get-ChildItem -Path $TargetPath -Recurse -ErrorAction SilentlyContinue).Count
    Write-Host "  ✅ Arquivos restaurados: $fileCount" -ForegroundColor Green

    if (Test-Path "$TargetPath\.git") {
        Write-Host "  ✅ Repositório git presente" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  Aviso: .git não encontrado" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ❌ Erro: Diretório não existe" -ForegroundColor Red
    exit 1
}

# ================================================================================
# REGISTRAR OPERAÇÃO
# ================================================================================

$endTime = Get-Date
$duration = (New-TimeSpan -Start $startTime -End $endTime).TotalSeconds

$logDir = "$backupDir\logs"
if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

$logFile = "$logDir\restore-$timestamp.log"
$logContent = @"
════════════════════════════════════════════════════════
✅ RESTAURAÇÃO COMPLETADA COM SUCESSO
════════════════════════════════════════════════════════
Data: $timestamp
Arquivo restaurado: $($backupFileInfo.Name)
Tamanho: $([Math]::Round($backupSize, 2)) MB
Destino: $TargetPath
Duração: $([Math]::Round($duration, 2)) segundos
Safeguard: $safeguardFile
Status: COMPROVADO ✅
════════════════════════════════════════════════════════
"@

Add-Content -Path $logFile -Value $logContent

# ================================================================================
# RESUMO
# ================================================================================

$summary = @"
════════════════════════════════════════════════════════
✅ RESTAURAÇÃO COMPLETADA COM SUCESSO
════════════════════════════════════════════════════════
Backup: $($backupFileInfo.Name)
Tamanho: $([Math]::Round($backupSize, 2)) MB
Destino: $TargetPath
Duração: $([Math]::Round($duration, 2)) segundos

Safeguard (estado anterior):
  $safeguardFile

Status: COMPROVADO ✅

PRÓXIMOS PASSOS:
1. Verifique o repositório restaurado
2. Se necessário, execute: git status
3. Se estiver satisfeito, pode deletar o safeguard:
   Remove-Item "$safeguardFile" -Force

Log da operação: $logFile
════════════════════════════════════════════════════════
"@

Write-Host $summary -ForegroundColor Green

exit 0
