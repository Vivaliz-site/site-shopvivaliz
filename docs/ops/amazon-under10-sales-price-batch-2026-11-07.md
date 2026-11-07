# Proposta P2: corrigir preco de venda Amazon e ERP 991 — lote inicial de 8 Seller SKUs

Fonte: [issue #3788](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3788), auditoria completa #14 gerada em 07/11/2026 03:52:16 UTC. **Ainda nao autoriza qualquer escrita antes da revisao formal.**

## Escopo exato do lote A

| Produto ERP | Seller SKUs | Preco atual observado | Preco-alvo preliminar | Margem minima |
|---|---|---:|---:|---|
| 362847505 | `ABRA-PERF-FBA`, `FITAPERFAFBA`, `8F-QXBC-MINT`, `RH-6WBD-NY54` | R$ 48,59 | R$ 49,22 | >=10% |
| 353697660 | `F12CASTANHOFBA` | R$ 25,02 | R$ 25,28 | >=10% |
| 341484398 | `ASSLIMFBA`, `TAJ/AS*BR1`, `1C7Q-LKVS-KOPL` | R$ 75,35 | R$ 76,39 | >=10% |

Proposta inicial = **8 Seller SKUs / 3 IDs ERP**; o snapshot nao apontou outros SKUs Amazon BUYABLE nesses tres IDs. Nao representa resultado de leitura Amazon/Olist em tempo real. O target foi obtido por `ceil((custo_conjunto + logistica + WMS)/(1 - imposto14% - margem10% - comissao_atual/preco_atual))` em centavos, com o maior target entre anuncios do mesmo produto. E **apenas estimativa preliminar**; taxa da Amazon e tarifa podem mudar ao novo preco. Na aplicacao, validar a cotacao oficial `ProductFees` ao target e repetir calculo; se nao alcançar >=10% com todas as taxas/tarifas oficiais, **nao aplicar**, preparar novo target e nova revisao no HEAD exato.

## Condicoes de seguranca, integridade e preflight antes de qualquer escrita

1. Exigir grant comercial `security/owner-approvals/<PR>.json` aprovado no HEAD exato pela conta `shopvivaliz-human`, valido por prazo definido, CI e merge queue oficiais. O chat sozinho nao substitui a revisao GitHub.
2. Confirmar pelo ERP Olist/Tiny v3 o vinculo exato, GTIN, kit/custo de unidade comercial e precos efetivos da tabela **991**, sem usar base de cadastro como fallback. Conferir promocoes, repricer, maximo, minimo e precos efetivos na Amazon SP-API Pricing. Se qualquer fonte contradiz a auditoria #14, interromper esse SKU.
3. Confirmar pela API/documentacao oficial a **operacao de escrita de excecao na lista ERP 991**, nao usar endpoint de atualizacao de preco base. A lista 991 tambem alimenta site, catalogo, carrinho e checkout: validar todos e interromper se houver impacto fora dos 3 IDs autorizados.
4. Confirmar Amazon PTD, `ProductFees` ao novo preco (SKU, BRL, marketplace), frete FBA Onsite/DBA por peso e faixa, imposto 14%, margem atual >=10%. Parar se 429/403/401 ou outra recusa, sem inventar comissao nem contornar recusa por outro canal.
5. Aplicar usando lease e fencing, journal/receipts persistentes, idempotencia e compensacao/rollback; alterar somente preco de venda nos 8 Seller SKUs e excecoes dos 3 IDs na lista 991; manter estoque, custo, programas, outras ofertas e outros produtos inalterados. Nunca repetir PATCH Amazon so porque aceitou sem readback.
6. Conferir GET Olist, Amazon Listings, **Amazon Pricing efetivo** e site apos propagacao; executar nova auditoria de margens e E2E grafico autenticado. Nao declarar concluido se a oferta continuar exibindo o preco antigo.

## Demais 55 Seller SKUs

Fora do escopo desse grant. A auditoria identificou 63 BUYABLE abaixo de 10%; 55 nao cabem nesse primeiro lote por impacto em mais produtos/anuncios, fonte de medidas ou programa ainda nao verificados. Dos 29 IDs ERP afetados, 19 tambem compartilham **26 anuncios BUYABLE adicionais fora do grupo abaixo de 10%**; a atualizacao de excecao por produto pode alterar precos de terceiros. Exige reconciliacao SKU por SKU e outra autorizacao exata; nao assumir que a planilha ERP e exclusiva do Seller SKU.

## Referencias

- `docs/knowledge/pricing-integrity.md`: tabela Amazon 991 e integridade do preco do site
- `docs/ops/amazon-margin-audit.md`: calculo da margem projetada, Product Fees, FBA Onsite/DBA
- `security/owner-approvals/3780.json`: grant **distinto**, somente para minimum_seller_allowed_price, que NAO autoriza alterar preco de venda
- [Amazon SP-API Purchasable Offer](https://developer-docs.amazon.com/sp-api/docs/manage-purchasable-offer)
- [Olist listas de precos — regra geral e excecao por produto](https://ajuda.olist.com/precificacao/listas-de-precos)

**Estado da proposta:** PREPARADA / COMERCIAL_WRITE_DISABLED ate formal review, preflight oficial e verificacao de capacidade de escrita ERP 991.
