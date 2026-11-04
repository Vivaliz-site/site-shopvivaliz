# ShopVivaliz — Auditoria de Margens Amazon (implantação por fases)

**Branch de implementação:** `feat/amazon-margin-audit-mvp-20261103`  
**Estado desta fase:** leitura e relatórios; **nenhuma escrita de preço** na Amazon, Olist/Tiny ou site.

## Fontes e identidade

- Amazon Listings `searchListingsItems`: **uma linha por Seller SKU** (não agrupar a unidade de análise por ASIN).
- Amazon Pricing `getPricing`: preço efetivamente anunciado e canal `AMAZON` versus `MERCHANT`.
- Amazon Catalog `getCatalogItem`: EAN por ASIN.
- Olist/Tiny `GET /produtos`: índice de EAN; exigir exatamente **um produto ativo** associado ao EAN. Marcar ambiguidade em vez de usar custo de duplicado/excluído.
- Olist/Tiny `GET /produtos/{id}` e `GET /produtos/{id}/custos`: **último custo positivo registrado no histórico**, com a data do evento. Não inferir custo zero, não substituir por custo de anúncio.
- Amazon Finances `listTransactions`: venda e taxas reais por Seller SKU, `quantityShipped`; `MfnPostageFee` é debitada em `ServiceFee` separada.
- Amazon Orders `getOrder`: confirmar `programs=DELIVERY_BY_AMAZON` para anúncios MFN/DBA.

## Fórmula de margem confirmada

```text
receita_liquida_transacao = item.totalAmount (FINANCES/Shipment)
postagem_DBA = soma ServiceFee MfnPostageFee associada ao ORDER_ID (negativa)
impostos = round(preco_unitario * quantidade * aliquota, 2)
lucro = receita_liquida_transacao + postagem_DBA - custo_unitario * quantidade - impostos
margem = lucro / (preco_unitario * quantidade)
```

O `item.totalAmount` **já inclui** comissão, tecnologia, logística FBA, frete do cliente e reembolsos/ajustes de frete incluídos no próprio lançamento. Não deduzir essas linhas novamente.

- **FBA Onsite:** `item.totalAmount` menos custo e imposto; nenhum FBA clássico como fonte de tarifa.
- **DBA:** somente confirmar quando o pedido indicar `DELIVERY_BY_AMAZON`, houver `MfnPostageFee` conciliada e a atribuição ao Seller SKU for inequívoca. Se não houver tarifa conciliada, manter `pending_postage` / `partial`; não assumir que ausência de débito significa frete grátis.
- **Pedidos de múltiplas unidades:** total financeiro e tributos calculados por quantidade, arredondamento tributário sobre total da venda.
- **Devoluções:** não agregadas automaticamente à margem de venda unitária nesta fase. Conciliação de reembolsos, frete reverso, campanhas e demais custos fora do lançamento serão adicionados em etapa de rentabilidade completa.
- Status `partial` ou `unverified` não pode ser anunciado como margem certificada. Imposto inicial é 14%, variável `SV_AMAZON_MARGIN_TAX_BPS=1400`.

## Indicador de anúncio ativo e campos sem valor

- **Ativo** é definido pelo estado **BUYABLE** retornado em `summaries[].status` da Amazon Listings. `DISCOVERABLE` sem `BUYABLE` não é uma oferta comprável. O painel abre com **Somente ativos** habilitado; pode ser desmarcado para auditar visualmente todos os registros.
- O filtro da tela limita os indicadores e as exportações PDF/XLSX (quando não há seleção, exporta apenas os registros filtrados). O botão **Auditar todos** continua solicitando auditoria do **catálogo inteiro**, inclusive não compráveis; para auditar só ativos, selecionar os registros filtrados e escolher **Auditar selecionados**.
- A contagem da primeira auditoria completa de 04/11/2026 mostrou 460 ativos dentre 527 anúncios; essa proporção é histórica e **não é fixa no código**.
- Uma oferta sem `price` da Pricing API pode ter `listed_price` cadastrado em Listings. Apresentar somente como **preço cadastral de referência**, nunca como preço efetivo elegível para cálculo de lucro/margem ou publicação.
- Vendas ausentes no período: custo e preço podem estar disponíveis, mas margem permanece **não apurada**. Não inferir margem 0% nem preencher taxas com estimativas não rotuladas.
- Um SKU sem EAN Amazon só será vinculado automaticamente ao ERP quando houver **uma correspondência exata e única de Seller SKU a produto ativo**. Com EAN presente, nunca ignorar divergência de GTIN ou selecionar produto ambíguo. O campo `erp_match_method` torna a origem do vínculo auditável.
- Campos vazios de custo para anúncios ativos significam **vínculo não identificado, API não disponível ou custo ainda sem histórico válido**. A ligação de anúncios ERP pode ser consultada via interface do ERP; sua API pública v3 não expõe leitura de vínculo por anúncio. Não tentar adivinhar pela semelhança dos títulos.

## Resiliência às cotas e referência de custos validados anteriormente

- Olist ERP v3 limita chamadas de leitura **por conta**, compartilhadas entre aplicativos, e fornece os cabeçalhos x-ratelimit-limit, x-ratelimit-remaining e x-ratelimit-reset. A auditoria reserva capacidade para integrações existentes, aguarda o reset indicado e aplica backoff em HTTP 429/503, sem tentar contornar 401/403. O limite é obtido dos cabeçalhos, não fixado no código.
- Um erro temporário na API de histórico de custos não significa "custo zero". O collector preserva o status erp_or_catalog_read_failed quando a API não consegue confirmar o histórico. A fila adia até 20 minutos em caso de 429, sem consumir tentativas de falha lógica.
- **Somente apresentação:** quando o relatório atual apresenta falha de leitura, o painel/PDF/Excel podem exibir em campo separado o **último custo anteriormente verificado** em snapshot dos 48h anteriores. A referência requer identidade exata de Seller SKU, ASIN e EAN, ID ERP positivo, custo positivo originado de OLIST_TINY_V3_PRODUCT_COST_HISTORY, data e número da auditoria anterior. Não atualizar custo atual, margem, estado de conciliação nem lucro com a referência.
- **Comprovação de 04/11/2026:** no run #4, 149 anúncios BUYABLE não tiveram custo confirmado; 132 eram unmapped_erp, 14 falharam em leitura, dois eram ambíguos e um teve divergência de identidade. Treze dos 14 com erro tinham histórico válido no run #3 e foram novamente consultados com sucesso por API read-only. O 14º não tinha referência anterior. O custo histórico dos 13 deve ser rotulado **não confirmado na execução atual**; isso não equivale a margens certificadas.
- No snapshot de recuperação de 26/10, vários EANs de anúncios BUYABLE só aparecem em produtos ERP **excluídos (situacao=E)** ou em múltiplos cadastros excluídos. A API oficial não garante o vínculo anúncio-produto apenas pelo EAN nessas situações. Não preencher custos usando produtos excluídos ou títulos similares sem prova adicional da aba "Anúncios" no ERP.

## Arquitetura

| Componente | Caminho |
|---|---|
| Contabilidade financeira pura | `includes/amazon-margin/MarginMath.php` |
| Coletor read-only | `includes/amazon-margin/AuditCollector.php` |
| Fila, snapshots MySQL | `includes/amazon-margin/AuditStore.php` |
| Exportações reais PDF/XLSX | `includes/amazon-margin/ReportExport.php` |
| Coleta CLI | `scripts/amazon-margin/run.php` |
| Painel Admin | `/admin/amazon-margin.php` |
| API autenticada | `/api/admin/amazon-margin.php` |
| Serviço/timer propostos | `ops/systemd/shopvivaliz-amazon-margin-audit.*` |

Apenas a CLI pode chamar as APIs comerciais externas. A interface consulta o snapshot do banco, sem expor credenciais nem dados de compradores. As tabelas `amazon_margin_audit_requests` e `amazon_margin_audit_runs` são criadas pela rotina controlada de migração/primeira execução da CLI. Solicitações da interface entram em fila, nunca disparam chamadas externas longas dentro de uma requisição HTTP.

## Uso e validação

Somente leitura real (sem criação de tabelas e sem gravação de preços):

```bash
php tests/amazon-margin/MarginMathTest.php
php tests/amazon-margin/ReportExportTest.php
php scripts/amazon-margin/run.php --skus=armastrafba2,ASSLIMFBA,Armastradba,1C7Q-LKVS-KOPL --dry-run
```

Execução com snapshot persistido, depois de aplicação das migrations e validação do deploy:

```bash
php scripts/amazon-margin/run.php --all
php scripts/amazon-margin/run.php --skus=ASSLIMFBA,Armastradba
php scripts/amazon-margin/run.php --next
```

Importante: `--all` pagina todos os anúncios Amazon e produtos ERP. A primeira execução pode consumir várias chamadas API e deve ocorrer no worker. Em dados comerciais não auditados, nunca usar preço ou custo do cache como se estivesse confirmado.

### Timer de jobs

Após merge, deploy validado e verificação da identidade `ubuntu` na Marina:

```bash
sudo install -m 0644 ops/systemd/shopvivaliz-amazon-margin-audit.service /etc/systemd/system/
sudo install -m 0644 ops/systemd/shopvivaliz-amazon-margin-audit.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-amazon-margin-audit.timer
```

O worker usa `GET_LOCK` do MySQL para impedir execução concorrente. Se uma tarefa falhar, será marcada como `failed`; ela nunca publicará preço.

## Edição de preços: etapa futura, bloqueada por padrão

A documentação canônica de preços `docs/knowledge/pricing-integrity.md` estabelece que o preço publicado no site **também** é governado pela tabela Amazon ERP n.º 991. Um produto pode ter vários anúncios distintos, enquanto essa lista mantém uma exceção de preço por ID de produto. Por isso:

1. Confirmar na API oficial do ERP como persistir **preço individual do anúncio**, caso seja suportado; não usar endpoints internos não documentados nem sobrescrever cadastro base.
2. Criar um serviço comercial próprio, com permissão explícita, prévia de alterações em **todos** os anúncios afetados e impacto no site.
3. Amazon: `PATCH /listings/2021-08-01/items/{sellerId}/{sku}` via `purchasable_offer`, com schema Product Type Definitions, validação prévia e readback de preço efetivo. Respeitar precificação automática e propagação assíncrona.
4. ERP: efetuar mudança autorizada na lista ou anúncio com versão prévia, journal, idempotência, CAS/fencing e possibilidade de compensação segura após falha parcial.
5. Nunca alterar outros SKUs por implicação silenciosa, nem declarar sucesso antes de verificar Amazon, ERP e site.

**Nesta fase o painel contém simulação rotulada como hipotética, sem botão de publicação real.**
