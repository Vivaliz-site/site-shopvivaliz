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
receita_bruta_transacao = ProductCharges + Shipping + PromoRebates (FINANCES/Shipment; cada tipo somente no nivel superior)
receita_liquida_transacao = item.totalAmount (FINANCES/Shipment)
postagem_DBA = soma ServiceFee MfnPostageFee associada ao ORDER_ID (negativa)
impostos = round(receita_bruta_transacao * aliquota, 2)
lucro = receita_liquida_transacao + postagem_DBA - custo_unitario * quantidade - impostos
margem = lucro / receita_bruta_transacao
```

O `item.totalAmount` **já inclui** comissão, tecnologia, logística FBA, frete do cliente e reembolsos/ajustes de frete incluídos no próprio lançamento. Não deduzir essas linhas novamente.

### Conciliação DBA por pedido com múltiplas remessas (06/11/2026)

**RED real da API Amazon e snapshot #12:** 15 SKUs DBA BUYABLE continuam `partial`. Os lançamentos Finances 30 dias possuem 38 `MfnPostageFee`, sem pedidos com Seller SKUs mistos nesses casos; **10 dos 15** tinham 100% de seus pedidos com `MfnPostageFee` presente e programas DBA já aceitos na auditoria, mas cada pedido foi dividido em duas ou mais linhas `Shipment`. O coletor debitava o frete uma única vez na primeira linha e marcava artificialmente as linhas seguintes como `pending_sales`, por isso tratava remessas conciliáveis como parciais. Não somar dois fretes e não tratar ausência de frete como frete zero.

**Correção P1 preparatória:** agrupar somente as remessas **do período financeiro** por `ORDER_ID` quando o modo atual é DBA; sem ID, linhas permanecem isoladas. Somar quantidades, repasses e bases brutas em **centavos**, aplicar o `MfnPostageFee` do pedido uma única vez e considerar as remessas confirmadas somente se a Amazon Orders confirmar `DELIVERY_BY_AMAZON`, `order_skus` indicar exatamente um Seller SKU e a tarifa existir. Preservar `confirmed_sales` como contagem das linhas Shipment originais e `confirmed_units` como unidades físicas. Descartar certificação se qualquer linha financeira do grupo não puder ser conciliada. Os 5 SKUs com pedidos **sem** postagem continuam parciais até lançamento real; não estimar postagem.

Arquivo `includes/amazon-margin/DbaOrderGroup.php`; regressão `tests/amazon-margin/DbaOrderGroupTest.php`. Exigir nova auditoria completa, teste real por grupo e validação UI/PDF/XLSX **somente após merge/deploy autorizado**, sem alterar preços/custos. O relatório #12 permanece imutável.

### Retificação financeira P1 — valor efetivamente vendido versus preço vigente (06/11/2026)

**Defeito RED reproduzido com a conta Amazon real:** em 547 itens Shipment do período recente, 54 tinham `OurPricePrincipal` por unidade diferente do preço **atual** da Listings/Pricing. Casos: `R9-O6PX-YBXA` teve venda por R$ 112,97 enquanto o anúncio mostrava R$ 159,00; `C08BR` apresentou R$ 1.417,79 na transação versus R$ 1.549,00 no cadastro atual; `TTO-BR-FBA` teve R$ 39,00 versus R$ 42,00. Aplicar 14% de imposto sobre o **preço atual** e dividir o lucro pelo mesmo valor produzia margem histórica enganosa, mesmo com repasse Amazon correto.

**Novo contrato:** para cada linha Shipment, calcular em centavos a receita bruta **da transação original** a partir dos valores de topo `ProductCharges + Shipping + PromoRebates`. Frete cobrado ao comprador compõe a base; frete promocional subsidiado normalmente cancela a respectiva cobrança (não duplicar); `AmazonFees` é abatida uma única vez porque já está em `item.totalAmount`. A taxa DBA `MfnPostageFee` permanece separada e exige associação inequívoca por pedido. Aceitar somente BRL e valores positivos conciliados, com `sum(breakdowns de topo) == item.totalAmount` (tolerância máxima 1 centavo). Tipo de breakdown desconhecido, moeda divergente, soma inconsistente ou receita não confirmada tornam a **venda pendente** em vez de usar o preço atual como substituto.

A `price` do SKU continua sendo **preço vigente do anúncio** e não serve de base de imposto/margem histórica. O coletor grava `realized_sale_gross_cents`, `realized_unit_price` e proveniência para linhas confirmadas ou parcialmente conciliadas; painel, PDF e XLSX distinguem o preço atual da **receita bruta média por unidade**, que também pode incluir frete pago pelo comprador líquido de promoções. Não rotular esse valor bruto como preço do produto — por exemplo, no DBA, preço do produto de R$78,99 mais frete de R$23,59 produz base bruta de R$102,58 sem que o preço unitário tenha subido. Se não houve vendas financeiramente conciliadas, **a margem realizada não existe**; projeções futuras precisam de estimativa de taxas separada, não passar por confirmadas. Testes obrigatórios `MarginMathTest.php`, `ReportExportTest.php` e regressões DBA/FBA/kit, seguidos de **novo run completo e E2E UI real**. O snapshot histórico #11 é imutável, mas suas margens antigas não podem ser republicadas como valores atuais pós-correção.


- **FBA Onsite:** `item.totalAmount` menos custo e imposto; nenhum FBA clássico como fonte de tarifa.
- **DBA:** somente confirmar quando o pedido indicar `DELIVERY_BY_AMAZON`, houver `MfnPostageFee` conciliada e a atribuição ao Seller SKU for inequívoca. Se não houver tarifa conciliada, manter `pending_postage` / `partial`; não assumir que ausência de débito significa frete grátis.
- **Pedidos de múltiplas unidades:** total financeiro e tributos calculados por quantidade, arredondamento tributário sobre total da venda.
- **Devoluções:** não agregadas automaticamente à margem de venda unitária nesta fase. Conciliação de reembolsos, frete reverso, campanhas e demais custos fora do lançamento serão adicionados em etapa de rentabilidade completa.
- Status `partial` ou `unverified` não pode ser anunciado como margem certificada. Imposto inicial é 14%, variável `SV_AMAZON_MARGIN_TAX_BPS=1400`.

## Custo por KIT e distinção entre lucro unitário/acumulado (06/11/2026)

**Defeito reproduzido:** a auditoria anterior utilizava `precoCusto` de um
produto ERP **simples** como custo de uma venda Amazon composta por várias
peças, produzindo margens artificialmente altas. Exemplo validado nas telas
reais Amazon/Olist e no run #8: o Seller SKU `KIT5ROD35CFREIOFBA` anuncia
**5** rodízios, mas estava associado no Olist a `1C7Q-LKUU-0BA3`
(`tipo=S`, custo **R$ 3,01 por peça**), de modo que o custo correto da
**unidade comercial Amazon** é **5 × R$ 3,01 = R$ 15,05**. O ERP também
possui a mesma referência como kit próprio `tipo=K` a **R$ 15,05**.

Outras verificações independentes: `ROD6FBA` e `IA-YWLG-2L3K`
anunciam **6** unidades, produto simples Olist custa R$ 4,03; custo
do conjunto **R$ 24,18** (há cadastro tipo K com esse custo).
`KITVEDFBA` anuncia **2** vedantes, produto simples custa R$ 10;
custo total **R$ 20** (há kit tipo K com esse custo).
`FN-WXV1-QWDV` anuncia **4** rodízios, custo simples R$ 10, conjunto
R$ 40 (há kit tipo K com esse custo).

**Regra obrigatória por Seller SKU (não consolidar por ASIN):**

1. `titulo_anuncio` na Amazon determina **quantidade explícita** de
   peças por conjunto: "Kit 5...", "Kit com 4...", "6 UN" etc.
   Não interpretar milímetros, centímetros, dimensões, preço ou números
   apenas presentes no Seller SKU como quantidade.
2. Consultar `GET /produtos/{id}` (API Olist v3) para distinguir
   `tipo=K` (custo do kit completo) de `tipo=S` (produto simples).
   Se `tipo=K`, a composição está no campo `kit[].produto/quantidade`;
   o histórico do preço de custo já se refere a **uma unidade do kit**
   e não pode ser multiplicado novamente. **Antes de certificar** esse
   custo histórico, buscar os custos positivos atuais de **cada componente**
   por ID Olist V3 e comparar `soma(custo_unitario × quantidade)`.
   Se a soma não corresponder ao custo registrado do kit, expor os dois
   valores e manter a margem **não certificada**; não optar
   automaticamente pelo menor custo, pois podem existir frete,
   embalagens ou taxas de aquisição não registrados na composição.
3. Se `tipo=S` e o produto do ERP representa **uma peça**, mas o
   anúncio contém N peças idênticas, usar
   `custo_anuncio = arredondar_centavos(custo_peca × N)`. O valor
   do custo consultado no ERP fica preservado em `erp_base_cost`;
   o custo da unidade comercial vai para `last_cost`.
4. Se o produto ERP já representa um conjunto com a mesma quantidade,
   **não multiplicar duas vezes**. Em caso de quantidades conflitantes,
   peças heterogêneas sem ficha de composição ou tipo não verificado,
   sinalizar `kit_cost_unverified` e deixar **custo, lucro e margem
   não certificados**. Não preencher valores por estimativa de título.
5. Quantidade de kits vendidos é `confirmed_units` obtida da Amazon
   Finances (pode ser maior que `confirmed_sales` em pedidos de múltiplas
   unidades). Fórmula de custo do período:
   `COGS = custo_anuncio × confirmed_units`.
   **Lucro unitário médio** =
   `profit_cents / confirmed_units`.
   **Lucro acumulado** = `profit_cents / 100`.
   Nunca confrontar lucro acumulado de muitas vendas com preço de uma peça.
6. Conservar o cálculo das taxas FBA Onsite/DBA, dedução de
   `MfnPostageFee` por pedido e impostos de 14% sem cobrança duplicada.
   O resultado continua parcial quando houver transações pendentes.

**Achado real 06/11/2026, novo caso irmão da P1:** os produtos K
`KIT-RODIZIOS-50MM-GEL` e `KIT-RODIZIOS-SOPRANO-50MM-FREIO`
têm **exatamente os mesmos componentes do ERP** (ID 344671209, 2 peças
a R$ 4,03; ID 344670767, 2 peças a R$ 3,44), cuja soma corresponde a
R$ 14,94. O primeiro possui **custo de kit registrado de R$ 33,08**,
enquanto o segundo registra R$ 14,94. Essa divergência
de **R$ 18,14** não pode ser ocultada nem convertida em margem
certificada sem reconciliar os encargos ou corrigir a origem no ERP.
O sistema registra `erp_kit_bom_cost`,
`erp_base_cost` (custo histórico) e `kit_cost_issue`;
no relatório financeiro mantém custo/margem **nulos** e a evidência
dos valores divergentes. Nenhuma escrita comercial é feita.

Testes: `KitBomCostTest.php`, `KitCostTest.php`, `KitCostReportTest.php`,
`MarginMathTest.php`, `ReportExportTest.php` e regressões de
vínculo ERP. Exigir rerun read-only na produção e validação real do
painel/PDF/XLSX antes de divulgar nova margem certificada. Relatório
anterior #8 permanece histórico, **não deve ser tratado como margem
atualizada para kits**. Nenhuma escrita de preços da Amazon/ERP é
realizada por este patch.

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

1. Apenas nas integrações que disponibilizam exportação de anúncios no ERP,
   exportar e importar os arquivos **originais, sem renomear**, XLS, XLSX ou CSV
   (máximo cinco por importação). A integração **Amazon FBA Classic não permite
   importar ou exportar anúncios no Olist**; conferir seus vínculos pelo
   produto → aba **Anúncios** e pelos pedidos importados. Para **Amazon Onsite**,
   verificar a opção de exportação na conta antes de solicitá-la. Referência:
   https://ajuda.olist.com/pt_BR/amazon/como-integrar-o-erp-com-a-amazon-fba-classic
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

## Evidência independente pela interface autenticada Olist (05/11/2026)

A tela de anúncios ERP já autenticada pode ser lida **sem alterar anúncios, preços ou
cookies**. Essa rota é independente da importação oficial XLS/XLSX/CSV acima:
**nunca** classificar captura da UI como `OLIST_ERP_ANUNCIOS_EXPORT`.
O serviço de devoluções utiliza um perfil persistente Playwright na Marina;
o agente deve abrir **somente uma aba temporária própria** e fechá-la após
a coleta, sem interromper o serviço, autenticação nem páginas existentes.

A captura privada usa `OLIST_ERP_AUTHENTICATED_UI_CAPTURE` e preserva
`seller_sku` exato, SKU do produto relacionado, integração ERP, instante UTC,
total/paginação e prova de captura gráfica de cada página. A rotina deve
recusar páginas parciais, registros duplicados, produtos não relacionados e
integrações não verificadas. **Preço exibido nessa UI não é origem para custo
nem margem.** Arquivo canônico privado:
`/home/ubuntu/.shopvivaliz/runtime/amazon-margin/olist-ui-capture.json`.
Evidências em `.../amazon-margin/evidence/olist-<integracao>-p<PAGINA>-<YYYYMMDD>.png`.
Não colocar índice, imagens, compradores, credenciais ou cookies no Git ou no
`current/storage` público.

O consumidor `OlistUiCapture.php` verifica integridade, privacidade,
assinatura PNG, hashes SHA-256, paginação, unicidade, timestamp e prazo de
até 48h. Busca o **produto ativo e custo positivo diretamente na API oficial
Olist v3, SKU ERP exato e único**. Nunca usa preço da captura.
Quando a relação **atual** da UI diverge de EAN/pedido anterior, a linha
fica `ambiguous_erp` com `erp_ui_conflict`, custo e margem **nulos** até
reconciliação manual/independente; nunca aceita automaticamente o histórico.
Se a captura tiver vencido, ela vira referência, não fonte de custo corrente;
divergência histórica ainda não reconciliada não restaura certificação.
Relatórios PDF/XLSX e tela exibem a origem e a divergência explicitamente.
Nenhuma escrita comercial é permitida neste módulo.

**Escopo observado em 05/11/2026:** canal ERP Amazon com 192 anúncios
(50+50+50+42), cruzamento exato com run Amazon #7 encontra 27 dos 80
BUYABLE sem custo e oito diferenças contra produto ERP antes certificado,
sendo três com margens parcialmente calculadas. Outros 53 continuam
sem prova nessa integração. Canais ERP `Amazon Onsite` e `Amazon Classic`
precisam de evidência atual independente; nomes ERP não significam
logística FBA clássica. Sem cobertura e UI/E2E reais do módulo publicado,
o veredito de projeto permanece `NAO_APTO` até certificador V5 oficial.

Testes mínimos: `OlistUiCaptureTest.php`,
`OlistUiReportConflictTest.php`, `OlistAmazonOrderLinkTest.php`,
`FinanceFeeWindowTest.php`, `MarginMathTest.php`,
`ReportExportTest.php` e prova de execução real na UI autenticada,
seguida de reload/readback da auditoria publicada.

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


## Vínculo independente por aba Anúncios dos produtos Olist (06/11/2026)

A conta Olist possui **três integrações Amazon separadas**, comprovadas nas opções
da UI autenticada: Amazon `27473`, Amazon Onsite `27478` e Amazon Classic
`27482`. A aba **Cadastros → Produtos → editar produto → Anúncios** contém,
para cada produto do ERP, entradas `mapeamentoProdutosEcommerce[n]` com
`idProdutoEcommerce` (Seller SKU), `idEcommerce`, `situacao`, `status` e
identificador do vínculo. Essa evidência é **diferente** da tela `/anuncios`,
de um arquivo XLS exportado e dos preços/custos retornados pela API.

O método de aquisição é somente leitura comercial: obter o conjunto integral de
**produtos ativos na API oficial Olist V3**, abrir individualmente cada formulário
na sessão autenticada existente, conferir `input[name=id]`, inspecionar os campos
de anúncio sem clicar em `salvar` e registrar capturas de tela da aba Anúncios
para todos os produtos com anúncios-alvo anteriormente não identificados.
Em 06/11/2026, a API informou **202 produtos ativos**, em três páginas de
100, 100 e 2 produtos. Trata-se de contagem observada, não parâmetro fixo.

O coletor de evidência está em
`scripts/amazon-margin/capture-olist-product-links.js`; aceita lotes
sequenciais de até 25 produtos e grava checkpoints privados em
`/home/ubuntu/.shopvivaliz/runtime/amazon-margin/`. É necessário executar
o browser no contexto autorizado da Marina e da sessão Olist já autenticada.
Pode receber os caminhos de entrada/saída através das variáveis
`SV_AMAZON_OLIST_PRODUCT_TABS_INPUT` e
`SV_AMAZON_OLIST_PRODUCT_TABS_OUTPUT`. Não guardar credenciais nos arquivos
nem incluir HTML bruto, PII ou custos da tela em relatório.

O `SvAmazonMarginOlistProductTabCapture` só lê o índice canônico
`olist-product-links-current.json` depois de comprovar integralidade
(`records` para **todos** os produtos ativos, sem falhas), modo de arquivo
privado, `source=OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI`, Seller SKU exato,
integração e vínculo ativos, screenshot PNG privativo e hash SHA-256. A prova
expira após 48 horas. Vários produtos Olist reivindicando um mesmo Seller SKU
são ambiguidade, **não uma escolha por título**. O ID e o SKU de destino devem
ser idênticos aos da API V3 corrente, com produto ativo e custo do histórico
oficial, antes de qualquer margem ser certificada. Divergência frente a
outro vínculo confirmado por pedido, EAN, exportação ou UI implica suspensão
da certificação até conciliação; não sobrescrever o dado anterior.

Nenhum preço, vínculo, imposto, custo oficial ou anúncio Amazon/Olist é
alterado durante a captura ou a importação. Os demais gates de kit, BOM,
FBA Onsite e frete DBA permanecem obrigatórios. A auditoria da interface
e das exportações PDF/XLSX continua necessária após qualquer deploy.


## Margem comercial PROJETADA no preco vigente (decisao do proprietario em 06/11/2026)

A margem da lista de risco **abaixo de 9%** e a **margem atual estimada** da oferta **por Seller SKU**, sem exigir venda anterior. A margem **realizada**, calculada sobre valores transacionais efetivos e conciliados, continua preservada em campo proprio e nunca e misturada com a projecao.

- **Preco:** oferta BUYABLE vigente na Amazon Pricing (nao usar o preco de venda antiga nem o preco cadastral se Pricing nao confirmou).
- **Custo:** ultimo custo ERP Olist V3 positivo do produto ativo vinculado ao Seller SKU, referente ao kit comercial completo e com multiplicador e BOM validados.
- **Comissao:** cotacao de comissao pela Amazon Product Fees API com Seller SKU e **preco atual**, conferindo SKU, BRL, marketplace e preco. A API recebeu HTTP 429 com lote de 20 em 06/11; probe read-only de 1 e 5 SKUs retornou HTTP 200. O auditor limita lotes a 5 e faz no maximo 3 tentativas adicionais com espera limitada em 429; nao estima comissao se a quota continuar indisponivel. Utilizar ReferralFee e taxas de venda reconhecidas, sem descontar FBAFees do FBA classico como se fossem tarifas do Onsite.
- **Peso/volume:** obter item_package_weight / item_package_dimensions da Amazon Catalog ou dimensoes.pesoBruto / comprimento / largura / altura do produto validado Olist. Peso cubico (kg) = comprimento x largura x altura (cm) / 6000; peso tarifavel = maior entre peso real e cubico, acrescido de 20 gramas de embalagem. Diferencas entre as duas fontes sao sinalizadas.
- **Logistica:** tarifas oficiais FBA Onsite e DBA vigentes por faixa de preco/peso; WMS Onsite = R$ 0 na tabela oficial. DBA acima de R$ 200 depende da regiao de origem real, configurada por SV_AMAZON_DBA_ORIGIN_REGION (sp_capital, se_capital, se_interior, other). Sem origem identificada, margem pendente.
- **DBA sem venda:** o canal MERCHANT pode ser projetado como DBA candidato, marcado explicitamente estimated_program_unverified; nao afirma que o pedido foi conciliado. Programa desconhecido permanece pendente.
- **Limite do filtro de risco solicitado:** margem atual projetada abaixo de **9% (900 bps)** aparece na listagem; exatamente 9,00% nao entra. Esse limite de visualizacao nao altera a regra separada de 10% do PromoFloorGuard para promocoes. Margem projetada nao significa rentabilidade financeira historica certificada; pendencias sem cotacao confiavel nunca recebem margem inventada.
- **Imposto:** SV_AMAZON_MARGIN_TAX_BPS (padrao 1400 = 14%) aplicado ao **preco vigente**.
- **Formula por unidade comercial:** lucro atual = preco vigente - custo completo do kit - comissao cotada - logistica aplicavel - imposto sobre preco vigente - WMS; margem atual (%) = lucro atual / preco vigente x 100.
- **Dados ausentes:** nao inventar custo, peso, comissao, regiao nem lucro. Manter margem atual nula ate haver fonte confiavel, com causa expressa.
- **Relatorios:** painel tem filtro Margem atual estimada abaixo de 9% e separa Margem historica; Excel inclui preco, custo, comissao, logistica, imposto, peso real/cubico/tarifavel, status e origem; PDF exibe margem atual.
- **Escopo:** margens projetadas nao incluem publicidade, devolucoes e outras despesas extraordinarias nao fornecidas. Nao apresentar como liquido financeiro total certificado.

Fontes: FBA Onsite https://sellercentral.amazon.com.br/gp/help/external/GSCSJT23SEAKNXAN ; DBA https://venda.amazon.com.br/cresca/dba ; comissoes https://venda.amazon.com.br/precos ; cotacao https://developer-docs.amazon.com/sp-api/reference/getmyfeesestimates .

A promocao especial de 50% nas tarifas logisticas (01/06 ate 30/09/2026) **nao e aplicada automaticamente** em novembro de 2026. Mudancas so em leitura, sem alterar precos ou estoque. Gates finais exigem auditoria completa real e UI autenticada.

## Certificação de preço efetivo — resposta Pricing sem ofertas (07/11/2026)

O endpoint oficial Amazon `GET /products/pricing/v0/price` pode responder HTTP 200 com item `status=Success` e `Product.Offers=[]`. **Isso não certifica um preço de venda vigente**, mesmo quando a API Listings registra `purchasable_offer` e preço mínimo corretos após um PATCH aceito.

O coletor diferencia os estados de leitura sem realizar nenhuma escrita:

- `EFFECTIVE_OFFER`: existe oferta com `BuyingPrice.ListingPrice.Amount` positivo e finito; somente aqui `price_source=AMAZON_SPAPI_PRICING`.
- `PRICING_OFFERS_EMPTY`: resposta bem-sucedida sem oferta; `price=null`, `price_source=UNVERIFIED`. Em anúncio `BUYABLE`, adiciona alerta `buyable_pricing_offers_empty:SKU`.
- `INVALID_OFFER_PRICE`: há objeto de oferta, mas sem preço numérico positivo válido; não inferir preço do cadastro.
- `PROVIDER_ITEM_ERROR`: erro por item na própria resposta Amazon; não tratar como oferta ausente.
- `PRICING_RESPONSE_MISSING`: o lote não retornou o Seller SKU solicitado.

Esse estado não autoriza publicar margem projetada nem lucro calculado a partir do preço cadastral. Para corrigir anúncios, obter confirmação de publicação, status BUYABLE, possíveis regras de precificação automática e o readback posterior no mesmo Seller SKU. Não repetir um PATCH comercial apenas porque a resposta Listings foi `ACCEPTED`. Regressão local: `php tests/amazon-margin-effective-pricing-offer-state-test.php`.

**Nota de interpretação (UI real 07/11):** o Seller Central pode exibir uma oferta ativa e um valor de preço diferente do cadastro mesmo quando o endpoint SP-API Pricing retorna `Product.Offers=[]`. O estado `PRICING_OFFERS_EMPTY` significa **somente** que essa resposta específica não forneceu preço suficiente para a certificação automatizada; não equivale a anúncio sem oferta ou sem disponibilidade no Seller Central. A fonte UI e sua data devem ser registradas separadamente, sem converter o preço cadastral em preço realizado.

## Reparacao de dependencia do navegador Olist na Marina (07/11/2026)

O coletor `scripts/amazon-margin/capture-olist-product-links.js` requer o
`playwright-core` para conectar **somente ao CDP autenticado existente** em
`127.0.0.1:9226`. Em 07/11 o caminho historico
`/home/ubuntu/amazon-returns-deploy/shared/olist-erp-browser/runtime/node_modules/playwright-core`
nao existia mais, embora a sessao Olist e o CDP ainda estivessem ativos.
A falha `MODULE_NOT_FOUND` impedia renovar a evidencia de produto/anuncio
quando a captura de ate 48 horas expirasse; nao era falta de senha ou RBAC.

**Caminho persistente, fora de releases imutaveis:**
`/home/ubuntu/shopvivaliz-deploy/shared/ops/amazon-margin-browser/node_modules/playwright-core`.
No runtime Marina autorizado, foi provisionada a versao **1.63.0** do pacote
Apache-2.0 a partir do cache npm oficial, sem iniciar novo navegador, copiar
cookies, criar workers ou alterar cadastro. Para reparar a instalacao em
futuros hosts, sob a identidade e lease autorizados:

```bash
npm install --offline --ignore-scripts --no-audit --no-fund --no-save \
  --prefix /home/ubuntu/shopvivaliz-deploy/shared/ops/amazon-margin-browser \
  playwright-core@1.63.0
node --check scripts/amazon-margin/capture-olist-product-links.js
```

O script agora gera erro explicito
`amazon_margin_playwright_core_missing_from_persistent_shared_ops` se essa
dependencia nao estiver instalada, em vez de recorrer a um `_npx` temporario.
Esta alteracao e somente de transporte. Validacao de CDP, autoria da sessao,
lease, indice oficial Olist de produtos ativos, screenshots privados e
integralidade das tres integracoes continuam obrigatorios. **Nao executar
capturas concorrentes** enquanto houver outra leitura/escrita autorizada no
mesmo perfil; o produto ERP nao e alterado e evidencias antigas nao devem ser
reescritas retroativamente.


## Evidência histórica após encerramento da oferta Seller Flex (07/11/2026)

A situação **BUYABLE** e o preço efetivo em Amazon Pricing descrevem somente
**a oferta atual**. Uma baixa autenticada no Seller Flex pode remover BUYABLE,
deixar `Product.Offers=[]` e não deve apagar da visão financeira os lançamentos
Shipment já publicados pela Amazon Finances dentro da janela auditada.

**RED real:** a auditoria #14 tinha 73 margens transacionais confirmadas,
mas o run #15 passou a ter 70 após 38 SKUs perderem BUYABLE por ajuste
Seller Flex autorizado. `ABRA-PERF-FBA`, `ME-MYQ8-H4JQ` e
`FN-WXV1-QWDV` perderam o reconhecimento da venda porque o código exigia
preço e fulfillment atuais. A queda de BUYABLE **não é regressão de estoque**:
o readback Seller Flex/SP-API das 38 baixas estava concluído e documentado
na issue #3699. A correção trata exclusivamente a classificação histórica.

**Regra de cálculo:** com custo do produto atual ativo e unidade do kit
verificados, `sale_gross_cents` positivo e conciliado com os valores
`ProductCharges+Shipping+PromoRebates` da transação Finances e com a
receita líquida de Shipment, calcular lucro/imposto (14%) sobre a transação
**mesmo quando a oferta não tem mais preço vigente**. Sem receita financeira
confirmada e sem preço corrente, margem = NULL, não fallback para preço
cadastral. Para ofertas inativas sem preço atual, margem **projetada continua
NULL** e somente a margem histórica devidamente conciliada é apresentada.

**Canal histórico:** `GET /orders/2026-01-01/orders/{orderId}` com
`includedData=FULFILLMENT`; verificar `fulfillment.fulfilledBy` para
**TODOS** os pedidos com remessas elegíveis no período. Aceitar somente
`AMAZON` ou `MERCHANT` sem mistura entre pedidos. `MERCHANT` exige
`programs[]` contendo explicitamente `DELIVERY_BY_AMAZON`, frete
`MfnPostageFee` conciliado uma única vez e Seller SKU único para aquele
pedido. Nem um SKU marcado FBA, nem um snapshot antigo, nem o tipo de anúncio
suprem a resposta da API. Se pedidos contraditórios/ausentes, status
`pending_financial_reconciliation` e nenhum lucro/margem histórica
certificados; 401/403/429/5xx globais abortam a execução pela política de
quota/backoff vigente, sem chamadas agressivas. Não usar FBAFees clássica
como custo do Seller Flex.

**Vínculo ERP:** pedido antigo fornece evidência para reconciliação do
Seller SKU independentemente de `BUYABLE`, mas somente sob verificação de
um pedido e um item/produto ativo Olist, sem disputa com EAN/aba Anúncios;
caso contrário `unmapped_erp`/`ambiguous_erp` permanece não certificado.
Os relatórios XLSX distinguem a origem
`AMAZON_ORDERS_V2026_FULFILLMENT` da hipótese de canal atual, sem expor IDs
de pedidos. Testes: `MarginMathTest.php`,
`HistoricalFulfillmentTest.php`, `ReportExportTest.php` e regressões
DBA/kit/FINANCES. Esta correção é P1 somente leitura comercial: **não**
ativa escrita automática de preços, estoque, custos ou pedidos.

A certificação global depende de nova auditoria completa pelo worker
existente após merge/deploy e UI admin autenticada com filtro ativos,
PDF/XLSX, reload/persistência. Snapshots #14/#15 são históricos imutáveis.

### Vinculo Olist e programa logistico do anuncio (11/11/2026)

O identificador Amazon Seller SKU do anuncio pode ser diferente do SKU do produto
Olist e o catalogo Amazon pode nao fornecer o GTIN. Consultar o vinculo exato na
aba **Produtos > Anuncios** do produto, nas integracoes **Amazon** (27473),
**Amazon Onsite** (27478) e **Amazon Classic** (27482). Quando o vinculo
esta vigente, confirmado na tela autenticada e corroborado pelo canal atual
retornado pelo Amazon Pricing, classificar **Amazon + MERCHANT = DBA** e
**Amazon Onsite + AMAZON = FBA Onsite**. Nunca confundir o nome da integracao
com o SKU do produto, nem aplicar tarifa FBA classica ao FBA Onsite. Caso o
mesmo Seller SKU figure em ambas as integracoes *do mesmo produto*, escolher
a integracao correspondente ao canal de fulfillment atual. Vinculos com
produtos ERP distintos exigem reconciliacao de identidade; nao substituir
custos de produtos diferentes por aproximacao.

A coleta autenticada de 11/11 identificou 211 produtos ERP ativos, 533 Seller
SKUs distintos vinculados e 0 erros de captura. Os 89 anuncios BUYABLE que o
snapshot anterior de margem tratava como sem ERP estavam todos vinculados;
o documento de captura de 06/11 havia ultrapassado a janela de atualidade
aplicada pelo auditor. A corretude da margem depende adicionalmente da cotacao
de comissao, custo ERP corrente, custo integral dos kits e tarifa real. Para
DBA em preco superior a R$ 200, a tabela corrente utiliza regiao de origem;
nao presumir regiao so pelo nome da integracao.

Exemplo: Seller SKU `1C7Q-LKVG-BOTU` foi localizado na integracao Amazon do
produto ERP ID `385227548`, SKU `178970678530`, com preco Amazon R$ 27,99.
A tarifa DBA aplicavel nesse preco e R$ 4,50; a margem nao pode ser
certificada antes de consultar custo e comissao atuais. Nenhum preco, estoque
ou anuncio foi alterado neste procedimento de leitura.

## Margem desejada — previa administrativa sem escrita comercial (12/11/2026)

O painel administrativo `/admin/amazon-margin.php` reutiliza a selecao
existente de Seller SKUs e oferece um campo de **margem liquida operacional
desejada (%)**, com `POST action=target_margin_preview` em
`/api/admin/amazon-margin.php`. A API consulta exclusivamente o ultimo
snapshot persistido e retorna, por SKU, o **preco de venda proposto**, o
**preco minimo Amazon proposto igual ao de venda**, o ID do produto ERP
para a lista **Amazon 991**, comissao proporcional a ultima cotacao SP-API,
tarifa logistica por faixa, impostos conforme `tax_bps` e margem simulada.

O calculo usa centavos inteiros e examina transicoes das faixas logisticas.
Nunca reduz o preco atual nesta previa. Produtos com custo de conjunto
pendente, preco efetivo ausente, modalidade desconhecida ou SKU ausente
recebem motivo de pendencia, nao valores preenchidos por deducao. Produtos
ERP compartilhados apresentam os demais Seller SKUs para dimensionar o
efeito no site e nas outras ofertas; alvos divergentes para o mesmo ID
aparecem como conflito. A funcao pode exportar a proposta como CSV; **nenhum
POST de simulacao escreve precos no ERP, na Amazon ou no site**.

**Limite da estimativa:** comissao e cotada apenas no preco vigente no
snapshot; o custo proporcional ao preco proposto e uma hipotese. Portanto
a margem exibida NAO e cotacao Amazon oficial no novo preco, nem lucro
historico realizado ou margem financeira total (publicidade, devolucoes
e outras despesas nao estao embutidas). A publicacao comercial real
exige o fluxo protegido preexistente, uma nova cotacao oficial SP-API
no preco proposto, compatibilidade e readback da tabela Olist 991,
preco efetivo da Amazon Pricing, Product Type Definition e atributos
`purchasable_offer.our_price` e
`purchasable_offer.minimum_seller_allowed_price`, impactos do mesmo
produto ERP, integracao com site, integridade financeira, aprovacao
formal de escopo e reconciliacao individual apos escrita. O endpoint
`target_margin_apply` retorna erro expresso enquanto esse executor nao
estiver autorizado; a interface nao promete aplicacao efetiva nesse estado.
Esta fase nao concede permissao comercial nem altera grants existentes.



### Credenciais SP-API — verificação cruzada ShopVivaliz / Amazon Returns

As credenciais LWA (client ID, client secret e refresh token) permanecem
somente nos ambientes privados, nunca no repositório ou na documentação.
O runtime ShopVivaliz já as carregava; o Seller ID é um identificador separado,
recuperado de dois snapshots privados de anúncios e confirmado com GET real
de Listings e Pricing na conta Amazon BR. O arquivo privado recebeu a chave
AMAZON_SELLER_ID preservando proprietário e permissões, sem registrar seu
valor em logs ou no Git.

A documentação do Amazon Returns confirma que o runtime utiliza o mesmo
conjunto de chaves LWA em seu próprio ambiente protegido. O preflight público
de saúde retornou HTTP 200, status OK e zero bloqueios, sem leitura ou cópia
do arquivo secreto do Returns. A API Sellers confirmou a participação no
marketplace BR; não é necessário pedir novo token ao operador.

Evidências de integração, somente leitura:
- GET /sellers/v1/marketplaceParticipations: HTTP 200.
- GET Listings e GET Pricing para R9-O6PX-YBXA: HTTP 200.
- PUT idempotente de validação na tabela 991: HTTP 204; GET posterior HTTP 200;
  159 exceções e uma promoção preservadas; SHA-256 canônico antes/depois idêntico;
  zero alteração de preço comercial.
- Cotação oficial getMyFeesEstimates no preço proposto R$ 119,08:
  comissão R$ 17,86, lucro R$ 11,91 e margem líquida calculada de 10,00%.
- Listings VALIDATION_PREVIEW para preço de venda e mínimo iguais a
  R$ 119,08: HTTP 200, status VALID, zero issues; nenhum PATCH comercial enviado.
- Amazon Returns /api/health.php: HTTP 200, status OK, zero bloqueios.

## Aplicação comercial de margem desejada (P2 — revisão protegida)

### Correção verificada no provedor Amazon Listings

A prévia real SP-API na conta autorizada (SKU de validação R9-O6PX-YBXA)
retornou HTTP 400, InvalidInput, para op=merge em
/attributes/purchasable_offer. Para o mesmo produto, preço proposto e
campo, a operação op=replace retornou HTTP 200, VALID, zero problemas
em mode=VALIDATION_PREVIEW. A nova operação substitui somente o atributo
purchasable_offer com a lista completa de ofertas recebida pelo GET,
preservando os demais públicos, ofertas e atributos; atualiza our_price
e minimum_seller_allowed_price da oferta selecionada no mesmo PATCH.
GET posterior confirmou preço original intacto: nenhum PATCH comercial
foi enviado. Antes do PATCH, o worker refaz o GET Listings depois do
readback do ERP 991 e reconstrói a substituição sobre a lista mais recente
de ofertas, não sobre o snapshot coletado antes do PUT Olist. Se uma
consulta transitória falhar depois de o ERP estar confirmado, a fila mantém
a etapa erp_verified para retomar por GET, sem repetir o PUT comercial.
A correção está coberta por testes que confirmam a operação, a etapa de
retomada e a preservação de ofertas não selecionadas.



### Contrato oficial de gravação Olist (validado no Swagger publicado)

O endpoint oficial é PUT /listas-precos/{idListaDePreco}, operação
AtualizarListaDePrecosAction, disponível desde a API v3.1.1. A resposta
de sucesso documentada é HTTP 204. O schema de entrada
AtualizarListaPrecoRequestModel aceita descricao, acrescimoDesconto e
itens[] com idProduto, preco e precoPromocional. O GET da lista retorna
excecoes[] e também codigo, que é campo de resposta, não do PUT. Portanto
o adaptador converte as exceções atuais da tabela 991 em itens[], preserva
preço regular/promocional de todos os produtos e altera somente a exceção
ERP selecionada. A compensação usa o mesmo schema oficial. O readback GET
confere preço alvo, promoções, percentual, códigos e todas as exceções não
selecionadas; divergência deixa o item em revisão manual.

O censo GET /listas-precos também identificou as listas 983 (Amazon FBA),
984 (Amazon ONSITE) e 991 (Amazon). O escopo desta rotina P2 permanece
explicitamente na lista 991 (Amazon); as outras duas listas não são alteradas
nem se presume a qual integração estão atribuídas apenas pelo nome.

Fontes oficiais:
- Swagger Olist ERP API v3: https://erp.tiny.com.br/public-api/v3/swagger/index.html
- Changelog v3.1.1: https://api-docs.erp.olist.com/changelog/versao-3-1-1
- Schema publicado no Swagger: PUT /listas-precos/{idListaDePreco} /
  AtualizarListaPrecoRequestModel.


A etapa posterior à simulação usa as rotas administrativas autenticadas:
- `POST target_margin_apply` exige os SKUs já selecionados, margem desejada,
  ID do snapshot, digest da mesma prévia, confirmação explícita do impacto
  da tabela 991 no site e nos outros anúncios, e nonce de idempotência.
  A resposta `202` significa **solicitação registrada**, não preço aplicado.
- `GET target_margin_apply_status&request_id=...` permite à mesma conta
  administrativa consultar o andamento sem ler o journal privado de
  credenciais e payloads dos provedores.
- A fila persiste `amazon_margin_target_price_requests` e
  `amazon_margin_target_price_items`, com checkpoint por etapa, SKU e
  valor em centavos. `scripts/amazon-margin/run.php --next` consome
  solicitações comerciais apenas se a fila normal de auditoria estiver
  vazia. `--all`, `--skus` e `--dry-run` não gravam preços.

A execução protegida reconsulta produto, canal e preço efetivo Amazon,
custo por unidade, faixa de logística, produto ERP e exceção da tabela 991.
Reestima a comissão via `getMyFeesEstimates` SP-API **no preço proposto**,
recalculando até alcançar a margem alvo com impostos e logística atuais.
Não escreve se houver custo pendente, promoção, quantidade/dimensão
não conciliada, preço efetivo divergente, SKU inexistente ou incompatibilidade
de produto ERP compartilhado. Na lista 991 preserva as demais exceções,
as regras gerais e preços promocionais não selecionados; após gravação
usa GET para confirmar paridade. Na Amazon usa uma alteração granular
da oferta com `purchasable_offer.our_price` e
`purchasable_offer.minimum_seller_allowed_price` igual ao preço de venda,
sem desabilitar campanhas, alterar estoque ou mudar outros campos.

A operação nunca repete PUT/PATCH de resultado incerto sem ler novamente
as APIs, por meio dos estágios `erp_submit_started`,
`amazon_submit_started` e `awaiting_readback`. Um retorno HTTP
definitivamente recusado pelo catálogo Amazon pode gerar compensação da
tabela ERP, somente quando a diferença ainda corresponde exatamente à
modificação isolada realizada pelo worker. Inconsistência, concorrência,
falta de autorização ou readback não confirmado permanecem para revisão
humana, sem autodeclaração de sucesso.

**Pendências de ativação em produção:** a SP-API utilizada pela auditoria
já cota comissões de preços propostos, mas o identificador oficial de
vendedor (Merchant Token/Seller ID) não estava presente nas quatro
chaves de configuração reconhecidas pelo cliente da VM Marina durante
a validação somente leitura. Por isso `getListingsItem` não foi
comprovado. Também falta evidência segura do contrato `PUT` da
lista 991 no Olist V3, sem alterar preços reais em teste. O executor
comercial não deve ser integrado em produção sem revisão nominal
`shopvivaliz-human`, verificação do provedor e teste E2E UI.
O módulo P2 não foi executado sobre preços reais durante seu desenvolvimento.

## Cotacao conservadora com medidas Amazon/Olist divergentes (proposta tecnica)

Quando ambas as fontes de embalagem sao verificadas e a divergencia de
medidas nao invalida a elegibilidade logistica, a simulacao com comissao
SP-API no novo preco utiliza a **maior tarifa oficial entre Amazon Catalog
e dimensoes Olist**, reavaliada a cada faixa de preco/peso. Se faltar
medida, houver pacote fora do programa, modalidade nao confirmada ou
tarifa sem origem configurada, nenhuma publicacao e autorizada. A medida
cadastrada nao e sobrescrita. A margem e projetada: custos extraordinarios
de anuncios, devolucoes e outras despesas permanecem fora desse indicador.

Regressao: `php tests/amazon-margin/TargetMarginConservativePackageTest.php`.
Esta alteracao de calculo nao e aprovacao de novos Seller SKUs nem permite
ultrapassar maximum_seller_allowed_price, promocao ativa, divergencia de
produto ERP ou o fluxo administrativo P2 preexistente. Sao necessarios
revisao, integracao, publicacao canonica e readback real antes de certificar
os casos pendentes.

## Regra de publicação do preço mínimo e máximo Amazon (14/11/2026)

A margem inicial da prévia administrativa para itens atualmente abaixo da
meta é **9%**, como definido pelo proprietário, sem redução de preços já
acima desse patamar.

Ao publicar um preço de venda aprovado, `our_price` e
`minimum_seller_allowed_price` recebem exatamente o mesmo valor. Se o
preço de venda ultrapassar o `maximum_seller_allowed_price` vigente, o
máximo passa para pelo menos 110% do preço de venda, arredondado para cima
ao centavo. Se o máximo já estiver acima do preço de venda, ele permanece
inalterado. Se não houver máximo cadastrado, não se inventa um limite.
Ofertas B2B e demais audiências não selecionadas são preservadas.

Máximos com mais de um período ou agendamento exigem reconciliação manual;
a automação não sobrescreve cronogramas. O valor máximo esperado é
persistido no journal por Seller SKU e conferido no readback assíncrono
da Seller Central, junto com venda, mínimo e tabela ERP 991. O schema do
journal recebe upgrade idempotente para `amazon_maximum_price_cents`.

Carrinhos de ferramentas permanecem fora da publicação e não podem ter
preço de venda, mínimo ou máximo alterados por esta rotina. A classificação
é sustentada pelos seis produtos Olist dos dez Seller SKUs atualmente
identificados, além de padrões de título para novos produtos. O motor de
prévia marca `state=excluded`, e a fila comercial ignora linhas isentas
mesmo quando selecionadas junto com itens permitidos. O worker também
reconcilia a identidade do SKU com o snapshot original imediatamente
antes de qualquer nova operação externa. Um lote somente de carrinhos
não gera solicitação comercial.
