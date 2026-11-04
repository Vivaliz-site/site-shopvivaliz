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

## Vínculos de três integrações Amazon no Olist

- A conta ERP pode conter **três integrações distintas**, chamadas **Amazon**, **Amazon Onsite** e **Amazon Classic**. Estes nomes são canais internos do ERP, não são as modalidades financeiras FBA Onsite/DBA da Amazon. Não assumir FBA Classic por existir a integração chamada Amazon Classic.
- O Seller SKU da Amazon pode ser diferente do SKU e EAN do produto relacionado no ERP. Se EAN ou SKU não confirmar a identidade, buscar prova pelo pedido: Amazon Finances ORDER_ID com apenas um Seller SKU; GET /pedidos?numeroPedidoEcommerce=...; um pedido importado em Amazon/Amazon Onsite/Amazon Classic; GET /pedidos/{id}; exatamente um item e produto ERP ativo; custo confirmado no endpoint oficial.
- Consultar até três pedidos por Seller SKU (90 dias para identidade), rejeitar IDs ERP conflitantes, pedidos com múltiplos Seller SKUs ou linhas ERP, integração diferente ou ID externo divergente. Não persistir números de pedidos/clientes no relatório: somente ERP ID, integração, método de vínculo e quantidade de evidências.
- Os pedidos mais antigos utilizados para identificar produto não entram no cálculo: a margem considera somente remessas financeiras a partir do período de auditoria (30 dias por padrão), com data UTC validada. A taxa DBA `MfnPostageFee` também deve ser limitada à janela financeira ao consolidar despesas, evitando misturar frete de 90 dias com lucro de 30 dias.
- Quando o GTIN do catálogo Amazon divergir do produto relacionado no Olist, explicitar a divergência e usar o vínculo por pedidos verificáveis, sem sobrescrever GTIN nem anúncios.
- Para ofertas sem pedidos ou vínculos conclusivos, apresentar custo não confirmado; isso não implica inexistência de custo no ERP. A interface deve dizer que o **produto relacionado não foi identificado pela auditoria**, nunca que o cadastro do ERP não possui vínculo ou custo. A aba **Anúncios** dos produtos Olist permite confirmar os demais casos pela interface.

## Importação segura de vínculos atuais — Cadastros / Anúncios Olist

A API pública Olist/Tiny v3 fornece produtos, custos e pedidos, porém não fornece uma
operação de leitura equivalente à lista de anúncios vinculados na interface
Cadastros → Anúncios. Por isso, o painel **Auditoria de Margens Amazon** oferece
o controle **Importar vínculos e auditar** para aceitar a exportação oficial atual dos
canais **Amazon**, **Amazon Onsite** e **Amazon Classic**. Esses nomes são contas
do ERP e não identificam as modalidades logísticas FBA Onsite/DBA da Amazon.

Fluxo autorizado, somente leitura comercial:

1. No ERP, exportar os anúncios de cada integração e importar os arquivos
   **originais, sem renomear**, XLS, XLSX ou CSV (máximo cinco arquivos por importação).
   O nome original de exportação deve conter data/hora, interpretadas no fuso de
   São Paulo; um nome renomeado não equivale a prova de exportação recente.
2. O endpoint administrativo POST exige sessão admin, CSRF válido, até 10 MB por
   solicitação, extensão e assinatura do arquivo compatíveis. O processamento
   usa diretório temporário **não público**, exclui arquivos brutos no término e
   produz índice JSON atomicamente com permissões 0640, integridade SHA-256 de
   origem e método de vínculo auditável.
3. O importador aceita somente linhas Olist com Integração, Identificador
   (Seller SKU Amazon) e Produto (SKU). Faz associação **exata**, sem fuzzy title,
   sem substituir EAN e sem usar o preço/custo existente na planilha.
   Divergências de dois produtos para o mesmo Seller SKU são bloqueadas.
4. Apenas exportações com até **48h** (pela data/hora do nome original)
   podem ser associadas automaticamente a produto; ainda assim, o collector
   exige **um único produto ERP ativo** com SKU *exatamente igual*, lido da API
   V3 corrente, e seu histórico real de custo positivo. A janela de 48h limita a
   evidência mas não garante assinatura/autenticidade criptográfica do XLS: importar
   apenas os originais fornecidos pela conta ERP autorizada.
5. Uma importação recente enfileira uma auditoria geral usando a fila existente.
   Importações **históricas** mostram candidatos e fontes, mas **NÃO** disparam
   auditoria nem certificam o custo/margem. O índice é consultado novamente em
   cada execução, com nova verificação de prazo; dados antigos nunca substituem
   vínculos por pedidos reais ou margens já confirmadas.
6. A exportação Excel registra fonte de vínculo, integração ERP e horário da
   exportação. Vínculos históricos permanecem segregados como candidatos não
   certificados; PDF e Excel preservam preços, tributos, comissão, frete DBA,
   custo real da API e eventuais margens pendentes.

**Local privado na Marina:** `/home/ubuntu/.shopvivaliz/runtime/amazon-margin/olist-ads-index.json`.
É **fora** de `/home/ubuntu/shopvivaliz-deploy/current` e do link público
`current/storage`. Não publicar arquivo original nem índice no Git, em URLs
públicas ou em relatórios com dados pessoais. Arquivos importados contêm
somente produtos/anúncios, não pedidos de compradores.

**Dependências do runtime (já provisionadas nesta implantação):**
`/home/ubuntu/.shopvivaliz/venvs/amazon-margin-ads/bin/python` com
`xlrd==2.0.2` e `openpyxl==3.1.5`; pasta privada do índice/incoming com
acesso apenas aos operadores e ao grupo do serviço web. Em reimplantação da
VM, provisionar essas dependências e permissões antes de liberar o importador.

**Prova de não confiabilidade do histórico:** exportações do laptop de
julho/2026 cobrem 537 Seller SKUs históricos, com 76 vínculos únicos para
os 81 anúncios então sem custo, três discrepâncias de codificação e duas
ausências. Dentre 54 vínculos atuais comprovados por pedidos, **17** divergiram
do SKU ERP mostrado nas exportações antigas. Portanto, exportação antiga
**jamais** é fonte suficiente para certificar custo atual ou alterar preços.

Testes direcionados: `OlistAdsImportTest.py` (CSV, XLS legado, XLSX, datas,
conflitos, segurança e escrita atômica), `OlistAdsIndexTest.php` (frescura,
identidade exata e fail-closed), `MarginMathTest.php` (DBA/FBA Onsite), e
Chromium/E2E HTTP real: login administrativo simulado apenas no servidor local
de teste, CSRF exigido, upload histórico sem auditoria duplicada, rejeição de
extensão inválida, índice 0640 fora do site e 459 BUYABLE no painel. Não
autoriza escrever preços no Olist nem na Amazon.

## Resiliência às cotas e referência de custos validados anteriormente

- Olist ERP v3 limita chamadas de leitura **por conta**, compartilhadas entre aplicativos, e fornece os cabeçalhos x-ratelimit-limit, x-ratelimit-remaining e x-ratelimit-reset. A auditoria reserva capacidade para integrações existentes, aguarda o reset indicado e aplica backoff em HTTP 429/503, sem tentar contornar 401/403. O limite é obtido dos cabeçalhos, não fixado no código.
- Uma falha de limite/autorização do provedor (Olist ou Amazon: HTTP 401/403/429/5xx) é sistêmica: abortar a varredura e deixar a fila classificar reprocessamento, em vez de registrar centenas de anúncios como custo ausente. Apenas erros comprovadamente locais a um SKU podem produzir `erp_or_catalog_read_failed` sem interromper todos os outros anúncios.
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
