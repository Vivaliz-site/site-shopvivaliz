# Estado da Auditoria

**Status:** NÃO APTO

Nova auditoria formal executada em 2026-09-16 segundo `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, matriz de transições/dados históricos e overlay do projeto.

## Última auditoria válida
- Data: 2026-09-16.
- Commit/SHA auditado: `9200c85db53408d728572109fe54e1162a333bea`.
- Produção observada: `/home/ubuntu/shopvivaliz-deploy/current -> releases/20260915-193503-b37665d3`.
- Veredito: **NÃO APTO PARA CERTIFICAÇÃO**.
- Confiança: muito alta para o NO-GO.
- Stop-the-line: o release publicado não corresponde ao SHA auditado e não existe evidência produção-equivalente das mutações críticas no SHA corrente.

## Evidência fresca desta auditoria
- `https://shopvivaliz.com.br/` respondeu HTTP 200.
- `https://shopvivaliz.com.br/health` e `/status` responderam HTTP 404 nesta inspeção; portanto evidências históricas de health em outra rota/release não podem ser reutilizadas sem contrato atual.
- `apache2`, queue worker e renovadores de token ShopVivaliz/Mercado Livre/Shopee foram observados ativos.
- O release ativo possui prefixo `b37665d3`; o `main` auditado é `9200c85d...`.
- Não foi efetuada compra, alteração de preço, estoque ou produto real. Sob a nova regra, testes/API não substituem mutações de operador pela UI quando essas mutações são o comportamento certificado.
- A listagem de Actions por `main` não apresentou uma execução funcional recente vinculada ao SHA auditado capaz de substituir a lacuna de runtime; os checks de PR/governança não certificam o storefront publicado.

## Matriz de operação e paridade
| Operação / área | Local/CI | Produção no SHA auditado | Resultado |
| --- | --- | --- | --- |
| abrir storefront/catálogo | testes existentes | apex 200 no release antigo | PASS apenas para release publicado antigo |
| busca/categoria/produto | cobertura automatizada histórica | não reexecutada no `9200c85d...` publicado | NÃO VALIDADO NO SHA |
| carrinho/checkout | testes/smokes existentes | pagamento real não executado; release divergente | NÃO VALIDADO E2E |
| admin editar catálogo/preço/estoque | automações/testes parciais | mutação real segura no SHA não executada | NÃO VALIDADO |
| integrações/renewers | serviços ativos | efeito ponta a ponta não reconciliado nesta auditoria | PARCIAL |
| health/proveniência | histórico divergente | `/health` e `/status` 404; pointer `b37665d3` | **FAIL DE PROVENIÊNCIA** |
| rollback/restore | artefatos/runbooks existentes | não revalidado para o candidato | NÃO VALIDADO |

## Achados
### P1 — produção não executa o SHA auditado
**COMPROVADO.** `main=9200c85d...`; release ativo `b37665d3...`. Sob `AUDIT_RUNTIME_PARITY_V1`, o comportamento live não pode certificar o candidato.

### P1 — operações stateful críticas não têm paridade de runtime no candidato
Checkout e mutações administrativas de catálogo/preço/estoque não foram exercitados pela interface canônica contra o mesmo release candidato, com reload/revisita e confirmação do efeito. Isso bloqueia `APTO`.

### P2 — contrato de health não comprovado no release atual
Os caminhos `/health` e `/status` retornaram 404. O storefront está acessível, mas falta um health/version endpoint inequívoco que exponha a proveniência do artefato servido.

### P2 — reconciliação entre testes de PR e ambiente publicado é insuficiente
Gates verdes de branch/PR não demonstram que os mesmos fluxos críticos foram executados no release público nem que classes de dados legados/migrados foram exercitadas.

## Risco residual
Alto para certificação. A loja responde, mas o release não é o candidato auditado e as mutações de maior impacto não têm evidência produção-equivalente no mesmo SHA.

## Saída do NO-GO
1. implantar artefato imutável do SHA aprovado e expor esse SHA em health/version;
2. rodar navegação + carrinho + checkout em canário seguro, sem cobrança indevida;
3. exercitar mutações administrativas seguras em dados de teste/staging e confirmar após reload/revisita;
4. comparar inventário local de fluxos com o conjunto realmente executado no ambiente candidato;
5. revalidar integrações, rollback e restore e executar reauditoria contraditória.

## Regra de validade
Esta auditoria cobre `9200c85db53408d728572109fe54e1162a333bea` e o release observado em 2026-09-16. Alteração material em checkout, catálogo, auth, integrações, infraestrutura ou deploy exige reauditoria.