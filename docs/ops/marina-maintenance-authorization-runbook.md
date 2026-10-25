# Runbook — Autorização de manutenção Marina

## Objetivo

Esta tela acompanha a operação fixa `retire_origin_abandoned_cart_scheduler`:

- origem: `shopvivaliz-free-a1`;
- unidade: `shopvivaliz-abandoned-cart-recovery.timer`;
- destino: `marina`.

A aplicação web não acessa SSH, não executa shell e não altera cron/systemd. A
única mutação permitida é a solicitação ao broker oficial, pela rota privada
provisionada e com reconciliação devolvida pelo próprio broker.

## Pré-requisitos

1. Broker oficial provisionado com URL HTTPS de DNS interno (`.internal` ou
   `.svc`), audience, identidade SPIFFE esperada, CA, certificado-cliente,
   chave de transporte e pin SPKI. Cabeçalhos de “rota privada” não são prova
   de transporte privado.
2. Identidade do operador validada pelo broker e registrada na lista oficial.
3. Janela de manutenção aprovada; signatário independente e broker privado
   oficialmente provisionados. O signatário mantém a chave fora de PHP,
   navegador, Git e argumentos de CLI e emite o ticket efêmero somente após
   receber a solicitação autenticada do servidor.
4. Sessão administrativa com claim MFA oficial recente (máximo de 15 minutos).
   Reautenticação somente por senha não satisfaz este gate.
5. Nenhuma chave privada, ticket ou assinatura deve ser enviada ao navegador,
   Git, chat ou logs. A UI não possui campo de JSON/assinatura; a capacidade
   transita somente entre o signatário independente e o broker privado.

Sem esses itens, o estado correto é `NAO_APTO`; não se deve repetir a tentativa
nem substituir o broker por IP público, SSH ou comando local.

## Fluxo pela UI

1. Acesse `/admin/marina-maintenance.php` com uma sessão administrativa.
2. Clique em **Atualizar estado** e confirme o estado do broker.
3. Clique em **Executar pré-verificação**. A tela mostra somente o estado e
   identificadores sanitizados; o broker deve guardar a fotografia completa
   de lease/fencing/CAS e emitir `preflight_id` e `preflight_digest`.
4. Somente se a pré-verificação retornar `APPROVAL_REQUIRED` e o estado do
   signatário estiver pronto, clique em **Autorizar operação oficial** uma vez.
   O backend envia ao signatário apenas o contexto autenticado derivado da
   sessão, recebe o ticket assinado internamente e o entrega diretamente ao
   broker. O navegador não recebe essa capacidade.
6. Acompanhe o `operation_id` até `SUCCEEDED_RECONCILED`, ou pare em
   `REFUSED`/`INDETERMINATE_INSPECTION_REQUIRED` para inspeção oficial.

## Estados e resposta

| Estado | Significado | Ação |
|---|---|---|
| `NAO_APTO` | Broker ausente, rota pública ou pré-requisito não provisionado | Corrigir provisionamento oficial; nenhuma mutação foi tentada |
| `READY_FOR_APPROVAL` | Pré-verificação pronta, mas ainda sem aprovação | Confirmar broker/signatário oficial |
| `APPROVAL_REQUIRED` | Broker pronto para autorização interna | Clicar uma vez em Autorizar |
| `RUNNING` | Broker aceitou e está executando | Consultar operação; não repetir |
| `SUCCEEDED_RECONCILED` | Efeito confirmado pelo broker | Registrar evidência e seguir para validação pós-migração |
| `REFUSED` | Controle de autorização recusou | Corrigir a causa indicada, sem contornar |
| `INDETERMINATE_INSPECTION_REQUIRED` | Resultado não pôde ser confirmado | Inspecionar broker/lease oficialmente antes de qualquer nova ação |

## Endpoints protegidos

Todos exigem sessão administrativa. Prévia e aprovação exigem CSRF e
reautenticação/MFA recente:

- `GET /api/admin/marina-maintenance-status.php`
- `POST /api/admin/marina-maintenance-preview.php`
- `POST /api/admin/marina-maintenance-approve.php`
- `GET /api/admin/marina-maintenance-operation.php?operation_id=...`

O backend deriva o sujeito administrativo e a MFA recente da sessão, gera um
identificador idempotente de solicitação e pede ticket ao signatário oficial.
O ticket v2 assinado contém audience do broker, sujeito/operador, identificador
da solicitação e `preflight_id`/digest; o PHP compara todos antes de transmitir.
O broker deve reservar atomicamente esse identificador e nonce e validar
novamente assinatura Ed25519, audience, papel, lease, TTL máximo de 180
segundos, CAS do cron, fence de escritor único e fencing monotônico. JavaScript
nunca é autorização.

## Evidência mínima para declarar a operação concluída

- estado final `SUCCEEDED_RECONCILED` do broker oficial;
- `operation_id` e `audit_id` sanitizados;
- prova de que o serviço de origem permaneceu fenced conforme o ticket;
- confirmação da unidade equivalente no destino Marina;
- validação pós-migração dos fluxos de carrinho abandonado, pedidos e webhooks;
- auditoria extrema e manifesto certificado para o mesmo SHA, release,
  ambiente e escopo.

Enquanto qualquer item faltar, a migração permanece aberta e não deve ser
rotulada como `APTO`.
