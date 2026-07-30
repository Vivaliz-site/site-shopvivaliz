# Wrapper legado; implementação canônica em scripts/marketplace/olist/olist-sync-complete.ps1
$target = Join-Path $PSScriptRoot "marketplace/olist/olist-sync-complete.ps1"
& $target @args
exit $LASTEXITCODE
