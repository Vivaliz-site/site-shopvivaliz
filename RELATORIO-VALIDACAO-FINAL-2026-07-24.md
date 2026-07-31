# Relatório histórico de validação — 2026-07-24

Este snapshot continha um secret de webhook Mercado Pago em texto puro. O valor foi removido da árvore atual e deve ser considerado comprometido.

## Estado confiável

- a presença de um valor em `.env` local nunca comprovou configuração de produção;
- nenhuma credencial deve ser registrada em relatório, screenshot, issue ou artifact;
- a rotação no provedor permanece ação externa obrigatória;
- a validação real exige evento assinado, status, idempotência, read-back e artifact ligado ao run.

Este documento é histórico e não deve ser usado como confirmação de prontidão ou como fonte de credenciais.
