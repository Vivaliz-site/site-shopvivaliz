# Autorização humana específica — conflito de identidade no MySQL derivado (02/11/2026)

## Gatilho e causa raiz (evidência LIVE, sem escrita)
A decisão comercial original [PR #3476](https://github.com/Vivaliz-site/site-shopvivaliz/pull/3476) foi APPROVED e MERGED, mas seu escopo exige **PARAR** se houver SKU/ID ambíguo, conflito de índice, pedido/reserva potencialmente afetado ou mudança comercial imprevista. O preflight real em `marina-a1-4c24g` encontrou exatamente tal colisão **ANTES** de qualquer transação.

- Fonte de verdade: Olist/Tiny v3, catálogo cacheado `storage/products-cache-ativos.json` da tabela Amazon **991**, com `_price_source=olist_tiny_amazon`, `estoque_disponivel` e 201 SKUs válidos únicos de 202 itens consultados; 1 item sem SKU deve ser excluído explicitamente.
- Destino: tabela **local derivada** `products` do MySQL ShopVivaliz; 260 registros, 201 ativos.
- Diferenças lidas em 02/11, apenas leitura: 4 SKUs ausentes, 19 IDs divergentes, 156 preços e 148 estoques divergentes, 4 reativações, 1 desativação proposta, **1 colisão de identidade**.
- **Duas linhas locais ativas possuem o MESMO `olist_id=341443735`**:
  - `products.id=257`, SKU legado `FLOREIRA-FLAT77`, `olist_id=341443735`, preço local R$ 810,96, estoque 9.
  - `products.id=15286`, SKU vigente `JFQFCQ77x2`, `olist_id=341443735`, preço local R$ 810,96, estoque 0.
- **Fonte ERP**: somente SKU `JFQFCQ77x2` aparece com `id=341443735`, preço Amazon 991 R$ 810,96 e estoque *vendável* 7. O SKU legado `FLOREIRA-FLAT77` não aparece como produto ativo ERP no snapshot.
- Inspeção das relações no momento: `order_items` para `products.id=257` = 0; total de vínculos em `product_categories` aos IDs 257 e 15286 = 0. Esses números **devem ser reconfirmados dentro da transação**, junto a todas as FKs, pedidos/reservas e dependências externas.
- Exemplo independente: `1C7Q-LKVS-MDOC` no MySQL ainda com `olist_id=387064728`, preço R$ 10,00 e estoque 0, mas ERP atual com `olist_id=393626238`, preço Amazon 991 R$ 38,90 e vendável 4.
- Auditoria integral read-only de 202 produtos verificou 200/200 preços públicos iguais ao ERP (0 duplicidade de SKU), mas o **veredito geral foi FAIL** por `release_changed_during_price_audit`: site público mudou de SHA durante a execução. Portanto **não usar essa rodada como certificação final**.

## Decisão comercial específica a ratificar pelo proprietário
Aprovar **apenas uma** resolução dessa colisão local, condicionada a preflight exato e transação reversível:
1. Preservar a linha histórica `products.id=257` e seus vínculos, mas **desativar** `active=0` e **desassociar** `olist_id=341443735` (set NULL) **somente se** ERP confirmar que o SKU legado está realmente inativo/ausente e não houver referências comerciais, anúncios, reservas, pedidos ou outras relações afetadas. Não excluir a linha nem escrever qualquer dado no ERP.
2. Preservar `products.id=15286`, SKU `JFQFCQ77x2`, vinculado ao mesmo ERP `id=341443735`; deixar sua atualização de preço/estoque/active para a reconciliação única já descrita e aprovada na PR #3476. Para este snapshot, preço efetivo R$ 810,96 e estoque vendável 7, **reconsultar imediatamente antes** da aplicação; nunca fixar números antigos no código.
3. Proibir alterações ao preço Amazon 991, estoque ERP, anúncios, pedidos, reservas, custos, caixas de compra, fornecedores ou qualquer outra linha derivada por meio desta autorização complementar. **Nenhum outro conflito fica autorizado**. Nova colisão ou mudança material exige nova decisão.

A aprovação deverá ser **REVIEW APPROVED da conta exclusiva `shopvivaliz-human` no SHA final e merge pessoal via GitHub**, com as proteções de branch. O arquivo de grant `security/owner-approvals/<NUMERO_DA_PR>.json` delimita recurso, principal, operação, pares exatos de registros, prazo e uso único. Não confundir aprovação da PR #3476 com a aprovação desta exceção.

## Execução após autorização (NÃO realizar pelo merge por si)
1. Operador técnico oficial valida owner grant com `scripts/validate-owner-pr-grant.py`, repo, SHA, revisão, arquivo imutável em `main`, expiração e `operation=resolve_legacy_sku_conflict`. Separar credenciais humanas e operacionais.
2. Na VM Marina, usar a identidade autorizada e adquirir lock/lease de escritor comercial canônico; conferir que não exista outra conciliação ativa, obter snapshot fresquíssimo ERP v3 Amazon 991 e recusar se dados/identidades divergem.
3. Criar **backup verificado de `products` e FKs** com hash, count e restore test em ambiente isolado, sem dados pessoais/segredos no PR. Plano de reversão testado antes de qualquer `UPDATE`.
4. Iniciar uma transação MySQL (isolation adequada, bloqueios `SELECT ... FOR UPDATE` sob lease), revalidar id257/id15286, unicidade SKU+Olist ID, FKs, pedidos/reservas, e executar **somente** o UPDATE estreito do item legado; exigir `affected_rows=1`; caso contrário rollback integral.
5. Readback interno antes do COMMIT, verificar linha 257 inativa e `olist_id=NULL`, linha 15286 intacta e unívoca, nenhum vínculo alterado. Commit apenas se todas as invariantes passam; readback durável após COMMIT.
6. Reexecutar, separadamente, a reconciliação geral **única** aprovada pela PR #3476 por executor transacional validado, dry-run e backup/rollback, preservando IDs locais; **nunca usar** o importador `sync-daemon-to-db.php` não transacional ou religar o serviço automático para escreve-la.
7. Certificar ERP/DB/publicações por SKU+ID, preços e disponibilidade Amazon 991, webhook real, carrinho legado e jornadas gráficas reais de pesquisa/produto/carrinho/checkout sem pagamento, no SHA publicado e após reload; registrar RED/GREEN, certifier V5 e rollback.

## Segurança, falha fechada e rollback
- Este PR **não altera MySQL, ERP, serviços nem permissões externas**, e o merge não ativa execução.
- Uma discrepância, qualquer efeito sobre pedidos/reservas/anúncios, qualquer falha de verificador/lease/backup/E2E **proíbe UPDATE** e conserva estado.
- A reversão restaura somente valores originais da tabela derivada, preservando IDs/FKs e logs auditáveis; nunca reverter alterações comerciais do ERP automaticamente.
- Válido apenas para recurso `commercial:derived-products-db`, operação `resolve_legacy_sku_conflict`, principal `shopvivaliz-derived-products-maintainer`, revisão/merge pessoais e expiração UTC definida no grant.
