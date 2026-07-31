#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Backup automático diário do repositório ShopVivaliz
.DESCRIPTION
    Cria backup completo do repositório, armazena em local seguro,
    e rotaciona backups antigos (mantém mínimo 10 dias)
.PARAMETER BackupDir
    Diretório onde armazenar backups (padrão: C:\backups\site-shopvivaliz)
.PARAMETER RetentionDays
    Mínimo de dias para manter backups (padrão: 10)
.EXAMPLE
    .\backup-daily.ps1
    .\backup-daily.ps1 -BackupDir "E:\backups" -RetentionDays 14
#>

param(
    [string]$BackupDir = "C:\backups\site-shopvivaliz",
    [int]$RetentionDays = 10
)

$ErrorActionPreference = "Stop"
$WarningPreference = "Continue"

# ================================================================================
# CONFIGURAÇÃO
# ================================================================================

$RepoPath = "C:\Users\FRED\site-shopvivaliz"
$LogDir = "$BackupDir\logs"
$Timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$Date = Get-Date -Format "yyyy-MM-dd"
$BackupFile = "$BackupDir\site-shopvivaliz-$Date.7z"
$LogFile = "$LogDir\backup-$Timestamp.log"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "🔄 BACKUP AUTOMÁTICO DIÁRIO" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# ================================================================================
# VALIDAÇÕES
# ================================================================================

# Validar se repositório existe
if (-not (Test-Path $RepoPath)) {
    $msg = "❌ ERRO: Repositório não encontrado em $RepoPath"
    Write-Host $msg -ForegroundColor Red
    exit 1
}

Write-Host "✅ Repositório encontrado: $RepoPath" -ForegroundColor Green

# Criar diretório de backup se não existir
if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
    Write-Host "✅ Diretório criado: $BackupDir" -ForegroundColor Green
}

# Criar diretório de logs
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

# ================================================================================
# EXECUTAR BACKUP
# ================================================================================

Write-Host ""
Write-Host "📦 CRIANDO BACKUP..." -ForegroundColor Yellow
Write-Host "  Fonte: $RepoPath" -ForegroundColor Gray
Write-Host "  Destino: $BackupFile" -ForegroundColor Gray
Write-Host "  Timestamp: $Timestamp" -ForegroundColor Gray
Write-Host ""

$startTime = Get-Date

try {
    # Verificar se 7-Zip está instalado
    $7zip = Get-Command 7z -ErrorAction SilentlyContinue

    if ($7zip) {
        # Usar 7-Zip (melhor compressão)
        Write-Host "📊 Compressor: 7-Zip" -ForegroundColor Gray

        # Excluir .git, .vscode, node_modules
        & 7z a -t7z "$BackupFile" "$RepoPath" `
            -x!"$RepoPath\.git\*" `
            -x!"$RepoPath\.vscode\*" `
            -x!"$RepoPath\node_modules\*" `
            -x!"$RepoPath\.claude\*" `
            -x!"$RepoPath\logs\*" `
            -x!"$RepoPath\*.log" `
            -mx=9 `
            -v0 | Out-Null

        if ($LASTEXITCODE -eq 0) {
            Write-Host "✅ Arquivo criado com sucesso" -ForegroundColor Green
        } else {
            throw "7z retornou código de saída: $LASTEXITCODE"
        }
    } else {
        # Fallback: Usar Compress-Archive (mais lento, menos compressão)
        Write-Host "📊 Compressor: Windows built-in (Compress-Archive)" -ForegroundColor Gray
        Write-Host "⚠️  7-Zip não encontrado, usando compressão padrão" -ForegroundColor Yellow

        $zipFile = $BackupFile -replace '\.7z$', '.zip'
        Compress-Archive -Path $RepoPath -DestinationPath $zipFile -Force -ErrorAction Stop
        $BackupFile = $zipFile

        Write-Host "✅ Arquivo criado com sucesso" -ForegroundColor Green
    }
} catch {
    $msg = "❌ ERRO ao criar backup: $_"
    Write-Host $msg -ForegroundColor Red
    Add-Content -Path $LogFile -Value "[ERROR] $Timestamp - $msg"
    exit 1
}

# ================================================================================
# VALIDAR BACKUP
# ================================================================================

Write-Host ""
Write-Host "✔️ VALIDANDO BACKUP..." -ForegroundColor Yellow

if (Test-Path $BackupFile) {
    $fileSize = (Get-Item $BackupFile).Length / 1MB
    Write-Host "✅ Arquivo existe" -ForegroundColor Green
    Write-Host "  Tamanho: $([Math]::Round($fileSize, 2)) MB" -ForegroundColor Gray

    if ($fileSize -lt 1) {
        Write-Host "⚠️  AVISO: Arquivo muito pequeno (< 1 MB)" -ForegroundColor Yellow
    }
} else {
    Write-Host "❌ ERRO: Arquivo de backup não foi criado" -ForegroundColor Red
    exit 1
}

# ================================================================================
# ROTAÇÃO DE BACKUPS (MANTER 10+ DIAS)
# ================================================================================

Write-Host ""
Write-Host "🔄 ROTACIONANDO BACKUPS..." -ForegroundColor Yellow

$backupFiles = @()
Get-ChildItem -Path $BackupDir -Filter "site-shopvivaliz-*" -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '\.(7z|zip)$' } | ForEach-Object {
    $backupFiles += $_
}

Write-Host "  Backups encontrados: $($backupFiles.Count)" -ForegroundColor Gray

if ($backupFiles.Count -gt 0) {
    $backupFiles = $backupFiles | Sort-Object LastWriteTime -Descending

    $cutoffDate = (Get-Date).AddDays(-$RetentionDays)
    $filesToDelete = @()

    foreach ($file in $backupFiles) {
        if ($file.LastWriteTime -lt $cutoffDate) {
            $filesToDelete += $file
        }
    }

    if ($filesToDelete.Count -gt 0) {
        Write-Host "  Deletando $($filesToDelete.Count) backup(s) antigo(s)..." -ForegroundColor Gray
        foreach ($file in $filesToDelete) {
            $deletedSize = $file.Length / 1MB
            Remove-Item -Path $file.FullName -Force -ErrorAction SilentlyContinue
            Write-Host "    ✓ Deletado: $($file.Name) ($([Math]::Round($deletedSize, 2)) MB)" -ForegroundColor Gray
        }
    } else {
        Write-Host "  ✅ Todos os backups estão dentro do período de retenção" -ForegroundColor Green
    }

    # Mostrar backups mantidos
    Write-Host ""
    Write-Host "📦 BACKUPS MANTIDOS (últimos $RetentionDays dias):" -ForegroundColor Cyan
    $keptBackups = Get-ChildItem -Path $BackupDir -Filter "site-shopvivaliz-*" -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '\.(7z|zip)$' } | Sort-Object LastWriteTime -Descending
    foreach ($backup in $keptBackups) {
        $size = $backup.Length / 1MB
        $age = (New-TimeSpan -Start $backup.LastWriteTime -End (Get-Date)).Days
        Write-Host "  📄 $($backup.Name) - $([Math]::Round($size, 2)) MB - $age dias atrás" -ForegroundColor Gray
    }
}

# ================================================================================
# REGISTRAR OPERAÇÃO
# ================================================================================

$endTime = Get-Date
$duration = (New-TimeSpan -Start $startTime -End $endTime).TotalSeconds

$summary = @"
════════════════════════════════════════════════════════
✅ BACKUP COMPLETADO COM SUCESSO
════════════════════════════════════════════════════════
Data: $Timestamp
Arquivo: $BackupFile
Tamanho: $([Math]::Round($fileSize, 2)) MB
Duração: $([Math]::Round($duration, 2)) segundos
Retenção: $RetentionDays dias mínimo
Status: COMPROVADO ✅
════════════════════════════════════════════════════════
"@

Write-Host $summary -ForegroundColor Green

# Salvar log
Add-Content -Path $LogFile -Value $summary
Write-Host ""
Write-Host "📝 Log: $LogFile" -ForegroundColor Gray

# ================================================================================
# SAÍDA
# ================================================================================

exit 0
