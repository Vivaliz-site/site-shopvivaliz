# Status dos Workflows GitHub Actions

## O que foi corrigido

- O PR #104 foi mergeado na `main` com squash.
- Os workflows principais foram ajustados para reduzir consumo automático de GitHub Actions.
- A pasta local `.secrets/` passou a estar protegida no `.gitignore`.
- O commit final do merge é `73d31d94ec279c692e3cc04ea454383ce15dbf34`.

## Workflows manuais

- `.github/workflows/deploy.yml`
- `.github/workflows/ci-autonomo-continuo.yml`
- `.github/workflows/autonomous-watchdog.yml`

Todos os três ficaram apenas com `workflow_dispatch`.

## Por que os gatilhos automáticos foram pausados

- Para economizar quota do GitHub Actions durante a estabilização do ambiente.
- Para evitar execuções recorrentes desnecessárias em deploy, CI e monitoramento.
- Para reduzir falhas automáticas enquanto secrets, deploy e watchdog eram validados.

## Como reativar com segurança

- Reativar os gatilhos automáticos somente após validar manualmente os fluxos críticos.
- Reaplicar `push`, `pull_request` ou `schedule` apenas quando a quota do GitHub Actions estiver estável.
- Manter `workflow_dispatch` como fallback durante a fase de observação.

## Checklist antes de reativar

- [ ] Secrets configurados
- [ ] CI validado manualmente
- [ ] Deploy testado manualmente
- [ ] Watchdog testado manualmente
- [ ] Quota GitHub Actions estável
