# Solicitação de autorização comercial: reconciliar tabela derivada `products`

**Estado: AGUARDANDO REVISÃO HUMANA FORMAL / NÃO EXECUTAR.**

## Proposta concreta para aprovação na própria PR

Autorizar **exclusivamente** a atualização idempotente da tabela **MySQL derivada `products` do site** a partir da lista **atual de produtos ativos do ERP Olist/Tiny v3 e preços efetivos da tabela Amazon 991**, sem qualquer alteração no ERP, marketplace, promoções, custos, pedidos, reservas ou anúncios. Não editar preços manualmente nem inferir valores de histórico/planilhas.

Proposta de escopo técnico para o revisor humano aceitar ou rejeitar:

- **Origem autoritativa:** produtos ativos via API oficial Olist v3, cache atômico `storage/products-cache-ativos.json` obtido do daemon aprovado, tabela Amazon 991 e disponibilidade v3.
- **Destino único:** tabela local derivada `products` do MySQL ShopVivaliz; a fonte primária ERP permanece intacta. Preservar IDs internos, FKs, pedidos e reservas.
- **Atualizações permitidas depois da revisão:** mapear `olist_id` por SKU único, preços derivados da tabela Amazon 991, estoque **disponível/vendável** (não saldo físico), `active` e inclusão/deativação correspondente à lista ERP. Não modificar outros campos comerciais.
- **Limite de escopo:** aplicar somente aos produtos efetivamente ativos na nova consulta ERP, após reconfirmar identidade do host, SHA, cache e diffs; qualquer SKU duplicado, divergência de preço comercial não conciliável, conflito de índice, pedido/reserva impactado ou efeito não previsto deve bloquear a aplicação para nova revisão.

## Evidências reais — 01/11/2026 UTC

- Auditor oficial da release `61d73ec6ee95882329bfb84a282812861e867e8a` terminou **PRICE_PARITY_VERDICT=PASS**, sem mutações: 202 produtos ativos ERP, 202 cache, 200 publicados, **200/200 preços públicos** correspondendo à tabela Amazon 991; 0 SKU duplicado; 0 erro. Relatório `/home/ubuntu/shopvivaliz-deploy/shared/logs/price-parity-61d73ec6ee95882329bfb84a282812861e867e8a-recheck-20261101.json` (SHA256 `5864a068b8c97d125813c422f294429a6ab8dcc72e98352a00a734615bb44662`).
- Cache atual gerado às 21:34 UTC pelo `shopvivaliz-catalog-reconcile.service`: 202 produtos, 0 falhas de estoque. Não foi alterado por esta proposta.
- Preflight **somente leitura** comparando cache com MySQL derivado: 260 linhas de produtos em MySQL; **19 IDs ERP divergentes, 155 preços locais divergentes, 149 estoques locais divergentes, 4 novos SKUs, 4 reativações e 6 possíveis desativações**. Não foram encontrados conflitos de identidade `SKU versus olist_id` entre linhas existentes; 1 item de cache sem campos obrigatórios ficaria de fora. **Recalcular e revisar esses números imediatamente antes da aplicação; não são autorização automática.**
- Exemplo SKU `1C7Q-LKVS-MDOC`: produto ERP/cache/site atual é ID `393626238`, preço Amazon 991 `R$38,90`, estoque vendável `4`. MySQL derivado antigo ainda possui `olist_id=387064728`, `R$10,00`, estoque `0`. O endpoint legado `api/cart/add.php` lê MySQL, expondo risco de rejeição de carrinho ou divergência em caminhos legados. O fluxo principal de pedidos usa cache ERP.
- A PR documental #3472 foi integrada, **mas não preencheu a decisão exigida**. Este pedido tem escopo separado e não usa o merge #3472 como autorização.
- Issue de rastreio [#3271](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3271); auditoria ampliada [#3404](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3404).

## Procedimento obrigatório após aprovação humana válida

1. Conferir autorização por **revisão `APPROVED` atual da identidade exclusiva `shopvivaliz-human`**, sem login compartilhado, no SHA vigente; garantir checks obrigatórios e proteções de branch. Não considerar label, comentário, merge documental ou aprovação de agente como permissão.
2. Descobrir lease/fencing do controlador para o escritor comercial; não executar se houver outro escritor concorrente. Confirmar host **Marina**, release imutável e serviços; nunca usar VM excluída.
3. Gerar backup verificável e **reversível** da tabela derivada e suas relações antes de qualquer escrita. Registrar hash, contagem, esquema, vínculos e restauração de teste em ambiente isolado; não publicar dados sensíveis.
4. Implementar plano de sincronização **transacional e idempotente** (o `sync-daemon-to-db.php` antigo executa múltiplos statements sem transação; não executá-lo diretamente em massa). Primeiro dry-run com diferenças por ID/SKU; aplicar somente diff autorizado e condições de preflight.
5. Verificar 100% de linhas afetadas por readback; preservar IDs internos de pedido, pedidos e reservas; provar que as 4 ativações/6 possíveis desativações são válidas; reverter se houver discrepância.
6. Reexecutar auditoria ERP v3 Amazon 991 de catálogo e as jornadas reais de UI de busca/produto/carrinho/checkout **sem pagamento real**, incluindo o endpoint legado quando em uso; validar persistência após reload e versão pós-deploy. Registrar RED/GREEN/regressão/causa raiz/observabilidade.
7. Automatização futura de mutações de `price`/`stock` requer autorização permanente **separada** pelo mesmo mecanismo; esta aprovação cobre **uma reconciliação** sob escopo e SHA controlados, não um grant ilimitado.

## Controles de não-execução

Esta PR é **somente documental**. Não cria um executor de escrita, não é autorização por si, não modifica ERP/DB/site, nem muda segurança. `config/autonomy-policy.json` mantém `price` e `stock` como `human_only_domains`; seguir `docs/ops/commercial-native-pr-review-2026-10-31.md`.

**Decisão solicitada ao proprietário:** aprovar via revisão nativa desta PR a **reconciliação única descrita acima**; se desejar qualquer outra opção, usar `CHANGES_REQUESTED` com escopo concreto. A revisão não dispensa gates, backup, test restore, E2E e relatório de efetivação.
