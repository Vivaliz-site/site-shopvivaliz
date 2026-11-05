# P0 Amazon Returns: proposta de complemento de escopo após grant #3556 (05/11/2026)

**NÃO AUTORIZA PRODUÇÃO POR SI SÓ.** Solicitação de revisão P2 exclusivamente por `shopvivaliz-human`. Não altera a PR #3556, não modifica seu canary de oito IDs nem seu prazo de 09/11/2026 23:59Z.

## Causa comprovada e escopo pedido

A base real da Marina, no tenant/conexão **1/1**, registrou um **novo caso #42471** atingindo D+45 em 05/11/2026 00:30:41Z, sem `safe_t_id`, sem crédito conciliado e sem recebimento físico WAREHOUSE nos eventos locais. Refund initiator **AMAZON_CUSTOMER_SERVICE** consta de evento Gmail; evidência física e inexistência de reivindicação externa exigem readback adicional. Saldo esperado R$36,37 (valor nominal, não crédito confirmado). Este caso **não está entre os oito IDs da PR #3556**.

Além disso, a autorização #3556 vence em 09/11, enquanto a orientação pública Amazon para pedidos FBA Onsite/Delivery by Amazon posteriores a 21/04/2026 menciona espera de 60 dias a partir do reembolso. Os quatro casos originalmente contemplados que alcançam esse marco só depois do vencimento são **51429** (10/11, refund initiator UNKNOWN) e **101669, 101670, 101671** (17/11, initiator AMAZON_CUSTOMER_SERVICE). O caso #42471 alcança D+60 em 20/11. A Amazon pode apresentar exceções e datas diferentes: **a decisão oficial específica do Seller Central prevalece**. Fonte: https://sellercentral.amazon.com.br/seller-forums/discussions/t/374d2692-2b56-411e-b497-b37d7cb1229b ; evidência e caveats: https://github.com/Vivaliz-site/amazon-returns-safet/issues/532#issuecomment-6659235465

**Proposta suplementar, SEM substituir o grant antigo:** host `marina-a1-4c24g`, principal `amazon-returns-safet-service`, tenant/conexão 1/1, **somente** IDs `42471, 51429, 101669, 101670, 101671`, operação `activate_scoped_safe_t_opening`, **somente** `SAFE_T_SUBMIT=true`, as outras seis ações de escrita false, `one_use=true`, prazo máximo **30/11/2026 23:59 UTC**. O executor ainda precisa provar lease/fencing single-writer e que o uso one-shot não sacrifica casos que dependam de janelas externas distintas.

## Condições não negociáveis

- D+45 continua **a primeira tentativa/checkpoint interno**, nunca transformar regra interna em D+60.
- Amazon iniciadora comprovada caso a caso, `refund_at` D+45..D+89, ausência de `WAREHOUSE PHYSICAL_RECEIVED` atual, saldo financeiro externo ainda não creditado, elegibilidade Seller Central confirmada e data/limite oficiais observados.
- **UNKNOWN #51429 não pode ser submetido** sem prova independente de que a Amazon iniciou o reembolso.
- Antes de qualquer envio, consultar a existência de SAFE-T e protocolo suporte externo; tratar UI_DRIFT/coverage unverified como **não comprovado** e nunca como ausência de claim. Idempotência/readback do ID externo aceito obrigatórios.
- Sem operador efetivo com grant próprio, lease e RBAC, **não executar**. O runbook `Vivaliz-site/amazon-returns-safet/docs/runbooks/scoped-safe-t-pr3556.md` confirma que `activate_scoped_safe_t_opening` não está certificado.
- Kill switch de produção permanece **ON** até autorização operacional efetiva; em execução permitida, perfil temporário e restauração **ON** no finally e após falha/interrupção. Não reutilizar secret em issue/PR.
- **PR #3556 permanece válida apenas no escopo original**; não duplicar #8803, que tem ID externo `64204-51742-6364222`. Nenhum teste deve criar reivindicação ou suporte real sem todas as verificações.

Aprovação necessária: GitHub `APPROVED` legítimo por `shopvivaliz-human` no HEAD final **desta nova PR**, depois checks oficiais. Não habilitar auto-merge ou fazer merge em nome da conta proprietária. Mesclar documento/grant, isoladamente, não executa claim nem ativa flags.

## Evidência de 05/11

GitHub PR #3556 MERGED e revisão formal aprovada; Amazon Returns release ativa `4c48e237497640aaee45bf167ca2373836a8b89b`; health 7/7 gates externos disabled; 237 SAFE_T_DISCOVERY PENDING com `UI_DRIFT: SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED`; nenhuma reivindicação nesta auditoria. Issue canônica #532. Status **P0/NAO_APTO**.
