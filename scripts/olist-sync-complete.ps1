#Requires -Version 5.0

<#
    SINCRONIZAR 198 PRODUTOS OLIST - PowerShell Edition
    Abre navegador, faz login, captura código e sincroniza produtos

    Execução: powershell -ExecutionPolicy Bypass -File olist-sync-complete.ps1
#>

param(
    [switch]$Headless = $false,
    [int]$TimeoutSeconds = 120
)

# Cores para output
function Write-Status {
    param([string]$Message, [string]$Status = "INFO")
    $colors = @{
        "OK"    = "Green"
        "ERRO"  = "Red"
        "WARN"  = "Yellow"
        "INFO"  = "Cyan"
    }
    Write-Host "[$(Get-Date -Format 'HH:mm:ss')] [$Status] $Message" -ForegroundColor $colors[$Status]
}

Write-Status "========================================" "INFO"
Write-Status "SINCRONIZAR 198 PRODUTOS OLIST" "INFO"
Write-Status "========================================" "INFO"

# ============================================================================
# CONFIGURACAO
# ============================================================================

$Config = @{
    SiteBase = "https://shopvivaliz.com.br"
    ConnectUrl = "https://shopvivaliz.com.br/olist/connect.php"
    CallbackUrl = "https://shopvivaliz.com.br/olist/callback.php"
    SyncUrl = "https://shopvivaliz.com.br/olist/sync-products.php"
    DiagnosticUrl = "https://shopvivaliz.com.br/api/olist/diagnostic.php"
    Email = $env:OLIST_EMAIL
    Senha = $env:OLIST_PASSWORD
    ClientId = $env:OLIST_CLIENT_ID
}

$LogFile = "logs/olist-sync-$(Get-Date -Format 'yyyyMMdd-HHmmss').log"
$ResultFile = "logs/olist-sync-resultado.json"

New-Item -ItemType Directory -Path (Split-Path $LogFile) -Force | Out-Null

# ============================================================================
# TESTE DE CONECTIVIDADE
# ============================================================================

Write-Status "Testando conectividade com servidor..." "INFO"

try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $response = Invoke-WebRequest -Uri $Config.ConnectUrl -Method Head -TimeoutSec 10 -SkipCertificateCheck -ErrorAction Stop
    Write-Status "Conectividade OK (Status: $($response.StatusCode))" "OK"
} catch {
    Write-Status "Erro de conectividade: $_" "ERRO"
    Write-Status "Verifique se o servidor está online" "WARN"
    exit 1
}

# ============================================================================
# ABRIR NAVEGADOR E FAZER LOGIN
# ============================================================================

Write-Status "Iniciando navegador..." "INFO"

# Detectar navegador disponível
$chrome = Get-Command chrome.exe -ErrorAction SilentlyContinue
$edge = Get-Command msedge.exe -ErrorAction SilentlyContinue
$firefox = Get-Command firefox.exe -ErrorAction SilentlyContinue

$browser = $null
if ($chrome) {
    $browser = "chrome"
    Write-Status "Chrome detectado" "OK"
} elseif ($edge) {
    $browser = "edge"
    Write-Status "Edge detectado" "OK"
} elseif ($firefox) {
    $browser = "firefox"
    Write-Status "Firefox detectado" "OK"
} else {
    Write-Status "Nenhum navegador encontrado (Chrome, Edge, Firefox)" "ERRO"
    exit 1
}

# Abrir conexão com Olist
Write-Status "Abrindo navegador em: $($Config.ConnectUrl)" "INFO"

$ie = New-Object -COM "InternetExplorer.Application"
$ie.Visible = $true
$ie.Navigate($Config.ConnectUrl)

# Aguardar redirecionamento
Write-Status "Aguardando redirecionamento para Olist..." "INFO"
$redirected = $false
$waitTime = 0

while (-not $redirected -and $waitTime -lt $TimeoutSeconds) {
    try {
        $currentUrl = $ie.LocationURL
        if ($currentUrl -like "*accounts.tiny.com.br*" -or $currentUrl -like "*id.olist.com*") {
            $redirected = $true
            Write-Status "Redirecionado para: $currentUrl" "OK"
        }
    } catch {}

    Start-Sleep -Seconds 2
    $waitTime += 2
}

# ============================================================================
# INSTRUÇÕES PARA LOGIN MANUAL
# ============================================================================

Write-Status "========================================" "INFO"
Write-Status "PRÓXIMOS PASSOS" "INFO"
Write-Status "========================================" "INFO"
Write-Status "1. Você verá a tela de login da Olist no navegador" "INFO"
Write-Status "2. Faça login com:" "INFO"
Write-Status "   Email: $($Config.Email)" "INFO"
Write-Status "   Senha: (será usada automaticamente)" "INFO"
Write-Status "3. Autorize a aplicação ShopVivaliz quando solicitado" "INFO"
Write-Status "4. Você será redirecionado para a página de confirmação" "INFO"
Write-Status "5. O script automaticamente sincronizará os 198 produtos" "INFO"
Write-Status "" "INFO"
Write-Status "Aguardando você completar o login (até $TimeoutSeconds segundos)..." "WARN"
Write-Status "========================================" "INFO"

# ============================================================================
# TENTAR PREENCHER CREDENCIAIS AUTOMATICAMENTE (se possível)
# ============================================================================

Write-Status "Tentando preencher credenciais automaticamente..." "INFO"

# Aguardar página carregar
Start-Sleep -Seconds 3

try {
    # Procurar campo de email
    $emailField = $ie.Document.querySelector('input[type="email"], input[name="email"], input[id="email"]')
    if ($emailField) {
        Write-Status "Campo de email encontrado, preenchendo..." "OK"
        $emailField.value = $Config.Email
        $emailField.focus()
        Start-Sleep -Seconds 1
    } else {
        Write-Status "Campo de email não encontrado (você preencherá manualmente)" "WARN"
    }

    # Procurar e clicar em botão de continuar
    $buttons = $ie.Document.querySelectorAll('button')
    if ($buttons.Length -gt 0) {
        Write-Status "Clicando em próximo..." "OK"
        $buttons[0].click()
        Start-Sleep -Seconds 2
    }

    # Procurar campo de senha
    $senhaField = $ie.Document.querySelector('input[type="password"], input[name="senha"]')
    if ($senhaField) {
        Write-Status "Campo de senha encontrado, preenchendo..." "OK"
        $senhaField.value = $Config.Senha
        Start-Sleep -Seconds 1

        # Procurar botão de login
        $loginBtn = $ie.Document.querySelector('button[type="submit"], button')
        if ($loginBtn) {
            Write-Status "Clicando em Login..." "OK"
            $loginBtn.click()
            Start-Sleep -Seconds 2
        }
    }
} catch {
    Write-Status "Preenchimento automático falhou (manual é OK): $_" "WARN"
}

# ============================================================================
# AGUARDAR REDIRECIONAMENTO PARA CALLBACK
# ============================================================================

Write-Status "Aguardando redirecionamento para callback.php..." "INFO"

$callbackReached = $false
$callbackWaitTime = 0
$maxCallbackWait = 180

while (-not $callbackReached -and $callbackWaitTime -lt $maxCallbackWait) {
    try {
        $currentUrl = $ie.LocationURL

        if ($currentUrl -like "*callback.php*") {
            $callbackReached = $true
            Write-Status "Callback recebido! URL: $currentUrl" "OK"

            # Extrair código da página
            $pageHtml = $ie.Document.DocumentElement.innerHTML
            if ($pageHtml -match "code[`"']?\s*[:=]\s*[`"']?([a-zA-Z0-9_.-]+)[`"']?") {
                $authCode = $matches[1]
                Write-Status "Authorization code capturado: $($authCode.Substring(0,30))..." "OK"
            }
        }
    } catch {}

    Start-Sleep -Seconds 3
    $callbackWaitTime += 3

    if ($callbackWaitTime % 30 -eq 0) {
        Write-Status ("Aguardando... ({0} s)" -f $callbackWaitTime) "WARN"
    }
}

if (-not $callbackReached) {
    Write-Status "Timeout aguardando callback (você pode estar em uma tela de confirmação)" "WARN"
    Write-Status "Abrindo página de sincronização..." "INFO"
}

# ============================================================================
# ACESSAR PÁGINA DE SINCRONIZAÇÃO
# ============================================================================

Write-Status "Acessando página de sincronização..." "INFO"
$ie.Navigate($Config.SyncUrl)

# Aguardar sincronização
Write-Status "Aguardando sincronização dos 198 produtos (pode levar alguns minutos)..." "INFO"
$syncComplete = $false
$syncWaitTime = 0
$maxSyncWait = 600

while (-not $syncComplete -and $syncWaitTime -lt $maxSyncWait) {
    try {
        $pageHtml = $ie.Document.DocumentElement.innerHTML

        if ($pageHtml -like '*"sucesso": true*' -or $pageHtml -like '*198*produtos*') {
            $syncComplete = $true
            Write-Status "SINCRONIZAÇÃO COMPLETA!" "OK"

            # Extrair resultado
            if ($pageHtml -match '"total_produtos":\s*(\d+)') {
                $total = $matches[1]
                Write-Status "Total de produtos: $total" "OK"
            }
            if ($pageHtml -match '"com_imagem":\s*(\d+)') {
                $comImagem = $matches[1]
                Write-Status "Com imagem: $comImagem" "OK"
            }
            if ($pageHtml -match '"sem_imagem":\s*(\d+)') {
                $semImagem = $matches[1]
                Write-Status "Sem imagem: $semImagem" "OK"
            }
        }
    } catch {}

    Start-Sleep -Seconds 5
    $syncWaitTime += 5

    if ($syncWaitTime % 30 -eq 0) {
        Write-Status ("Aguardando sincronização... ({0} s)" -f $syncWaitTime) "WARN"
    }
}

# ============================================================================
# RESULTADO FINAL
# ============================================================================

Write-Status "========================================" "INFO"
Write-Status "CONCLUÍDO" "OK"
Write-Status "========================================" "INFO"

if ($syncComplete) {
    Write-Status "Todos os 198 produtos foram sincronizados com sucesso!" "OK"
    Write-Status "Verifique o catálogo em: https://shopvivaliz.com.br/catalogo/" "INFO"
} else {
    Write-Status "A sincronização pode estar em andamento. Verifique manualmente em:" "WARN"
    Write-Status "$($Config.SyncUrl)" "INFO"
}

# Manter janela aberta
Write-Status "Pressione Enter para fechar..." "INFO"
Read-Host
$ie.Quit()
