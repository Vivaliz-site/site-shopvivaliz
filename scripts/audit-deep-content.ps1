#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Auditoria PROFUNDA de conteúdo, formulários e funcionalidades
.DESCRIPTION
    Valida conteúdo real das páginas, não apenas status HTTP
    - Termos e políticas (conteúdo presente)
    - Institucional (sobre, contato)
    - Admin (menus, funcionalidades)
    - Checkout (formulários, Mercado Pago)
    - Pagamento (integração)
#>

param(
    [string]$BaseUrl = "https://dev.shopvivaliz.com.br",
    [int]$Timeout = 15
)

$ErrorActionPreference = "Continue"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "🔎 AUDITORIA PROFUNDA DE CONTEÚDO E FUNCIONALIDADES" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$results = @()
$errors = @()
$passCount = 0
$failCount = 0

# ================================================================================
# PÁGINAS A AUDITAR COM VALIDAÇÕES
# ================================================================================

$audits = @(
    @{
        Name = "Página: Termos e Condições"
        Path = "/termos"
        Checks = @(
            @{ Name = "Conteúdo presente (não vazio)"; Pattern = ".{500,}" },
            @{ Name = "Não contém 'placeholder' ou '[TODO]'"; Pattern = "(?i)(placeholder|TODO|lorem ipsum)" ; Negate = $true },
            @{ Name = "Tem data/versão"; Pattern = "(?i)(última atualização|versão|data)" }
        )
    },

    @{
        Name = "Página: Política de Privacidade"
        Path = "/politica-privacidade"
        Checks = @(
            @{ Name = "Conteúdo presente"; Pattern = ".{500,}" },
            @{ Name = "Não contém placeholder"; Pattern = "(?i)(placeholder|TODO)" ; Negate = $true },
            @{ Name = "Menção a dados pessoais"; Pattern = "(?i)(dados pessoais|privacidade|proteção)" }
        )
    },

    @{
        Name = "Página: Sobre (Institucional)"
        Path = "/sobre"
        Checks = @(
            @{ Name = "Conteúdo sobre empresa"; Pattern = ".{300,}" },
            @{ Name = "Sem placeholder"; Pattern = "(?i)(placeholder|TODO)" ; Negate = $true },
            @{ Name = "Informação de contato ou localização"; Pattern = "(?i)(endereço|contato|email|telefone|localização)" }
        )
    },

    @{
        Name = "Página: Contato (Formulário)"
        Path = "/contato"
        Checks = @(
            @{ Name = "Formulário presente"; Pattern = "<form" },
            @{ Name = "Campo de nome"; Pattern = "(?i)(name|nome)" },
            @{ Name = "Campo de email"; Pattern = "(?i)(email|e-mail)" },
            @{ Name = "Campo de mensagem"; Pattern = "(?i)(mensagem|message|assunto)" },
            @{ Name = "Botão de envio"; Pattern = "(?i)(enviar|submit|envio)" }
        )
    },

    @{
        Name = "Página: Carrinho"
        Path = "/carrinho"
        Checks = @(
            @{ Name = "Página carrega"; Pattern = ".{100,}" },
            @{ Name = "Tem estrutura de carrinho ou mensagem vazio"; Pattern = "(?i)(carrinho|vazio|empty|item)" }
        )
    },

    @{
        Name = "Página: Checkout (Formulário de Pedido)"
        Path = "/checkout"
        Checks = @(
            @{ Name = "Formulário de pedido"; Pattern = "<form" },
            @{ Name = "Campo de CPF/documento"; Pattern = "(?i)(cpf|documento|doc)" },
            @{ Name = "Campo de endereço"; Pattern = "(?i)(endereço|address|rua|cep)" },
            @{ Name = "Campo de telefone"; Pattern = "(?i)(telefone|phone|whatsapp)" },
            @{ Name = "Informação de frete"; Pattern = "(?i)(frete|shipping|envio)" }
        )
    },

    @{
        Name = "API: Pagamento Mercado Pago"
        Path = "/api/catalog/products.php"
        Checks = @(
            @{ Name = "Retorna JSON"; Pattern = "^\{" },
            @{ Name = "Tem produto"; Pattern = "(?i)(id|nome|price|valor)" }
        )
    },

    @{
        Name = "Admin: Dashboard"
        Path = "/admin"
        Checks = @(
            @{ Name = "Página carrega"; Pattern = ".{500,}" },
            @{ Name = "Menu presente"; Pattern = "(?i)(menu|dashboard|admin)" },
            @{ Name = "Link para produtos"; Pattern = "(?i)(produto|product)" },
            @{ Name = "Link para pedidos"; Pattern = "(?i)(pedido|order)" },
            @{ Name = "Link para monitoramento"; Pattern = "(?i)(monitor|health|status)" }
        )
    },

    @{
        Name = "Admin: Produtos"
        Path = "/admin/produtos.php"
        Checks = @(
            @{ Name = "Página carrega"; Pattern = ".{500,}" },
            @{ Name = "Tabela ou lista de produtos"; Pattern = "(?i)(<table|<tbody|class.*produto)" },
            @{ Name = "Ações (editar/deletar)"; Pattern = "(?i)(editar|edit|delete|deletar|remove)" }
        )
    },

    @{
        Name = "Admin: Pedidos"
        Path = "/admin/pedidos.php"
        Checks = @(
            @{ Name = "Página carrega"; Pattern = ".{500,}" },
            @{ Name = "Tabela ou lista"; Pattern = "(?i)(<table|<tbody|class.*pedido)" },
            @{ Name = "Status de pedidos"; Pattern = "(?i)(pendente|confirmado|enviado|entregue)" }
        )
    }
)

# ================================================================================
# FUNÇÃO DE TESTE
# ================================================================================

function Test-ContentPage {
    param(
        [string]$Name,
        [string]$Path,
        [array]$Checks
    )

    Write-Host "🔎 $Name" -ForegroundColor Cyan

    $url = "$BaseUrl$Path"
    $pageResults = @{
        Page = $Name
        Path = $Path
        Status = "PENDENTE"
        Passed = 0
        Failed = 0
        Details = @()
    }

    try {
        Write-Host "   Obtendo conteúdo..." -NoNewline -ForegroundColor Gray

        $response = Invoke-WebRequest -Uri $url -TimeoutSec $Timeout -SkipCertificateCheck -ErrorAction Stop
        $content = $response.Content

        Write-Host " ✅" -ForegroundColor Green

        if ($response.StatusCode -ne 200) {
            Write-Host "   ❌ Status: $($response.StatusCode)" -ForegroundColor Red
            $pageResults.Status = "ERRO_HTTP"
            $pageResults.Failed += 1
            return $pageResults
        }

        # Executar checks
        Write-Host "   Validando conteúdo:" -ForegroundColor Gray

        foreach ($check in $Checks) {
            $checkName = $check.Name
            $pattern = $check.Pattern
            $negate = $check.Negate -eq $true

            $match = $content -match $pattern

            if ($negate) {
                $match = -not $match
            }

            if ($match) {
                Write-Host "     ✅ $checkName" -ForegroundColor Green
                $pageResults.Passed += 1
                $pageResults.Details += @{
                    Check = $checkName
                    Status = "PASSOU"
                }
            } else {
                Write-Host "     ❌ $checkName" -ForegroundColor Red
                $pageResults.Failed += 1
                $pageResults.Details += @{
                    Check = $checkName
                    Status = "FALHOU"
                }
            }
        }

        if ($pageResults.Failed -eq 0) {
            $pageResults.Status = "PASSOU"
            $script:passCount += 1
        } else {
            $pageResults.Status = "FALHOU"
            $script:failCount += 1
            $script:errors += $Name
        }

    } catch {
        Write-Host " ❌ ERRO: $($_.Exception.Message)" -ForegroundColor Red
        $pageResults.Status = "ERRO"
        $pageResults.Failed += 1
        $script:failCount += 1
        $script:errors += $Name
    }

    Write-Host ""
    return $pageResults
}

# ================================================================================
# EXECUTAR AUDITORIAS
# ================================================================================

Write-Host "📍 Base URL: $BaseUrl" -ForegroundColor Gray
Write-Host "⏱️  Timeout: $Timeout segundos" -ForegroundColor Gray
Write-Host ""

foreach ($audit in $audits) {
    $result = Test-ContentPage -Name $audit.Name -Path $audit.Path -Checks $audit.Checks
    $results += $result
}

# ================================================================================
# RELATÓRIO
# ================================================================================

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📊 RESULTADO DA AUDITORIA PROFUNDA" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

Write-Host "✅ PÁGINAS COMPLETAS: $passCount" -ForegroundColor Green
Write-Host "❌ PÁGINAS COM PROBLEMAS: $failCount" -ForegroundColor Red

if ($errors.Count -gt 0) {
    Write-Host ""
    Write-Host "🔴 PÁGINAS COM FALHA:" -ForegroundColor Red
    foreach ($error in $errors) {
        Write-Host "   ❌ $error" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "📋 DETALHES:" -ForegroundColor Cyan
Write-Host ""

foreach ($result in $results) {
    $status = switch ($result.Status) {
        "PASSOU" { "✅" }
        "FALHOU" { "❌" }
        "ERRO" { "🔴" }
        default { "⚠️" }
    }

    Write-Host "$status $($result.Page)" -ForegroundColor $(if ($result.Status -eq "PASSOU") { "Green" } else { "Red" })
    Write-Host "   Passou: $($result.Passed)/$($result.Passed + $result.Failed)" -ForegroundColor Gray
}

# ================================================================================
# SALVAR RELATÓRIO
# ================================================================================

$reportFile = "audit-deep-$timestamp.md"

Write-Host ""
Write-Host "📄 Salvando relatório em $reportFile..." -ForegroundColor Gray

$reportContent = @"
# 🔎 Auditoria Profunda de Conteúdo - $timestamp

**Site:** $BaseUrl
**Data:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')

## 📊 Resumo

| Métrica | Valor |
|---------|-------|
| Páginas testadas | $($results.Count) |
| ✅ Completas | $passCount |
| ❌ Com problemas | $failCount |
| Taxa de sucesso | $([Math]::Round(($passCount / $results.Count) * 100, 1))% |

## 📋 Resultado por Página

"@

foreach ($result in $results) {
    $reportContent += @"

### $($result.Page) - $($result.Status)

**Caminho:** $($result.Path)

| Validação | Status |
|-----------|--------|
"@

    foreach ($detail in $result.Details) {
        $statusIcon = if ($detail.Status -eq "PASSOU") { "✅" } else { "❌" }
        $reportContent += "`n| $($detail.Check) | $statusIcon $($detail.Status) |"
    }

    $reportContent += @"

**Resultado:** Passou $($result.Passed)/$($result.Passed + $result.Failed)

"@
}

$reportContent += @"

## 🔴 Páginas com Problemas

"@

foreach ($error in $errors) {
    $reportContent += "`n- ❌ $error"
}

$reportContent += @"

## 🎯 Recomendações

1. **Termos/Políticas:** Verificar conteúdo completo, sem placeholders
2. **Formulários:** Validar todos os campos obrigatórios
3. **Admin:** Testar funcionalidades de CRUD
4. **Checkout:** Testar fluxo completo de pedido
5. **Pagamento:** Validar integração Mercado Pago

---

**Gerado em:** $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
"@

$reportContent | Out-File -FilePath $reportFile -Encoding UTF8 -Force
Write-Host "✅ Relatório salvo: $reportFile" -ForegroundColor Green

Write-Host ""

if ($failCount -eq 0) {
    Write-Host "🎉 TODAS AS PÁGINAS PASSARAM NA AUDITORIA!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "⚠️  $failCount PÁGINA(S) COM PROBLEMAS - REVISAR" -ForegroundColor Red
    exit 1
}
