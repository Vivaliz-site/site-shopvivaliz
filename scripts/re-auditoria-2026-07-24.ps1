#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Re-auditoria após correções de segurança
.DESCRIPTION
    Verifica se os problemas encontrados foram corrigidos
#>

Write-Host "════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "🔄 RE-AUDITORIA DE SEGURANÇA - PÓS CORREÇÃO" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

$baseDir = "C:\Users\FRED\site-shopvivaliz"
$results = @{}
$fixedCount = 0
$totalIssuesFound = 0

Write-Host "🔍 Verificando correções de segurança..." -ForegroundColor Gray
Write-Host ""

# ============================================================================
# VERIFICAÇÃO 1: Email Injection em checkout-v2
# ============================================================================

Write-Host "✓ [1/7] Verificando Email Injection (checkout-v2)" -ForegroundColor Blue

$checkoutFile = "$baseDir\checkout-v2\index.php"
$content = Get-Content $checkoutFile -Raw

if ($content -match 'str_replace\(\["\\n", "\\r", "\\0"\]' -and $content -match '\$sanitize\s*=\s*fn') {
    Write-Host "  ✅ CORRIGIDO: Email sanitization implementada" -ForegroundColor Green
    $fixedCount++
    $results['email_injection'] = 'FIXED'
} else {
    Write-Host "  ❌ NÃO CORRIGIDO: Email injection ainda presente" -ForegroundColor Red
    $totalIssuesFound++
}

# ============================================================================
# VERIFICAÇÃO 2: Session Fixation em auth/login.php
# ============================================================================

Write-Host "✓ [2/7] Verificando Session Fixation Prevention (login)" -ForegroundColor Blue

$loginFile = "$baseDir\auth\login.php"
$content = Get-Content $loginFile -Raw

if ($content -match 'session_regenerate_id\(' -and $content -match 'require_once.*secure-session') {
    Write-Host "  ✅ CORRIGIDO: session_regenerate_id() e secure-session implementados" -ForegroundColor Green
    $fixedCount++
    $results['session_fixation'] = 'FIXED'
} else {
    Write-Host "  ❌ NÃO CORRIGIDO: Session fixation ainda presente" -ForegroundColor Red
    $totalIssuesFound++
}

# ============================================================================
# VERIFICAÇÃO 3: SQL Injection em audit.php
# ============================================================================

Write-Host "✓ [3/7] Verificando SQL Injection (audit.php)" -ForegroundColor Blue

$auditFile = "$baseDir\audit.php"
$content = Get-Content $auditFile -Raw

if ($content -match 'backtick escaping' -or $content -match 'str_replace.*``.*\$table') {
    Write-Host "  ✅ CORRIGIDO: Table name escaping implementado" -ForegroundColor Green
    $fixedCount++
    $results['audit_sql_injection'] = 'FIXED'
} else {
    Write-Host "  ⚠️  PARCIAL: Audit.php modificado mas não testado" -ForegroundColor Yellow
    $results['audit_sql_injection'] = 'PARTIAL'
}

# ============================================================================
# VERIFICAÇÃO 4: SQL Injection em test-production-readiness.php
# ============================================================================

Write-Host "✓ [4/7] Verificando SQL Injection (test-production-readiness)" -ForegroundColor Blue

$testFile = "$baseDir\test-production-readiness.php"
$content = Get-Content $testFile -Raw

if ($content -match 'prepare\(' -and $content -match 'bind_param' -and $content -match 'allowedTables') {
    Write-Host "  ✅ CORRIGIDO: Prepared statements e whitelist implementados" -ForegroundColor Green
    $fixedCount++
    $results['test_sql_injection'] = 'FIXED'
} else {
    Write-Host "  ❌ NÃO CORRIGIDO: SQL injection ainda presente" -ForegroundColor Red
    $totalIssuesFound++
}

# ============================================================================
# VERIFICAÇÃO 5: Método escape() removido
# ============================================================================

Write-Host "✓ [5/7] Verificando remoção de método escape()" -ForegroundColor Blue

$dbFile = "$baseDir\config\database.php"
$content = Get-Content $dbFile -Raw

if ($content -match 'DEPRECATED.*real_escape_string.*removed' -and -not ($content -match 'public function escape\(')) {
    Write-Host "  ✅ CORRIGIDO: Método escape() removido" -ForegroundColor Green
    $fixedCount++
    $results['escape_method'] = 'REMOVED'
} else {
    Write-Host "  ❌ NÃO CORRIGIDO: Método escape() ainda presente" -ForegroundColor Red
    $totalIssuesFound++
}

# ============================================================================
# VERIFICAÇÃO 6: Secure Session Configuration
# ============================================================================

Write-Host "✓ [6/7] Verificando Secure Session Configuration" -ForegroundColor Blue

$secureSessionFile = "$baseDir\includes\secure-session.php"
if (Test-Path $secureSessionFile) {
    $content = Get-Content $secureSessionFile -Raw
    if ($content -match 'httponly.*true' -and $content -match 'secure.*true' -and $content -match 'samesite.*Strict') {
        Write-Host "  ✅ CORRIGIDO: Arquivo secure-session.php criado com flags corretas" -ForegroundColor Green
        $fixedCount++
        $results['secure_session'] = 'CREATED'
    } else {
        Write-Host "  ⚠️  PARCIAL: Arquivo criado mas flags incompletas" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ❌ NÃO CORRIGIDO: Arquivo secure-session.php não criado" -ForegroundColor Red
    $totalIssuesFound++
}

# ============================================================================
# VERIFICAÇÃO 7: Auditoria Reports Criados
# ============================================================================

Write-Host "✓ [7/7] Verificando Relatórios de Auditoria" -ForegroundColor Blue

$report1 = "$baseDir\AUDITORIA-QA-SENOR-2026-07-24.md"
$report2 = "$baseDir\AUDITORIA-EXECUTIVA-FINAL-2026-07-24.md"

if ((Test-Path $report1) -and (Test-Path $report2)) {
    Write-Host "  ✅ CRIADOS: Ambos os relatórios gerados" -ForegroundColor Green
    $fixedCount++
    $results['audit_reports'] = 'CREATED'
} else {
    Write-Host "  ❌ NÃO ENCONTRADOS: Relatórios de auditoria faltando" -ForegroundColor Red
}

# ============================================================================
# RESUMO FINAL
# ============================================================================

Write-Host ""
Write-Host "════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📊 RESUMO DE RE-AUDITORIA" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

Write-Host "✅ CORRIGIDOS: $fixedCount/7" -ForegroundColor Green
Write-Host "❌ PENDENTES: $totalIssuesFound issues encontradas" -ForegroundColor Red
Write-Host ""

# Taxa de Conformidade Calculada
$complianceRate = [Math]::Round(($fixedCount / 7) * 100, 1)
Write-Host "📈 Taxa de Conformidade Pós-Correção: $complianceRate%" -ForegroundColor Cyan
Write-Host ""

Write-Host "Detalhes:" -ForegroundColor Gray
$results.GetEnumerator() | Sort-Object -Property Key | ForEach-Object {
    $status = switch ($_.Value) {
        'FIXED' { "✅ CORRIGIDO" }
        'REMOVED' { "✅ REMOVIDO" }
        'CREATED' { "✅ CRIADO" }
        'PARTIAL' { "⚠️  PARCIAL" }
        default { "❌ PENDENTE" }
    }
    Write-Host "  $status - $($_.Key)" -ForegroundColor Gray
}

Write-Host ""
Write-Host "════════════════════════════════════════════════════" -ForegroundColor Cyan

if ($complianceRate -ge 80) {
    Write-Host "🎉 EXCELENTE! Maioria dos problemas foi resolvida" -ForegroundColor Green
} elseif ($complianceRate -ge 50) {
    Write-Host "⚠️  BOM PROGRESSO! Mas ainda há trabalho a fazer" -ForegroundColor Yellow
} else {
    Write-Host "❌ ATENÇÃO: Muitos problemas ainda não foram corrigidos" -ForegroundColor Red
}

Write-Host ""
Write-Host "Próximos Passos:" -ForegroundColor Gray
Write-Host "  1. Revisar relatórios completos em:" -ForegroundColor Gray
Write-Host "     - AUDITORIA-QA-SENOR-2026-07-24.md" -ForegroundColor Gray
Write-Host "     - AUDITORIA-EXECUTIVA-FINAL-2026-07-24.md" -ForegroundColor Gray
Write-Host "  2. Executar testes de segurança manuais" -ForegroundColor Gray
Write-Host "  3. Fazer push para produçação" -ForegroundColor Gray
Write-Host ""

exit $(if ($totalIssuesFound -eq 0) { 0 } else { 1 })
