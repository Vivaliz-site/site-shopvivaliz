$ErrorActionPreference = "Stop"

Write-Host "🧪 INICIANDO SUITE DE TESTE DE IA LOCAL" -ForegroundColor Cyan

# 1. Submeter tarefa
$TaskPayload = @{
    description = "Leia C:\site-shopvivaliz\.ai\README.md e retorne o titulo principal."
    type = "analysis"
    priority = "normal"
    force_local = $true
} | ConvertTo-Json

Write-Host "• Enviando tarefa para a fila local..."
$SubmitResult = Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:3000/api/tasks" -ContentType "application/json" -Body $TaskPayload
$TaskId = $SubmitResult.task_id

if (-not $TaskId) {
    Write-Error "Falha ao submeter tarefa!"
    exit 1
}

Write-Host "✅ Tarefa submetida com sucesso! ID: $TaskId" -ForegroundColor Green

# 2. Aguardar processamento
$Timeout = 60
$Start = Get-Date
$Completed = $false
$TaskDetails = $null

Write-Host "• Aguardando conclusao da tarefa..." -NoNewline
while (((Get-Date) - $Start).TotalSeconds -lt $Timeout) {
    Write-Host "." -NoNewline
    $TaskDetails = Invoke-RestMethod -Uri "http://127.0.0.1:3000/api/tasks/$TaskId"
    if ($TaskDetails.status -eq "completed" -or $TaskDetails.status -eq "failed" -or $TaskDetails.status -eq "blocked") {
        $Completed = $true
        Write-Host ""
        break
    }
    Start-Sleep -Seconds 2
}

if (-not $Completed) {
    Write-Host ""
    Write-Error "Timeout aguardando processamento da tarefa!"
    exit 1
}

Write-Host "• Status Final da Tarefa: $($TaskDetails.status)" -ForegroundColor Cyan

# 3. Validar resultados e gerar relatorio
$ResultLog = $TaskDetails.result
$ReportFile = "C:\site-shopvivaliz\reports\local-ai-test-report.md"

$ReportLines = @(
    "# Relatorio de Teste de IA Local - ShopVivaliz",
    "",
    "- **Data/Hora:** $(Get-Date -Format g)",
    "- **ID da Tarefa:** `$TaskId`",
    "- **Status da Tarefa:** `$($TaskDetails.status)`",
    "- **Provedor Utilizado:** `$($ResultLog.provider)`",
    "- **Modelo Utilizado:** `$($ResultLog.model)`",
    "- **Tempo de Execucao:** `$($ResultLog.execution_time_ms) ms`",
    "- **Custo Financeiro:** `$($ResultLog.actual_cost) USD`",
    "- **Execucao Simulada (Mock):** `$($ResultLog.simulated)`",
    "",
    "## Output do Modelo",
    "```text",
    $ResultLog.result,
    "```",
    "",
    "## Criterios de Sucesso"
)

# Checks
$PassOllama = $ResultLog.provider -eq "ollama"
$PassModel = $ResultLog.model -eq "qwen2.5-coder:1.5b"
$PassCost = $ResultLog.actual_cost -eq 0
$PassSimulated = $ResultLog.simulated -eq $false

if ($PassOllama -and $PassModel -and $PassCost -and $PassSimulated) {
    $ReportLines += "- **Inferencia Local Real:** `PASS`"
    $ReportLines += "- **Provedor Ollama:** `PASS`"
    $ReportLines += "- **Modelo Correto:** `PASS`"
    $ReportLines += "- **Custo Zero:** `PASS`"
    $ReportLines += "- **Sem Simulacao:** `PASS`"
    $ReportLines += ""
    $ReportLines += "### ✅ TESTES CONCLUIDOS COM SUCESSO!"
    Write-Host "✅ TESTE COMPLETO E APROVADO!" -ForegroundColor Green
} else {
    $ReportLines += "- **Inferencia Local Real:** `FAIL`"
    $ReportLines += "- **Detalhes:** Provedor: $($ResultLog.provider), Modelo: $($ResultLog.model), Custo: $($ResultLog.actual_cost), Simulado: $($ResultLog.simulated)"
    $ReportLines += ""
    $ReportLines += "### ❌ TESTES COM FALHA!"
    Write-Host "❌ TESTE FALHOU!" -ForegroundColor Red
}

New-Item -ItemType Directory -Force -Path "C:\site-shopvivaliz\reports" | Out-Null
$ReportLines | Out-File $ReportFile -Encoding utf8
Write-Host "📝 Relatorio salvo em: $ReportFile" -ForegroundColor Gray
