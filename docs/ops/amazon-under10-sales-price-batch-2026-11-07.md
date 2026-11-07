# Proposta P2 reduzida após preflight real: quatro Seller SKUs / dois produtos ERP

Fonte: [issue #3788](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3788), auditoria #14 em 07/11/2026 03:52:16 UTC. **Ainda não autoriza qualquer escrita sem revisão formal, grant ativo, preflight e readback.**

## Escopo exato com preço efetivo Amazon comprovado

| Produto ERP | Seller SKUs | Preço efetivo atual | Preço-alvo | Margem estimada no alvo |
|---|---|---:|---:|---:|
| 353697660 | `F12CASTANHOFBA` | R$ 25,02 | R$ 25,28 | 10,01% |
| 341484398 | `ASSLIMFBA`, `TAJ/AS*BR1`, `1C7Q-LKVS-KOPL` | R$ 75,35 | R$ 76,39 | 10,01% |

**Provas P0 observadas no runtime de produção pela rota MCP autorizada:**

1. Amazon Product Fees oficial, **POST de cotação read-only**, com validação SKU/BRL/marketplace/preço: tarefa `0a11b358-33ba-4648-b783-91d8d1b871fd`, `succeeded/exit_code=0`. As quatro cotações retornaram comissão de R$ 2,78 para o SKU a R$ 25,28 e R$ 8,40 para os três SKUs a R$ 76,39.
2. Com custo e logística do snapshot #14 e 14% de imposto, margem projetada **1001 bps** nos quatro, exigindo **nova confirmação de custos, programa, peso, faixas logísticas e fees** imediatamente antes da mudança. Se a tarifa mudar um centavo que elimine o piso, parar e preparar novo grant.
3. Olist/Tiny GET oficial `/listas-precos/991`: `f2993e5f-f5ab-4de2-b2fa-e507394bd1a8`, `succeeded/exit_code=0`; exceções ativas e sem promoção: 353697660 = R$ 25,02; 341484398 = R$ 75,35, coincidindo com o snapshot.
4. Amazon Pricing oficial, **GET de 8 Seller SKUs**: tarefa `da36b7b1-0dca-4254-b20d-0e20cca1c7d7`, `succeeded/exit_code=0`. Os quatro SKUs acima retornaram `EFFECTIVE_OFFER` e preço efetivo esperado.

### Grupo excluído após reproduzir discrepância no Amazon Pricing

ERP **362847505** e seus **quatro SKUs** foram removidos do grant. O GET Pricing oficial constatou `PRICING_OFFERS_EMPTY` para `ABRA-PERF-FBA`, `8F-QXBC-MINT` e `RH-6WBD-NY54`; apenas `FITAPERFAFBA` retornou `EFFECTIVE_OFFER` a R$ 48,59. Alterar a exceção ERP comum sem confirmar as ofertas vivas poderia afetar três anúncios sem preço de venda efetivo verificável. Reconciliar Listings, programas Amazon e eventual sincronização antes de qualquer nova proposta desse produto. **Nenhuma escrita desses quatro SKUs / ERP ID está autorizada pela PR.**

## Gates obrigatórios para aplicação do lote reduzido

- PR `#3790` **Ready for review** e `security/owner-approvals/3790.json` com allowlist restrita a quatro SKU e dois IDs ERP. Apenas review `APPROVED` autenticado de `shopvivaliz-human` para **HEAD exato** e CI/merge queue permitem execução.
- Olist/Tiny v3 documenta `PUT /listas-precos/{idListaDePreco}`, mas a existência do endpoint **não prova** que a operação atualiza somente uma exceção sem substituir demais. Confirmar schema de payload e preservação de exceções/cadastro antes do PUT; não usar `PUT /produtos/{idProduto}/preco` (preço base).
- Confirmar compatibilidade PTD para `purchasable_offer.our_price` por Seller SKU, preço efetivo Amazon Pricing, campos min/max, política de repricing e impacto do preço da lista 991 no site.
- Para os dois IDs ERP, aferir os anúncios BUYABLE atuais relacionados e quaisquer anúncios descobertos após #14. Mudança por ID deve preservar demais produtos, regras e promoções da lista 991.
- Novo quote oficial Amazon Product Fees **no preço autorizado**, logística por peso e faixa, custos e programa validados, margem >=1000 bps, impostos 14%.
- Diário durável e reversível, idempotência, CAS, lease/fencing, proteção de concorrência, rollback/compensação. Não tratar `HTTP 200 ACCEPTED` como preço efetivo atualizado.
- Após gravações autorizadas, comparar GET ERP 991, Amazon Listings e **Amazon Pricing efetivo**, site/catálogo/carrinho/checkout sem compra real, e recalcular auditoria. Sem todas as confirmações, resultado continua PENDENTE.

## Escopo posterior

Auditoria #14: 63 SKUs BUYABLE com margem projetada abaixo de 10%; 4 candidatos desta PR, **59 SKUs fora do escopo** depois da exclusão do ERP 362847505. Dentro do conjunto amplo, 19 dos 29 IDs ERP afetados também estão vinculados a 26 anúncios BUYABLE adicionais fora da lista de risco; modificar preço por produto sem prova poderia alterar outros anúncios. Das 63 margens, 17 apresentam divergência dimensional e cinco têm programa DBA não confirmado. A margem histórica não substitui a projetada.

## Limites

Nenhuma atualização de preço/estoque/custo será executada antes dos gates. Não contornar recusa explícita da ferramenta OpenAI nem 401/403, MFA, quota Amazon 429, regras de branch ou concessões. O grant de mínimo da PR #3780 é diferente de uma alteração de preço de venda.

**Situação: preflight financeiro/API P0 concluído, CI verde antes de alterar HEAD, solicitação de aprovação formal aberta, escrita comercial não iniciada.**