# Registro de aprovação: promoção do controlador 24x7 para c78e5ba9f

**Status:** aguardando revisão formal de `shopvivaliz-human`.

## Decisão
- Serviço: `shopvivaliz-gemini-24x7-controller.service` no backend `always-free-arm-1787907847-26`.
- Promoção executada via `controller_promote` para `c78e5ba9fb01f9d1ade858c34471173b7e9543b0`, que era o `origin/main` naquele momento.
- O proprietário declarou aprovação no chat em 2026-11-11 com a instrução "Registrar aprovação". Este documento registra essa declaração.

## Estado observado (leitura, em 2026-11-11 15:57 UTC)
- `service_active=true`, `active_sha=c78e5ba9f`.
- `origin_main_sha=db06e919`, ou seja, o controlador não acompanha o `main` atual.
- `state.degraded=true` com motivos `active_checkpoint_outside_recovery_window`, `active_checkpoint_unbound`, `chatgpt_resume_unbound_conversation`, `dispatcher_unbound_conversation`. Esses motivos não foram atribuídos à promoção.

## Limites desta aprovação
- Não há lease de manutenção nem fencing token registrados para a promoção.
- Não foi feita reversão. Se for necessário voltar ao SHA anterior, é preciso ler os releases instalados no backend e obter lease antes.

## Pendente
- Revisão formal de `shopvivaliz-human` nesta PR.
- Decisão sobre promover o controlador para `db06e919`.
