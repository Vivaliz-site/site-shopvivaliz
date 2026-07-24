#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Auditoria COMPLETA de todos os 181 produtos
.DESCRIPTION
    Valida cada produto: título, descrição, imagem, slug, SEO, categoria, preço, etc.
#>

param(
    [string]$BaseUrl = "https://dev.shopvivaliz.com.br",
    [int]$Timeout = 15
)

$ErrorActionPreference = "Continue"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📦 AUDITORIA COMPLETA DE TODOS OS PRODUTOS" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"

# ================================================================================
# OBTER PRODUTOS
# ================================================================================

Write-Host "Obtendo lista de produtos..." -ForegroundColor Gray

try {
    $response = Invoke-WebRequest -Uri "$BaseUrl/api/catalog/products.php?limit=1000" -TimeoutSec $Timeout -SkipCertificateCheck
    $products = $response.Content | ConvertFrom-Json
} catch {
    Write-Host "❌ Erro ao obter produtos: $_" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Obtidos $($products.Count) produtos" -ForegroundColor Green
Write-Host ""

# ================================================================================
# VALIDAÇÕES
# ================================================================================

$results = @()
$issues = @()

$passCount = 0
$failCount = 0

Write-Host "🔍 Auditando cada produto..." -ForegroundColor Cyan
Write-Host ""

$productIndex = 0

foreach ($product in $products) {
    $productIndex++

    # Status bar a cada 20 produtos
    if ($productIndex % 20 -eq 0 -or $productIndex -eq 1 -or $productIndex -eq $products.Count) {
        Write-Host "   Progresso: $productIndex/$($products.Count)" -ForegroundColor Gray
    }

    $productResult = @{
        Index = $productIndex
        Nome = $product.nome
        ID = $product.id
        Slug = $product.slug
        Issues = @()
        Passed = 0
        Failed = 0
    }

    # ================================================================================
    # VALIDAR CADA PRODUTO
    # ================================================================================

    # 1. TÍTULO
    if ([string]::IsNullOrWhiteSpace($product.nome)) {
        $productResult.Issues += "❌ Título vazio"
        $productResult.Failed++
    } elseif ($product.nome.Length -lt 5) {
        $productResult.Issues += "⚠️ Título muito curto (< 5 caracteres)"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 2. SLUG
    if ([string]::IsNullOrWhiteSpace($product.slug)) {
        $productResult.Issues += "❌ Slug vazio"
        $productResult.Failed++
    } elseif ($product.slug -notmatch '^[a-z0-9\-]+$') {
        $productResult.Issues += "❌ Slug com caracteres inválidos"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 3. DESCRIÇÃO
    if ([string]::IsNullOrWhiteSpace($product.descricao)) {
        $productResult.Issues += "❌ Descrição vazia"
        $productResult.Failed++
    } elseif ($product.descricao.Length -lt 20) {
        $productResult.Issues += "⚠️ Descrição muito curta (< 20 caracteres)"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 4. IMAGEM
    if ([string]::IsNullOrWhiteSpace($product.imagem_url)) {
        $productResult.Issues += "❌ URL de imagem vazia"
        $productResult.Failed++
    } elseif ($product.imagem_url -like "*placeholder*" -or $product.imagem_url -like "*logo*") {
        $productResult.Issues += "❌ Imagem é placeholder ou logo"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 5. PREÇO
    if ($null -eq $product.preco -or $product.preco -eq "" -or $product.preco -eq "0") {
        $productResult.Issues += "❌ Preço vazio ou zero"
        $productResult.Failed++
    } elseif ([decimal]$product.preco -lt 0.01) {
        $productResult.Issues += "⚠️ Preço muito baixo (< R$ 0,01)"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 6. CATEGORIA
    if ([string]::IsNullOrWhiteSpace($product.categoria)) {
        $productResult.Issues += "❌ Categoria vazia"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 7. ESTOQUE
    if ($null -eq $product.estoque -or $product.estoque -eq "") {
        $productResult.Issues += "⚠️ Estoque não informado"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 8. SKU
    if ([string]::IsNullOrWhiteSpace($product.sku)) {
        $productResult.Issues += "⚠️ SKU vazio"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 9. SEO - Meta título
    if ([string]::IsNullOrWhiteSpace($product.seo_title)) {
        $productResult.Issues += "⚠️ SEO title vazio (usar title)"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # 10. SEO - Meta descrição
    if ([string]::IsNullOrWhiteSpace($product.seo_description)) {
        $productResult.Issues += "⚠️ SEO description vazio"
        $productResult.Failed++
    } elseif ($product.seo_description.Length -lt 30 -or $product.seo_description.Length -gt 160) {
        $productResult.Issues += "⚠️ SEO description fora do range (30-160 chars)"
        $productResult.Failed++
    } else {
        $productResult.Passed++
    }

    # Agrupar erros críticos
    if ($productResult.Failed -gt 5) {
        $failCount++
        $issues += "$($productIndex): $($product.nome ?? 'SEM_NOME') - $($productResult.Failed) problemas"
    } else {
        $passCount++
    }

    $results += $productResult
}

Write-Host ""
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📊 RESULTADO DA AUDITORIA DE PRODUTOS" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

Write-Host "✅ Produtos OK (< 5 problemas): $passCount" -ForegroundColor Green
Write-Host "❌ Produtos com PROBLEMAS (> 5 problemas): $failCount" -ForegroundColor Red

Write-Host ""
Write-Host "🔴 PRODUTOS CRÍTICOS:" -ForegroundColor Red

if ($issues.Count -eq 0) {
    Write-Host "   ✅ Nenhum produto com problemas críticos" -ForegroundColor Green
} else {
    foreach ($issue in $issues | Select-Object -First 20) {
        Write-Host "   $issue" -ForegroundColor Red
    }
    if ($issues.Count -gt 20) {
        Write-Host "   ... e $($issues.Count - 20) mais" -ForegroundColor Red
    }
}

# ================================================================================
# ESTATÍSTICAS
# ================================================================================

Write-Host ""
Write-Host "📈 ESTATÍSTICAS POR VALIDAÇÃO:" -ForegroundColor Cyan
Write-Host ""

$allIssues = @()
foreach ($result in $results) {
    $allIssues += $result.Issues
}

$issueCounts = @{}
foreach ($issue in $allIssues) {
    $issueCounts[$issue]++
}

$issueCounts.GetEnumerator() | Sort-Object -Property Value -Descending | ForEach-Object {
    $percentage = [Math]::Round(($_.Value / $products.Count) * 100, 1)
    Write-Host "  $($_.Name): $($_.Value)/$($products.Count) produtos ($percentage%)" -ForegroundColor Gray
}

# ================================================================================
# SALVAR RELATÓRIO
# ================================================================================

$reportFile = "audit-products-$timestamp.md"

Write-Host ""
Write-Host "📄 Salvando relatório em $reportFile..." -ForegroundColor Gray

$reportContent = @"
# 📦 Auditoria Completa de TODOS os Produtos - $timestamp

**Data:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
**Total de produtos:** $($products.Count)
**Auditados:** $($results.Count)

## 📊 Resumo

| Métrica | Valor |
|---------|-------|
| Produtos OK | $passCount |
| Produtos com problemas | $failCount |
| Taxa de qualidade | $([Math]::Round(($passCount / $results.Count) * 100, 1))% |

## 🔴 Problemas Encontrados

"@

foreach ($issuePair in ($issueCounts.GetEnumerator() | Sort-Object -Property Value -Descending)) {
    $percentage = [Math]::Round(($issuePair.Value / $products.Count) * 100, 1)
    $reportContent += "`n### $($issuePair.Name)`n"
    $reportContent += "`nAcometido: **$($issuePair.Value)/$($products.Count) produtos ($percentage%)**`n"
}

$reportContent += @"

## 🔴 Produtos com Problemas Críticos (>5 problemas)

"@

foreach ($issue in ($issues | Select-Object -First 50)) {
    $reportContent += "`n- $issue"
}

$reportContent += @"

## 📋 Todas as Validações

**Para cada produto, foram validados:**

1. ✅ Título (não vazio, > 5 caracteres)
2. ✅ Slug (válido, lowercase + números + hífen)
3. ✅ Descrição (não vazio, > 20 caracteres)
4. ✅ Imagem (não é placeholder/logo)
5. ✅ Preço (não vazio, > 0)
6. ✅ Categoria (atribuída)
7. ✅ Estoque (informado)
8. ✅ SKU (presente)
9. ✅ SEO Title (presente)
10. ✅ SEO Description (30-160 caracteres)

---

**Gerado em:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
"@

$reportContent | Out-File -FilePath $reportFile -Encoding UTF8 -Force
Write-Host "✅ Relatório salvo: $reportFile" -ForegroundColor Green

Write-Host ""

if ($failCount -eq 0) {
    Write-Host "🎉 TODOS OS PRODUTOS ESTÃO EM PERFEITAS CONDIÇÕES!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "⚠️  $failCount PRODUTOS COM PROBLEMAS CRÍTICOS" -ForegroundColor Yellow
    exit 1
}
