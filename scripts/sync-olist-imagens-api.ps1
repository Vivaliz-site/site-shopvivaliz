#Requires -Version 5.0
<#
.SYNOPSIS
Sincroniza imagens de produtos da API Tiny/Olist
Gera CSV: olist_imagens_api.csv

.NOTES
Usa a API: https://api.tiny.com.br/api2/produto.obter.php
Fonte: tokens de OLIST_TOKEN_V2 e OLIST_DEVELOPER_ID
#>

param(
    [string]$TokenV2 = $env:OLIST_TOKEN_V2,
    [string]$DeveloperId = $env:OLIST_DEVELOPER_ID,
    [string]$OutputCsv = "olist_imagens_api.csv"
)

# Verifica tokens
if (-not $TokenV2 -or -not $DeveloperId) {
    Write-Host "[ERRO] Tokens não configurados! Defina:" -ForegroundColor Red
    Write-Host "`$env:OLIST_TOKEN_V2 = 'seu_token'" -ForegroundColor Yellow
    Write-Host "`$env:OLIST_DEVELOPER_ID = 'seu_id'" -ForegroundColor Yellow
    exit 1
}

Write-Host "=== SINCRONIZANDO IMAGENS DA API TINY/OLIST ===" -ForegroundColor Cyan

$ApiUrl = "https://api.tiny.com.br/api2/produto.obter.php"
$imagens = @()
$erros = @()
$total = 0

# Lista de produtos a sincronizar (você fornece os IDs)
# Formato: @("id1", "id2", "id3")
$produtosIds = @(
    # Aqui irão os IDs de olist_product_id ou idProduto
)

Write-Host "Aguardando lista de produtos..." -ForegroundColor Yellow

if ($produtosIds.Count -eq 0) {
    Write-Host "[AVISO] Nenhum produto para sincronizar" -ForegroundColor Yellow
    exit 0
}

Write-Host "Sincronizando $($produtosIds.Count) produtos..." -ForegroundColor Green

foreach ($id in $produtosIds) {
    $total++
    Write-Host "[$total/$($produtosIds.Count)] Sincronizando produto ID: $id" -ForegroundColor Cyan

    try {
        # Preparar request
        $body = @{
            token = $TokenV2
            id = $id
            formato = "json"
        } | ConvertTo-Json

        # Request com header Developer-Id
        $response = Invoke-WebRequest `
            -Uri $ApiUrl `
            -Method POST `
            -Headers @{
                'Content-Type' = 'application/json'
                'Developer-Id' = $DeveloperId
            } `
            -Body $body `
            -UseBasicParsing `
            -TimeoutSec 30 `
            -ErrorAction Stop

        $data = $response.Content | ConvertFrom-Json

        # Extrair imagens de response[0].val.imagensInternas
        if ($data -and $data.retorno -and $data.retorno.produtos -and $data.retorno.produtos.Count -gt 0) {
            $produto = $data.retorno.produtos[0]

            $sku = $produto.codigo ?? ""
            $olistId = $id
            $imagensInternas = $produto.imagensInternas ?? @()

            if ($imagensInternas.Count -eq 0) {
                Write-Host "  -> Sem imagens" -ForegroundColor Gray
            } else {
                Write-Host "  -> $($imagensInternas.Count) imagens encontradas" -ForegroundColor Green

                $position = 0
                foreach ($img in $imagensInternas) {
                    $position++

                    $imagemObj = @{
                        sku = $sku
                        olist_product_id = $olistId
                        image_position = $position
                        image_id = $img.id ?? ""
                        descricao = $img.descricao ?? ""
                        tipo = $img.tipo ?? ""
                        src = $img.src ?? ""
                        srcReal = $img.srcReal ?? ""
                        tamanho = $img.tamanho ?? ""
                        excluido = $img.excluido ?? "0"
                        status = "ativo"
                    }

                    $imagens += $imagemObj
                }
            }
        } else {
            $erros += "Produto $id: resposta inválida ou vazia"
            Write-Host "  -> ERRO: resposta inválida" -ForegroundColor Red
        }

    } catch {
        $erros += "Produto $id: $($_.Exception.Message)"
        Write-Host "  -> ERRO: $($_.Exception.Message)" -ForegroundColor Red
    }
}

# Gerar CSV
Write-Host "`nGerando CSV: $OutputCsv" -ForegroundColor Cyan

if ($imagens.Count -gt 0) {
    $imagens | Export-Csv -Path $OutputCsv -Encoding UTF8 -NoTypeInformation -Force
    Write-Host "[OK] CSV gerado com $($imagens.Count) imagens" -ForegroundColor Green
} else {
    Write-Host "[AVISO] Nenhuma imagem foi sincronizada" -ForegroundColor Yellow
}

# Resumo
Write-Host "`n=== RESUMO ===" -ForegroundColor Cyan
Write-Host "Produtos processados: $total"
Write-Host "Imagens sincronizadas: $($imagens.Count)"
Write-Host "Erros: $($erros.Count)"

if ($erros.Count -gt 0) {
    Write-Host "`nErros encontrados:" -ForegroundColor Yellow
    $erros | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
}

Write-Host "`n[COMPLETO]" -ForegroundColor Green
