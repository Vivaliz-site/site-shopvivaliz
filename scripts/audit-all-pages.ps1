#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Auditoria completa de todas as páginas e produtos do site
.DESCRIPTION
    Testa status HTTP de todas as rotas conhecidas e todos os produtos
.PARAMETER BaseUrl
    URL base do site (padrão: https://dev.shopvivaliz.com.br)
.PARAMETER Timeout
    Timeout em segundos (padrão: 10)
.EXAMPLE
    .\audit-all-pages.ps1
    .\audit-all-pages.ps1 -BaseUrl "https://shopvivaliz.com.br" -Timeout 15
#>

param(
    [string]$BaseUrl = "https://dev.shopvivaliz.com.br",
    [int]$Timeout = 10
)

$ErrorActionPreference = "Continue"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "🔍 AUDITORIA COMPLETA DE PÁGINAS E PRODUTOS" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$reportFile = "site-audit-$timestamp.md"

# ================================================================================
# PÁGINAS A TESTAR
# ================================================================================

$pages = @(
    # Páginas principais
    "/",
    "/home",
    "/catalogo",
    "/produtos",
    "/sobre",
    "/contato",
    "/faq",
    "/blog",
    "/carrinho",
    "/checkout",

    # Páginas legais
    "/termos",
    "/politica-privacidade",
    "/politica-devolucoes",
    "/politica-entrega",

    # Admin
    "/admin",
    "/ADMIN",
    "/Admin",

    # APIs
    "/api/catalog/products.php",
    "/api/catalog/categories.php",
    "/api/cart/validate"
)

# ================================================================================
# VARIÁVEIS DE CONTROLE
# ================================================================================

$results = @()
$errors = @()
$warnings = @()
$okCount = 0
$errorCount = 0

Write-Host "📍 Base URL: $BaseUrl" -ForegroundColor Gray
Write-Host "⏱️  Timeout: $Timeout segundos" -ForegroundColor Gray
Write-Host ""

# ================================================================================
# FUNÇÕES
# ================================================================================

function Test-Page {
    param(
        [string]$Path,
        [string]$Description = ""
    )

    $url = "$BaseUrl$Path"
    $desc = $Description -eq "" ? $Path : $Description

    try {
        Write-Host -NoNewline "  Testando: $desc ... "

        $response = Invoke-WebRequest -Uri $url -Method Head -TimeoutSec $Timeout -SkipHttpErrorCheck -SkipCertificateCheck -ErrorAction SilentlyContinue

        if ($response.StatusCode -eq 200 -or $response.StatusCode -eq 301 -or $response.StatusCode -eq 302) {
            Write-Host "✅ $($response.StatusCode)" -ForegroundColor Green
            $script:okCount++
            return @{
                Path = $desc
                URL = $url
                Status = $response.StatusCode
                Result = "✅ OK"
                Error = $null
            }
        } elseif ($response.StatusCode -eq 404) {
            Write-Host "❌ 404 NÃO ENCONTRADA" -ForegroundColor Red
            $script:errorCount++
            $script:errors += $desc
            return @{
                Path = $desc
                URL = $url
                Status = $response.StatusCode
                Result = "❌ 404"
                Error = "Página não encontrada"
            }
        } elseif ($response.StatusCode -eq 500) {
            Write-Host "❌ 500 ERRO INTERNO" -ForegroundColor Red
            $script:errorCount++
            $script:errors += $desc
            return @{
                Path = $desc
                URL = $url
                Status = $response.StatusCode
                Result = "❌ 500"
                Error = "Erro interno do servidor"
            }
        } else {
            Write-Host "⚠️  $($response.StatusCode)" -ForegroundColor Yellow
            $script:warnings += $desc
            return @{
                Path = $desc
                URL = $url
                Status = $response.StatusCode
                Result = "⚠️  INESPERADO"
                Error = "Status $($response.StatusCode)"
            }
        }
    } catch {
        Write-Host "❌ TIMEOUT/CONEXÃO ERRO" -ForegroundColor Red
        $script:errorCount++
        $script:errors += $desc
        return @{
            Path = $desc
            URL = $url
            Status = 0
            Result = "❌ ERRO"
            Error = $_.Exception.Message
        }
    }
}

# ================================================================================
# TESTAR PÁGINAS PRINCIPAIS
# ================================================================================

Write-Host "📄 TESTANDO PÁGINAS PRINCIPAIS..." -ForegroundColor Cyan
Write-Host ""

foreach ($page in $pages) {
    $result = Test-Page -Path $page
    $results += $result
}

# ================================================================================
# TESTAR PRODUTOS
# ================================================================================

Write-Host ""
Write-Host "📦 TESTANDO PRODUTOS..." -ForegroundColor Cyan
Write-Host ""

# Tentar obter lista de produtos da API
try {
    Write-Host "  Obtendo lista de produtos..." -ForegroundColor Gray

    $productsUrl = "$BaseUrl/api/catalog/products.php?limit=1000"
    $response = Invoke-WebRequest -Uri $productsUrl -TimeoutSec $Timeout -SkipCertificateCheck -ErrorAction SilentlyContinue

    if ($response.StatusCode -eq 200) {
        $products = $response.Content | ConvertFrom-Json -ErrorAction SilentlyContinue

        if ($products -and $products.Count -gt 0) {
            Write-Host "  ✅ Obtidos $($products.Count) produtos" -ForegroundColor Green
            Write-Host ""

            $productCount = 0
            $maxProductsToTest = 20  # Testar apenas os primeiros 20 para não demorar muito

            foreach ($product in $products | Select-Object -First $maxProductsToTest) {
                $productCount++

                if ($product.slug) {
                    $slug = $product.slug
                    $result = Test-Page -Path "/produto/$slug" -Description "Produto: $($product.nome ?? $slug)"
                    $results += $result
                }

                if ($productCount -ge $maxProductsToTest) {
                    Write-Host ""
                    Write-Host "  (Testados primeiros $maxProductsToTest de $($products.Count) produtos)" -ForegroundColor Gray
                    Write-Host "  (Para testar TODOS, executar: .\audit-products-full.ps1)" -ForegroundColor Gray
                    break
                }
            }
        } else {
            Write-Host "  ⚠️  Nenhum produto encontrado na API" -ForegroundColor Yellow
        }
    }
} catch {
    Write-Host "  ⚠️  Erro ao obter produtos: $($_.Exception.Message)" -ForegroundColor Yellow
}

# ================================================================================
# GERAR RELATÓRIO
# ================================================================================

Write-Host ""
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📊 RELATÓRIO DE AUDITORIA" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

Write-Host "✅ SUCESSO: $okCount páginas" -ForegroundColor Green
Write-Host "❌ ERROS: $errorCount páginas" -ForegroundColor Red

if ($errors.Count -gt 0) {
    Write-Host ""
    Write-Host "🔴 PÁGINAS COM ERRO:" -ForegroundColor Red
    foreach ($errorItem in $errors) {
        Write-Host "   ❌ $errorItem" -ForegroundColor Red
    }
}

if ($warnings.Count -gt 0) {
    Write-Host ""
    Write-Host "🟡 PÁGINAS COM AVISO:" -ForegroundColor Yellow
    foreach ($warningItem in $warnings) {
        Write-Host "   ⚠️  $warningItem" -ForegroundColor Yellow
    }
}

# ================================================================================
# SALVAR RELATÓRIO EM ARQUIVO
# ================================================================================

Write-Host ""
Write-Host "📄 Salvando relatório em $reportFile..." -ForegroundColor Gray

$reportContent = @"
# 🔍 Auditoria de Páginas e Produtos - $timestamp

**Site:** $BaseUrl
**Data:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')

## 📊 Resumo

| Métrica | Valor |
|---------|-------|
| Total testado | $($results.Count) |
| ✅ Sucesso | $okCount |
| ❌ Erros | $errorCount |
| ⚠️ Avisos | $($warnings.Count) |
| Taxa de sucesso | $([Math]::Round(($okCount / $results.Count) * 100, 1))% |

## ✅ Páginas Funcionando ($okCount)

| Página | Status | URL |
|--------|--------|-----|
"@

foreach ($result in $results | Where-Object { $_.Status -eq 200 -or $_.Status -eq 301 -or $_.Status -eq 302 }) {
    $reportContent += "`n| $($result.Path) | $($result.Status) | $($result.URL) |"
}

if ($errors.Count -gt 0) {
    $reportContent += @"

## ❌ Páginas com ERRO ($errorCount)

| Página | Status | URL | Erro |
|--------|--------|-----|------|
"@

    foreach ($result in $results | Where-Object { $_.Result -like "*❌*" }) {
        $reportContent += "`n| $($result.Path) | $($result.Status) | $($result.URL) | $($result.Error) |"
    }
}

if ($warnings.Count -gt 0) {
    $reportContent += @"

## ⚠️ Páginas com AVISO ($($warnings.Count))

| Página | Status | URL | Aviso |
|--------|--------|-----|-------|
"@

    foreach ($result in $results | Where-Object { $_.Result -like "*⚠️*" }) {
        $reportContent += "`n| $($result.Path) | $($result.Status) | $($result.URL) | $($result.Error) |"
    }
}

$reportContent += @"

## 📝 Notas

- Timeout: $Timeout segundos
- Método: HEAD requests (rápido)
- Redirecionamentos (301, 302) considerados OK
- Primeiros 20 produtos testados (para teste completo: `audit-products-full.ps1`)

---

**Gerado em:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
"@

$reportContent | Out-File -FilePath $reportFile -Encoding UTF8 -Force

Write-Host "✅ Relatório salvo: $reportFile" -ForegroundColor Green

# ================================================================================
# RESUMO FINAL
# ================================================================================

Write-Host ""

if ($errorCount -eq 0) {
    Write-Host "🎉 AUDITORIA CONCLUÍDA - NENHUM ERRO ENCONTRADO!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "⚠️  AUDITORIA CONCLUÍDA - $errorCount ERROS ENCONTRADOS" -ForegroundColor Red
    exit 1
}
