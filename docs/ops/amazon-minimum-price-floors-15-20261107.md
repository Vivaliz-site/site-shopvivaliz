# Amazon — correção limitada de 15 pisos de preço (07/11/2026)

**Tipo:** autorização comercial específica, sem execução automática por merge.
**Owner intent:** igualar o preço mínimo Amazon ao preço de venda já atualizado, exclusivamente nos Seller SKUs confirmados abaixo.
**Fonte:** leitura oficial Amazon Listings Items via MCP Marina, tarefas `0d051637-4000-4898-9c26-d1b9ab8c47a9` e `9bcdacc7-cc93-487c-b5cd-ce1354d3b56e`, 07/11 03:41–03:42 UTC.
**Contraprova:** Amazon Pricing GET contra ERP 991 em 03:40:14 UTC: `SYNC_VERIFIED`, **47/47 preços efetivos corretos**, nenhum preço a reajustar nesta operação. O lote já alterado de 32 Seller SKUs teve preço mínimo = venda confirmado por readback anterior; esta operação trata somente os 15 restantes.

| Seller SKU | Venda ERP/Listing atual (R$) | Mínimo lido na Amazon (R$) | Novo mínimo proposto (R$) |
|---|---:|---:|---:|
| `CXDESFBA` | 78,90 | 47,90 | 78,90 |
| `1C7Q-LKV8-XT97` | 78,90 | 47,90 | 78,90 |
| `Tpjdba` | 44,36 | 38,90 | 44,36 |
| `ASSLIMFBA` | 75,35 | 71,91 | 75,35 |
| `TAJ/AS*BR1` | 75,35 | 71,91 | 75,35 |
| `1C7Q-LKVS-KOPL` | 75,35 | 71,91 | 75,35 |
| `VW-OD1U-AW2Y` | 117,17 | 104,62 | 117,17 |
| `1C7Q-LKUB-35V1` | 159,00 | 109,53 | 159,00 |
| `1C7Q-LKUT-WROG` | 25,39 | 22,79 | 25,39 |
| `LO-RAD4-KBW6` | 25,39 | 22,82 | 25,39 |
| `20787` | 13,31 | ausente | 13,31 |
| `JO-YODT-DYV3` | 13,31 | ausente | 13,31 |
| `WK-IMBG-A8Y3` | 13,31 | ausente | 13,31 |
| `ARM8` | 567,04 | 430,00 | 567,04 |
| `ARM8ONSITE` | 567,04 | ausente | 567,04 |

## Condições obrigatórias antes de qualquer alteração externa

1. A PR de autorização precisa estar **Ready for review** e obter revisão GitHub `APPROVED` exclusivamente da conta `shopvivaliz-human`, no SHA exato final, checks obrigatórios verdes, merge nativo. Arquivo de grant com `pr_number` exato, lista fixa dos 15, preços em **centavos** e prazo limitado; não interpretar presença do grant na branch como aprovação.
2. **Lease/fencing/single-writer** no host `marina-a1-4c24g`, nenhum processo de precificação concorrente. Garantir que o grant one-use não foi consumido nem expirou.
3. Reconsultar a API oficial Olist/Tiny lista 991, Amazon Pricing e Listings para cada SKU: verificar BRL, marketplace BR, Seller SKU exato (incluindo SKU numérico `20787` como **string**), canal FBA Onsite/DBA, produto/kit, venda atual igual ao alvo, ausência de aprovação comercial revogada e listagem autorizada.
4. Se mínimo já igual à venda no readback, **não enviar PATCH**. Se venda mudou, divergência de comissão/custo, atributos exigidos ausentes, erro de schema, promoção de marketplace, bloqueio Amazon, 401/403 ou recusa explícita: **não alterar** até reconciliar; não recorrer a rota alternativa para contornar a recusa.
5. Usar somente a operação oficial Amazon Listings Items `PATCH` de `purchasable_offer`, preservando integralmente os demais campos e schedules atuais conforme Product Type Definition efetiva; **alterar apenas** `minimum_seller_allowed_price`. Não fazer substituição parcial destrutiva de todo o objeto, não alterar `our_price`, `maximum_seller_allowed_price`, promoções, ERP 991, estoque, logística, anúncios fora do grant, nem carrinhos.
6. Para cada SKU: POST/PUT não autorizado; consultar resposta de PATCH (ACCEPTED não basta), verificar assincronismo/propagação, obter readback **GET Listings Item** do preço mínimo exato e **GET Amazon Pricing** do preço de venda, registrar prova sem dados de clientes. Parar diante de erro, reconciliação inconclusiva, ou quota 429 de forma proporcional.
7. Idempotência: uma execução controlada sobre no máximo os 15; se parcial, retomar somente SKUs que ainda divergem após readback, com mesma aprovação/grant dentro do escopo e lease válida. Não marcar APTO sem 15/15 floors e 47/47 vendas certificados.
8. Não ampliar a autorização a ajustes FBA Onsite por BIN, SAFE-T, reembolsos, emissão fiscal, Google Ads ou outras operações.

**Estado antes da aprovação:** preços de venda 47/47 corretos, **15 preços mínimos incorretos/ausentes**, 0 patches desses 15 realizados por esta autorização. Não iniciar rotina de escrita apenas pela abertura da PR.
