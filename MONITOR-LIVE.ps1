# Monitor TEMPO REAL para Compra
# Execute isso enquanto faz a compra

Clear-Host
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "MONITOR TEMPO REAL - ShopVivaliz" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$lastEmailLine = 0
$lastOlistLine = 0

while ($true) {
    Clear-Host
    Write-Host "[$([datetime]::now.ToString('HH:mm:ss'))] MONITORANDO..." -ForegroundColor Green
    Write-Host ""

    # 1. Site Status
    Write-Host "1. SITE:" -ForegroundColor Yellow
    try {
        $response = curl -s -I "https://shopvivaliz.com.br/" | Select-Object -First 1
        if ($response -match "200") {
            Write-Host "   [OK] Online" -ForegroundColor Green
        } else {
            Write-Host "   [FAIL] Offline" -ForegroundColor Red
        }
    } catch {
        Write-Host "   [ERROR] $($_.Message)" -ForegroundColor Red
    }
    Write-Host ""

    # 2. Email Log
    Write-Host "2. EMAIL:" -ForegroundColor Yellow
    if (Test-Path "logs/email-*.log") {
        $emailLogs = Get-ChildItem "logs/email-*.log" | Select-Object -Last 1
        if ($emailLogs) {
            $lines = Get-Content $emailLogs | Measure-Object -Line
            if ($lines.Lines -gt $lastEmailLine) {
                Write-Host "   [NEW] Email activity detected!" -ForegroundColor Green
                Get-Content $emailLogs | Select-Object -Last 3
                $lastEmailLine = $lines.Lines
            } else {
                Write-Host "   [WAIT] Aguardando email..." -ForegroundColor Yellow
            }
        }
    }
    Write-Host ""

    # 3. Olist Log
    Write-Host "3. OLIST SYNC:" -ForegroundColor Yellow
    if (Test-Path "logs/olist-sync.log") {
        $olistLog = Get-Content "logs/olist-sync.log" | Measure-Object -Line
        if ($olistLog.Lines -gt 0) {
            Write-Host "   [OK] Sync log detected" -ForegroundColor Green
            Get-Content "logs/olist-sync.log" | Select-Object -Last 2
        } else {
            Write-Host "   [WAIT] Aguardando sync..." -ForegroundColor Yellow
        }
    }
    Write-Host ""

    # 4. Compra Result
    Write-Host "4. COMPRA:" -ForegroundColor Yellow
    if (Test-Path "logs/compra-resultado.json") {
        Write-Host "   [SUCCESS] Resultado encontrado!" -ForegroundColor Green
        $result = Get-Content "logs/compra-resultado.json" | ConvertFrom-Json
        Write-Host "   Order ID: $($result.order_id)"
        Write-Host "   Boleto: $($result.boleto_number)"
        Write-Host ""
        Write-Host "PROXIMOS PASSOS:" -ForegroundColor Cyan
        Write-Host "1. Verificar email: fredmourao@gmail.com"
        Write-Host "2. Login Olist: https://www.olist.com.br/pedidos/"
        Write-Host "3. Procurar pedido: $($result.order_id)"
        break
    } else {
        Write-Host "   [WAIT] Aguardando resultado..." -ForegroundColor Yellow
    }

    Write-Host ""
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "Pressione Ctrl+C para parar" -ForegroundColor Gray
    Write-Host "========================================" -ForegroundColor Cyan

    Start-Sleep -Seconds 10
}
