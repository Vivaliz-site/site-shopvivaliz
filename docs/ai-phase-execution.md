# AI Phase Execution

## Objetivo
- Continuar a operacao autonoma por fases sem depender de credenciais locais expostas.
- Distinguir tarefas prontas para execucao local, prontas apenas no CI com GitHub Secrets e tarefas bloqueadas por aprovacao ou acesso manual.

## Fases
1. `phase-1-foundation`
   - Ativacao do orquestrador e validacao da esteira base.
2. `phase-2-revenue`
   - CRO, SEO automatico e paginas dinamicas de produto.
3. `phase-3-marketplaces`
   - Shopee e Mercado Livre usando secrets existentes no GitHub.
4. `phase-4-approval-gated`
   - Google Ads e dominio, que dependem de aprovacao humana ou acesso DNS.

## Execucao
- Rodar `python scripts/run-autonomy-phases.py`
- Para selecao continua da proxima tarefa segura, rodar `python scripts/autonomous-continuous-cycle.py --advance`
- O script:
  - le a fila canonica em `tasks-queue.json`
  - consulta apenas os nomes dos secrets com `gh secret list`
  - busca o status mais recente do workflow `shopvivaliz-qa.yml`
  - gera:
    - `logs/autonomy-phase-report.json`
    - `logs/autonomy-phase-report.md`

## Leitura do resultado
- `ready_local`: a tarefa pode seguir com o runtime atual.
- `ready_ci_with_repo_secrets`: o runtime local nao tem as variaveis, mas os secrets existem no GitHub e a fase pode seguir por workflow/CI.
- `blocked_missing_secret`: faltam nomes de secrets esperados no repositorio.
- `blocked_human_approval_required`: a tarefa depende de aprovacao humana.
- `blocked_manual_access`: a tarefa depende de acesso externo manual, como DNS.

## Governanca
- Nenhuma fase pode alterar preco, desconto, frete, comissao ou pagamento.
- Guardiao de Preco permanece intacto.
- Ads pagos nao executam automaticamente sem aprovacao.
