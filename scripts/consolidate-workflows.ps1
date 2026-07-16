# Script para consolidar workflows redundantes
# Marca como PAUSADO todos os que duplicam funcionalidade de outros

$workflows_to_pause = @(
    # Executor autônomo (redundante — master-production-pipeline.yml cuida de validate + monitor)
    "ai-autonomous-executor.yml",
    "autonomous-task-execution.yml",
    "parallel-trio-executor.yml",
    "agent-continuous-task-processor.yml",
    "v12-execucao-automatica.yml",

    # Ciclos autônomos (redundante — autonomous-watchdog.yml é o único necessário)
    "autonomous-cycle.yml",
    "autonomous-orchestrator.yml",
    "autonomous-proactive.yml",
    "24-7-continuous-agent.yml",  # Já feito, mas garante
    "automation-autonoma-24-7.yml",
    "autonomous-agents-24-7.yml",
    "ci-autonomo-continuo.yml",
    "ecommerce-multi-ai-build-24-7.yml",

    # Validação e health checks (redundante — shopvivaliz-qa.yml e master-production-pipeline.yml cobrem)
    "auto-validation-and-fix.yml",
    "health-check.yml",
    "external-smoke-test.yml",
    "site-monitor-autofix.yml",
    "git-auto-sync-validate.yml",

    # Deploy FTP (desativado — produção usa Oracle VM)
    "auto-ftp-deploy.yml",
    "deploy.yml",  # Mantém workflow_dispatch, mas remove triggers automáticos

    # Git operations (redundante — handled via main pipeline)
    "auto-git-pull.yml",
    "auto-git-push.yml",
    "auto-task-generator.yml",
    "auto-commit.yml",

    # Chat/monitoring (redundante — autonomos-watchdog cuida)
    "monitor-chat-responder.yml",
    "monitor-chat-responses.yml",
    "hourly-status-email.yml",

    # Secrets/setup (manual or initial setup only)
    "copy-secrets-to-pipeline.yml",
    "setup-branch-protection.yml",
    "setup-secrets.yml",
    "secret-scan.yml",

    # Shopee/Olist/Tiny helpers (KEEP os scheduling — são integrações específicas)
    # NÃO marcar como pausado:
    # - fetch-shopee-listings.yml
    # - optimize-shopee-listings.yml
    # - sync-shopee-6h.yml
    # - sync-olist-6h.yml
    # - sync-stock-tiny.yml
    # - shopee-upload-com-secrets.yml
    # - shopee-email-pipeline.yml

    # Outros
    "ai-agent-review.yml",
    "ai-pipeline-full.yml",
    "package-v9-2-84.yml",
    "diag-tiny-api.yml",
    "deploy-olist-proxy.yml",
    "deploy-squad-chat.yml",
    "medusa-eha-next-step-30min.yml",
    "auditoria-vazamento-30min.yml"
)

$workflow_dir = ".github/workflows"

foreach ($workflow in $workflows_to_pause) {
    $path = Join-Path $workflow_dir $workflow
    if (-not (Test-Path $path)) {
        Write-Host "⚠️  Workflow não encontrado: $workflow"
        continue
    }

    $content = Get-Content $path -Raw

    # Se já está marcado como PAUSADO, pula
    if ($content -like "*# Desabilitado*" -or $content -like "*PAUSADO*") {
        Write-Host "✓ Já pausado: $workflow"
        continue
    }

    # Marca como pausado
    $new_content = @"
name: PAUSADO - $(($content -match 'name: (.+)$' | ForEach-Object { $Matches[1] }) -replace 'name: ', '')
# Consolidado em master-production-pipeline.yml (2026-07-10)
# Este workflow é redundante e foi consolidado.
on:
  workflow_dispatch:
jobs:
  pausado:
    runs-on: ubuntu-latest
    steps:
      - run: echo "Workflow consolidado. Vide master-production-pipeline.yml para status do deployment."
"@

    Set-Content $path $new_content
    Write-Host "✓ Consolidado: $workflow"
}

Write-Host "`n========================================="
Write-Host "Consolidação completa!"
Write-Host "Workflows ainda ativos:"
Write-Host "  - master-production-pipeline.yml (novo — MASTER)"
Write-Host "  - shopvivaliz-qa.yml (validate)"
Write-Host "  - autonomous-watchdog.yml (monitor)"
Write-Host "  - Integrações: shopee-*.yml, sync-*.yml (específicas)"
Write-Host "========================================="
