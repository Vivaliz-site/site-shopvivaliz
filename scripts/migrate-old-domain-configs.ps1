# Wrapper legado protegido; implementação preservada em scripts/production/legacy/.
if ($env:ALLOW_LEGACY_PRODUCTION_SCRIPT -ne 'YES') {
    throw 'Execução bloqueada. Faça backup, valide em dry-run e defina ALLOW_LEGACY_PRODUCTION_SCRIPT=YES somente após aprovação.'
}
$target = Join-Path $PSScriptRoot 'production/legacy/migrate-old-domain-configs.ps1'
& $target @args
exit $LASTEXITCODE
