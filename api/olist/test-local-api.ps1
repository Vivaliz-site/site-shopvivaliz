# Teste da API Olist Local
# Faz requisições simples para validar que a API está funcionando

param(
    [string]$BaseUrl = "http://localhost:5000",
    [switch]$Verbose
)

$ErrorActionPreference = "Continue"

Write-Host ""
Write-Host "═" * 70 -ForegroundColor Cyan
Write-Host "🧪 Teste da API Olist Local" -ForegroundColor Green
Write-Host "═" * 70 -ForegroundColor Cyan
Write-Host ""

$testsPassed = 0
$testsFailed = 0

# Função para fazer requisição HTTP
function Invoke-ApiTest {
    param(
        [string]$Name,
        [string]$Method,
        [string]$Endpoint,
        [hashtable]$Headers = @{},
        [string]$Body = $null
    )

    $url = "$BaseUrl$Endpoint"
    $testName = "[$Method] $Endpoint"

    Write-Host "Testando: $testName" -ForegroundColor Yellow

    try {
        $params = @{
            Uri             = $url
            Method          = $Method
            Headers         = $Headers
            TimeoutSec      = 10
            ErrorAction     = "Stop"
        }

        if ($Body) {
            $params['Body'] = $Body
            $params['ContentType'] = 'application/json'
        }

        $response = Invoke-WebRequest @params

        if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 300) {
            Write-Host "  ✅ PASS (HTTP $($response.StatusCode))" -ForegroundColor Green

            if ($Verbose) {
                try {
                    $json = $response.Content | ConvertFrom-Json
                    Write-Host "  Response:" -ForegroundColor Gray
                    Write-Host ($json | ConvertTo-Json -Depth 2 | ForEach-Object { "    $_" })
                }
                catch {
                    Write-Host "  Response: $($response.Content)" -ForegroundColor Gray
                }
            }

            return $true
        }
        else {
            Write-Host "  ❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
            return $false
        }
    }
    catch [System.Net.WebException] {
        if ($_.Exception.Response.StatusCode -eq 404) {
            Write-Host "  ⚠️  SKIP (Endpoint não existe)" -ForegroundColor Yellow
            return $null
        }
        else {
            Write-Host "  ❌ FAIL - Erro de conexão: $($_.Exception.Message)" -ForegroundColor Red
            return $false
        }
    }
    catch {
        Write-Host "  ❌ FAIL - $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

# Teste 1: Health Check
Write-Host ""
Write-Host "📡 Teste de Conectividade" -ForegroundColor Cyan
if (Invoke-ApiTest "Health Check" "GET" "/health") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 2: Status
Write-Host ""
Write-Host "📊 Teste de Status" -ForegroundColor Cyan
if (Invoke-ApiTest "Status" "GET" "/status") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 3: Listar Pedidos
Write-Host ""
Write-Host "📦 Testes de Pedidos" -ForegroundColor Cyan
if (Invoke-ApiTest "Listar Pedidos" "GET" "/v2/orders") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 4: Obter Pedido Específico
if (Invoke-ApiTest "Obter Pedido (order-001)" "GET" "/v2/orders/order-001") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 5: Atualizar Pedido
Write-Host ""
Write-Host "✏️  Teste de Atualização" -ForegroundColor Cyan
$updateBody = @{
    status = "shipped"
    tracking_number = "BR123456789"
} | ConvertTo-Json

if (Invoke-ApiTest "Atualizar Pedido" "PATCH" "/v2/orders/order-001" @{} $updateBody) {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 6: Listar Produtos
Write-Host ""
Write-Host "🛍️  Testes de Produtos" -ForegroundColor Cyan
if (Invoke-ApiTest "Listar Produtos" "GET" "/v2/products") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 7: Obter Produto Específico
if (Invoke-ApiTest "Obter Produto (prod-001)" "GET" "/v2/products/prod-001") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 8: Criar Novo Produto
Write-Host ""
Write-Host "➕ Teste de Criação" -ForegroundColor Cyan
$newProduct = @{
    sku      = "SKU003"
    name     = "Novo Produto Teste"
    price    = 99.99
    quantity = 50
} | ConvertTo-Json

if (Invoke-ApiTest "Criar Produto" "POST" "/v2/products" @{} $newProduct) {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 9: Listar Webhooks
Write-Host ""
Write-Host "🔗 Testes de Webhooks" -ForegroundColor Cyan
if (Invoke-ApiTest "Listar Webhooks" "GET" "/webhooks") {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 10: Registrar Webhook
Write-Host ""
Write-Host "➕ Teste de Registro" -ForegroundColor Cyan
$newWebhook = @{
    url   = "http://localhost/api/webhooks/order-status-update.php"
    event = "orders.v2"
} | ConvertTo-Json

if (Invoke-ApiTest "Registrar Webhook" "POST" "/webhooks" @{} $newWebhook) {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 11: OAuth Token
Write-Host ""
Write-Host "🔐 Testes de Autenticação" -ForegroundColor Cyan
if (Invoke-ApiTest "OAuth Token" "POST" "/oauth/token" @{}) {
    $testsPassed++
}
else {
    $testsFailed++
}

# Teste 12: Erro 404
Write-Host ""
Write-Host "⚠️  Teste de Erro" -ForegroundColor Cyan
try {
    $response = Invoke-WebRequest -Uri "$BaseUrl/v2/orders/nao-existe" -Method GET -ErrorAction Stop
    Write-Host "  ❌ FAIL - Deveria retornar 404" -ForegroundColor Red
    $testsFailed++
}
catch {
    if ($_.Exception.Response.StatusCode -eq 404) {
        Write-Host "  ✅ PASS (Corretamente retornou 404)" -ForegroundColor Green
        $testsPassed++
    }
    else {
        Write-Host "  ❌ FAIL - Status code incorreto: $($_.Exception.Response.StatusCode)" -ForegroundColor Red
        $testsFailed++
    }
}

# Resumo
Write-Host ""
Write-Host "═" * 70 -ForegroundColor Cyan
Write-Host "📊 RESUMO DE TESTES" -ForegroundColor Green
Write-Host "═" * 70 -ForegroundColor Cyan
Write-Host ""
Write-Host "  ✅ Aprovados: $testsPassed" -ForegroundColor Green
Write-Host "  ❌ Falhados:  $testsFailed" -ForegroundColor Red
Write-Host "  📊 Total:     $($testsPassed + $testsFailed)" -ForegroundColor Cyan
Write-Host ""

if ($testsFailed -eq 0) {
    Write-Host "🎉 TODOS OS TESTES PASSARAM!" -ForegroundColor Green
    exit 0
}
else {
    Write-Host "⚠️  Alguns testes falharam. Verifique os logs acima." -ForegroundColor Yellow
    exit 1
}
