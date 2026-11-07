# Aprovação restrita: zerar disponibilidade Amazon de itens sem estoque vendável no Olist (06/11/2026)

**Natureza:** autorização comercial P2 de execução única, ainda NÃO CONCEDIDA nesta PR. Esta decisão não executa estoque nem altera ERP, custos, preços, anúncios, pedidos ou habilita automação recorrente.

**Pedido expresso do proprietário no chat (06/11/2026):** considerar exclusivamente o campo `disponivel` da API oficial Olist/Tiny v3; para os anúncios Amazon ativos vinculados aos 18 produtos com `disponivel <= 0`, zerar o estoque anunciado na Amazon, mediante autorização formal, canal correto e readback. O texto do chat **não substitui** `APPROVED` exclusivo da conta `shopvivaliz-human` no SHA exato, conforme `docs/knowledge/security-approval-via-pr.md` e `config/autonomy-policy.json`.

## Evidência de origem e limite fechado
- Amazon auditor read-only gerado `2026-11-06T20:44:10Z`, 527 registros, 454 `BUYABLE`. Este é o universo **observado**, não a verdade de disponibilidade no momento futuro de escrita.
- Cache Olist/Tiny: `2026-11-06T23:34:01Z`, 202 produtos; **18 produtos ERP e 38 anúncios BUYABLE com estoque vendável <= 0**. API autoritativa `GET /estoque/{id}`, 18/18 resultados confirmados em `2026-11-06T23:51:39Z..23:51:59Z`: 13 disponibilidade zero, 5 negativa. Disponibilidade negativa deve ser tratada como 0 vendável; saldo físico positivo reservado não significa disponibilidade.
- 17 anúncios adicionais com `ambiguous_erp` não estão nesta autorização e ficam fora de qualquer mutação.
- Canais Amazon da última coleta: **37** `AMAZON` (FBA Onsite, inclusive 2 SKUs com programa indeterminado), **1** `MERCHANT`/DBA. Readback de Listings Items pela SP-API: `03SFBA` e `FITAPERFAFBA` retornaram `BUYABLE`/HTTP 200. O segundo possui `AMAZON_NA` e `DEFAULT` (atributo DEFAULT quantity=3), portanto zerar somente DEFAULT não comprova desativar a oferta inteira.
- Não existe permissão efetiva de escrita em estoque Amazon comprovada nesta decisão. O `includes/marketplace/AmazonPublisher.php` atua com guard `sv_market_assert_no_commerce_fields` e **não** autoriza campo de inventário.

## Allowlist fechada por ID ERP e Seller SKU
| ID produto ERP | SKU do ERP | Seller SKUs Amazon incluídos |
|---|---|---|
| `341681699` | `03S` | `03SFBA`, `03sfbanovo`, `03sfna3`, `6Z-4I3R-J2VI`, `80-TH8G-9YV5`, `ZT-WFPJ-VBSB` |
| `342907554` | `1C7Q-LKVM-XFLJ` | `2Z-R4WV-3K4B`, `TREN75FBA` |
| `393626238` | `1C7Q-LKVS-MDOC` | `6Q-ZMC2-YZ4Y`, `FN-WXV1-QWDV`, `ROD75FREIOFBA` |
| `352335653` | `4` | `SANF4FBA`, `SO-D7LA-PSQA`, `VI-85O2-ZQTD`, `Z3-GGSQ-QVBA`, `sanf4-dba` |
| `353735611` | `C08AZ` | `SR-NG47-RMLL`, `T5-OJPT-8TMJ` |
| `342902343` | `CPAZ` | `COM-ASTRA-FBA1` |
| `353696687` | `CSD830FBA` | `NJ-RN79-J1HN` |
| `362847505` | `FITAPERFAFBA` | `8F-QXBC-MINT`, `ABRA-PERF-FBA`, `FITAPERFAFBA`, `RH-6WBD-NY54` |
| `353701556` | `JFQFAC57` | `43-YMM0-BR26`, `NB-9FGK-1YTY` |
| `341534002` | `JVAQMA56` | `ANT-MAC-56-DBA` |
| `352292111` | `JVCDCQ34` | `DEC34FBA`, `ME-MYQ8-H4JQ`, `dec34cqdba` |
| `352312277` | `JVCDPA33` | `MH-ALIG-JOEQ` |
| `353702856` | `JVTBPA32` | `QT-9ZRY-JOG8` |
| `341679379` | `N09R` | `7GAVFBA`, `LY-CLG1-1M2H` |
| `354887867` | `PCT-500` | `PCT-500` |
| `360490989` | `Pote500` | `WV-AHVH-R05V` |
| `342902474` | `SKU-342902474` | `FZ-OGHY-UT6G` |
| `350621042` | `VCRAC2` | `FG-CR2C-H6U3` |

**Escopo:** 38 Seller SKUs EXATOS acima; sem curinga, sem outro marketplace, sem alteração de preço/custo/estoque no ERP, sem mudança de anúncios não enumerados. Não presumir que `AMAZON_NA` pode ser atualizado por `fulfillment_availability.DEFAULT`.

## Gates mandatórios antes de qualquer envio externo
1. Obter GitHub `APPROVED` genuíno de `shopvivaliz-human` neste HEAD e merge via gates oficiais; comprovar grant imutável, principal, lease/fencing, finalidade de execução única e TTL. Não usar revisão em chat como substituto.
2. Revalidar em momento imediatamente anterior ao efeito os 18 valores oficiais Olist `GET /estoque/{id}` e a associação exata Seller SKU -> ID ERP, sem sobrescrever conflitos. Se algum SKU deixou de ser `BUYABLE`, se mudou de ID, se há estoque vendável positivo ou se há divergência, **pular SOMENTE esse SKU**, com motivo; não zerar por cache antigo.
3. Consultar Amazon Listings e inventário específico real por logística e instalação: `AMAZON_NA` exige caminho de inventário Seller Flex/FBA Onsite (External Fulfillment Inventory API / Seller Flex autenticado). Requer papel específico Amazon, identificação verificável da instalação/locationId e sequência monotônica sem colisão com outro escritor. Para `MERCHANT`, preservar os canais Amazon concorrentes; não apresentar `DEFAULT=0` como estoque FBA=0.
4. Verificar ownership/lock dos escritores de inventário. Sem lease, entitlement, site code ou permissão externa efetiva, falhar fechado. Proibido contornar 401/403, MFA, role Amazon, bloqueio de plataforma ou quotas 429; usar backoff controlado e batches pequenos.
5. Submeter apenas quantidades 0 aos SKUs que cumprirem todos os gates; não cancelar pedidos, não desativar listings por método substitutivo, não alterar frete, preços, custos, outras origens ou anúncios; registrar request-id e resposta por SKU, sem secrets/PII.
6. Para cada SKU, validar readback da **quantidade vendável real no Seller Flex/serviço de estoque** e do estado da oferta após propagação. Se resposta for somente `ACCEPTED` sem efeito confirmado, marcar `PENDING_EXTERNAL`, nunca `CONCLUIDO`.
7. Persistir evidência privada dos 38, distinguir `ZERO_CONFIRMED`, `ALREADY_ZERO`, `SKIPPED_CHANGED_ERP`, `NO_ENTITLEMENT`, `REJECTED`, `PENDING_EXTERNAL`. Não criar worker/timer concorrente nem reativar escrita automática. Restabelecer disponibilidade positiva somente com nova fonte ERP e decisão comercial específica.

## Referências
- Fonte técnica: `docs/ERP-OLIST-TINY-SOURCE-OF-TRUTH.md`, `docs/TINY-ERP-API-V3.md`, `docs/ops/amazon-margin-audit.md`.
- Issues: [#3699](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3699), [#3728](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3728).
- Amazon: [Listings Items - submit inventory](https://developer-docs.amazon/sp-api/docs/submit-listings-data); [External Fulfillment Inventory v2024-09-11](https://developer-docs.amazon/sp-api/lang-en_us/docs/external-fulfillment-inventory).
