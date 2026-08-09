param(
    [string]$TenantId = 'cc55b801-12c2-4ea2-a930-d639aa8988a4',
    [string]$ClientId = 'a5e400f0-969e-4fbe-be61-d390cb112517',
    [string]$Subject = 'CN=ShopVivalizExchangeAuth'
)

$ErrorActionPreference = 'Stop'
$certDir = 'C:\Certs'
$cerPath = Join-Path $certDir 'ShopVivalizExchangeAuth.cer'
New-Item -ItemType Directory -Force -Path $certDir | Out-Null

$store = New-Object System.Security.Cryptography.X509Certificates.X509Store(
    [System.Security.Cryptography.X509Certificates.StoreName]::My,
    [System.Security.Cryptography.X509Certificates.StoreLocation]::CurrentUser
)
$store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
try {
    $existing = $store.Certificates |
        Where-Object { $_.Subject -eq $Subject -and $_.HasPrivateKey -and $_.NotAfter -gt (Get-Date).AddDays(30) } |
        Sort-Object NotAfter -Descending |
        Select-Object -First 1

    if (-not $existing) {
        $keyName = 'ShopVivalizExchangeAuth-' + [Guid]::NewGuid().ToString('N')
        $keyParams = New-Object System.Security.Cryptography.CngKeyCreationParameters
        $keyParams.Provider = [System.Security.Cryptography.CngProvider]::MicrosoftSoftwareKeyStorageProvider
        $keyParams.KeyUsage = [System.Security.Cryptography.CngKeyUsages]::Signing
        $keyParams.ExportPolicy = [System.Security.Cryptography.CngExportPolicies]::None
        $keyParams.KeyCreationOptions = [System.Security.Cryptography.CngKeyCreationOptions]::None
        $key = [System.Security.Cryptography.CngKey]::Create(
            [System.Security.Cryptography.CngAlgorithm]::Rsa,
            $keyName,
            $keyParams
        )
        try {
            $rsa = New-Object System.Security.Cryptography.RSACng($key)
            try {
                $request = New-Object System.Security.Cryptography.X509Certificates.CertificateRequest(
                    $Subject,
                    $rsa,
                    [System.Security.Cryptography.HashAlgorithmName]::SHA256,
                    [System.Security.Cryptography.RSASignaturePadding]::Pkcs1
                )
                $existing = $request.CreateSelfSigned(
                    [DateTimeOffset]::UtcNow.AddMinutes(-5),
                    [DateTimeOffset]::UtcNow.AddYears(5)
                )
                $store.Add($existing)
            } finally {
                $rsa.Dispose()
            }
        } finally {
            $key.Dispose()
        }

        # Re-read from the Windows store to guarantee the persisted private-key association.
        $existing = $store.Certificates |
            Where-Object { $_.Subject -eq $Subject -and $_.HasPrivateKey } |
            Sort-Object NotAfter -Descending |
            Select-Object -First 1
        if (-not $existing) { throw 'Certificate was created but private key was not persisted in CurrentUser/My' }
    }

    $publicBytes = $existing.Export([System.Security.Cryptography.X509Certificates.X509ContentType]::Cert)
    [System.IO.File]::WriteAllBytes($cerPath, $publicBytes)

    $envPath = 'C:\mei-mg-email\.env'
    if (Test-Path $envPath) {
        $lines = Get-Content $envPath
        $remove = @(
            'MICROSOFT_GRAPH_TENANT_ID',
            'MICROSOFT_GRAPH_CLIENT_ID',
            'MICROSOFT_GRAPH_AUTH_MODE',
            'MICROSOFT_GRAPH_CERT_THUMBPRINT',
            'MICROSOFT_GRAPH_USER',
            'EMAIL_PROVIDER',
            'MAIL_FROM',
            'MAIL_FROM_NAME'
        )
        $clean = $lines | Where-Object {
            $line = $_
            -not ($remove | Where-Object { $line -match ('^\s*' + [regex]::Escape($_) + '\s*=') })
        }
        $clean += 'EMAIL_PROVIDER=microsoft_graph'
        $clean += 'MICROSOFT_GRAPH_AUTH_MODE=app_only_cert'
        $clean += "MICROSOFT_GRAPH_TENANT_ID=$TenantId"
        $clean += "MICROSOFT_GRAPH_CLIENT_ID=$ClientId"
        $clean += 'MICROSOFT_GRAPH_USER=naoresponda@dev.shopvivaliz.com.br'
        $clean += "MICROSOFT_GRAPH_CERT_THUMBPRINT=$($existing.Thumbprint)"
        $clean += 'MAIL_FROM=Contabilidade Melo <naoresponda@dev.shopvivaliz.com.br>'
        $clean += 'MAIL_FROM_NAME=Contabilidade Melo'
        Set-Content -Path $envPath -Value $clean -Encoding UTF8
    }

    Write-Output 'FREDWIN_M365_CERT_READY'
    Write-Output ('certificate_thumbprint=' + $existing.Thumbprint)
    Write-Output ('certificate_not_after=' + $existing.NotAfter.ToString('o'))
    Write-Output ('public_certificate_path=' + $cerPath)
    Write-Output ('tenant_id=' + $TenantId)
    Write-Output ('client_id=' + $ClientId)
    Write-Output ('private_key_exportable=false')
} finally {
    $store.Close()
}
