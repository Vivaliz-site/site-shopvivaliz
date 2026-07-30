# Wrapper legado; implementação canônica em scripts/marketplace/olist/sync-olist-imagens-api.ps1
$target = Join-Path $PSScriptRoot "marketplace/olist/sync-olist-imagens-api.ps1"
& $target @args
exit $LASTEXITCODE
