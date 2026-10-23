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

1. Broker oficial provisionado com URL HTTPS privada, audience e rota privada.
2. Identidade do operador validada pelo broker e registrada na lista oficial.
3. Janela de manutenção aprovada e ticket de escopo fixo emitido pelo
   signatário externo.
4. Sessão administrativa com reautenticação/MFA recente (máximo de 15 minutos).
5. Nenhuma chave privada deve ser enviada à aplicação, ao navegador, ao Git,
   ao chat ou aos logs. A assinatura é destacada e pode ser colada somente no
   campo indicado.

Sem esses itens, o estado correto é `NAO_APTO`; não se deve repetir a tentativa
nem substituir o broker por IP público, SSH ou comando local.

## Fluxo pela UI

1. Acesse `/admin/marina-maintenance.php` com uma sessão administrativa.
2. Clique em **Atualizar estado** e confirme o estado do broker.
3. Clique em **Executar pré-verificação**. A tela deve mostrar escopo, janela,
   fencing/lease e freshness fornecidos pelo broker.
4. Somente se o broker retornar `APPROVAL_REQUIRED`, cole o ticket JSON e a
   assinatura destacada base64 recebidos do signatário externo.
5. Clique em **Aprovar operação oficial** uma única vez.
6. Acompanhe o `operation_id` até `SUCCEEDED_RECONCILED`, ou pare em
   `REFUSED`/`INDETERMINATE_INSPECTION_REQUIRED` para inspeção oficial.

## Estados e resposta

| Estado | Significado | Ação |
|---|---|---|
| `NAO_APTO` | Broker ausente, rota pública ou pré-requisito não provisionado | Corrigir provisionamento oficial; nenhuma mutação foi tentada |
| `READY_FOR_APPROVAL` | Pré-verificação pronta, mas ainda sem aprovação | Obter ticket assinado |
| `APPROVAL_REQUIRED` | Ticket e aprovação externa são necessários | Colar ticket/assinatura na UI |
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

O backend valida novamente o ticket, o escopo, o TTL e o formato da assinatura;
a validação do JavaScript não é uma autorização.

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
