<#
.SYNOPSIS
    Gera (de forma idempotente) o certificado X.509 usado pela App Registration
    "ShopVivaliz Exchange Automation" para autenticação app-only no Microsoft
    Graph / Exchange Online. Exporta APENAS a chave pública (.cer).

.DESCRIPTION
    - Roda no Fred-Win, com o usuário logado (não precisa ser admin se usar
      CurrentUser\My, que é o padrão deste script).
    - A chave privada NUNCA sai do Windows Certificate Store local e é gerada
      como NÃO exportável (-KeyExportPolicy NonExportable) — não existe .pfx,
      não existe chave privada em arquivo, em nenhum momento.
    - Idempotente: se já existir um certificado válido (não expirado) com o
      mesmo Subject/FriendlyName no store, reaproveita em vez de recriar.
      Use -Force para forçar a criação de um novo (rotação).
    - Ao final, grava um arquivo de METADADOS NÃO SECRETOS (thumbprint,
      subject, datas, caminho do .cer) em formato JSON, pensado para ser
      commitado no repo do ShopVivaliz. Não contém segredo nenhum.

.PARAMETER Subject
    Subject do certificado. Default identifica a integração de forma única.

.PARAMETER FriendlyName
    Nome amigável no Certificate Store, usado para localizar/idempotência.

.PARAMETER StoreLocation
    'CurrentUser' (default, não precisa admin) ou 'LocalMachine' (se for
    rodar como serviço/agendado sob outra conta, precisa admin).

.PARAMETER ValidityYears
    Validade do certificado em anos. Default 2 (equilíbrio entre segurança
    e não precisar rotacionar com frequência excessiva).

.PARAMETER ExportDir
    Pasta onde o .cer público (e o metadata.json) serão gravados.

.PARAMETER Force
    Força a geração de um novo certificado mesmo que já exista um válido
    (usar no procedimento de ROTAÇÃO — ver docs/EXCHANGE-AUTOMATION.md).

.EXAMPLE
    .\New-ShopVivalizExchangeCert.ps1

.EXAMPLE
    # Rotação de certificado (gera um novo, mantém o antigo até a app ser
    # atualizada com o novo thumbprint — ver procedimento de rotação no doc)
    .\New-ShopVivalizExchangeCert.ps1 -Force

.NOTES
    Próximo passo depois de rodar este script: Register-ShopVivalizExchangeApp.ps1
    (cria/atualiza a App Registration no Entra e faz o upload do .cer gerado aqui).
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$Subject = 'CN=ShopVivalizExchangeAutomation',
    [string]$FriendlyName = 'ShopVivaliz Exchange Automation',
    [ValidateSet('CurrentUser', 'LocalMachine')]
    [string]$StoreLocation = 'CurrentUser',
    [ValidateRange(1, 5)]
    [int]$ValidityYears = 2,
    [string]$ExportDir = (Join-Path $PSScriptRoot 'output'),
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

function Write-Section([string]$Text) {
    Write-Host ''
    Write-Host "== $Text ==" -ForegroundColor Cyan
}

Write-Section "ShopVivaliz Exchange Automation - Geracao de certificado X.509"

if (-not (Test-Path $ExportDir)) {
    New-Item -ItemType Directory -Path $ExportDir -Force | Out-Null
}

$storePath = "Cert:\$StoreLocation\My"

# --- Idempotencia: reaproveita certificado valido existente, a menos que -Force ---
$existing = Get-ChildItem $storePath |
    Where-Object {
        $_.Subject -eq $Subject -and
        $_.FriendlyName -eq $FriendlyName -and
        $_.NotAfter -gt (Get-Date) -and
        $_.HasPrivateKey
    } |
    Sort-Object NotAfter -Descending |
    Select-Object -First 1

if ($existing -and -not $Force) {
    Write-Host "Certificado valido ja existe no store ($storePath). Reaproveitando (use -Force para gerar um novo / rotacionar)." -ForegroundColor Yellow
    $cert = $existing
}
else {
    if ($existing -and $Force) {
        Write-Host "Certificado existente encontrado, mas -Force foi passado: gerando um NOVO certificado (rotacao)." -ForegroundColor Yellow
        Write-Host "O certificado antigo (thumbprint $($existing.Thumbprint)) NAO sera removido automaticamente -- mantenha-o ate confirmar que a App Registration e o Exchange ja usam o novo, para nao derrubar o envio em producao durante a transicao." -ForegroundColor Yellow
    }

    if ($PSCmdlet.ShouldProcess($storePath, "Criar novo certificado autoassinado '$Subject'")) {
        $cert = New-SelfSignedCertificate `
            -Subject $Subject `
            -FriendlyName $FriendlyName `
            -CertStoreLocation $storePath `
            -KeyExportPolicy NonExportable `
            -KeySpec Signature `
            -KeyLength 2048 `
            -KeyAlgorithm RSA `
            -HashAlgorithm SHA256 `
            -NotAfter (Get-Date).AddYears($ValidityYears) `
            -Type Custom `
            -TextExtension @("2.5.29.37={text}1.3.6.1.5.5.7.3.2") # Client Authentication EKU

        Write-Host "Novo certificado criado. Chave privada NAO exportavel, permanece apenas em $storePath." -ForegroundColor Green
    }
    else {
        Write-Host "ShouldProcess negativo (-WhatIf) -- nada foi criado." -ForegroundColor Yellow
        return
    }
}

# --- Exporta SOMENTE a chave publica (.cer) ---
$cerPath = Join-Path $ExportDir 'ShopVivalizExchangeAutomation.cer'
Export-Certificate -Cert $cert -FilePath $cerPath -Type CERT | Out-Null

# --- Metadados NAO SECRETOS, para versionar no repo ---
$metadata = [ordered]@{
    generated_at_utc   = (Get-Date).ToUniversalTime().ToString('o')
    subject            = $cert.Subject
    friendly_name      = $cert.FriendlyName
    thumbprint         = $cert.Thumbprint
    not_before_utc     = $cert.NotBefore.ToUniversalTime().ToString('o')
    not_after_utc      = $cert.NotAfter.ToUniversalTime().ToString('o')
    store_location     = $StoreLocation
    store_path         = $storePath
    key_export_policy  = 'NonExportable'
    key_length_bits    = 2048
    hash_algorithm     = 'SHA256'
    public_cer_file    = (Resolve-Path $cerPath).Path
    private_key_status = 'NUNCA exportado. Reside apenas em ' + $storePath + ' na maquina Fred-Win.'
}

$metadataPath = Join-Path $ExportDir 'certificate-metadata.json'
$metadata | ConvertTo-Json -Depth 4 | Set-Content -Path $metadataPath -Encoding utf8

Write-Section "Resultado"
Write-Host "Thumbprint:        $($cert.Thumbprint)" -ForegroundColor Green
Write-Host "Subject:           $($cert.Subject)"
Write-Host "Valido ate (UTC):  $($cert.NotAfter.ToUniversalTime())"
Write-Host "Chave publica:     $cerPath"
Write-Host "Metadados (sem segredo, pode commitar): $metadataPath"
Write-Host ''
Write-Host "PROXIMO PASSO: rode Register-ShopVivalizExchangeApp.ps1 -CertificatePath `"$cerPath`" -CertificateThumbprint `"$($cert.Thumbprint)`"" -ForegroundColor Cyan
Write-Host "Lembrete de seguranca: NUNCA exporte a chave privada (.pfx) deste certificado. NUNCA commite .pfx nem chave privada no Git." -ForegroundColor Yellow

# Saida estruturada para scripts/orquestracao consumirem
[pscustomobject]@{
    Thumbprint    = $cert.Thumbprint
    Subject       = $cert.Subject
    NotAfter      = $cert.NotAfter
    StoreLocation = $StoreLocation
    CerPath       = $cerPath
    MetadataPath  = $metadataPath
}
