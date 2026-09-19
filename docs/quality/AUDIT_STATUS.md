# Estado da Auditoria

**Status:** NÃO APTO (mantido — ver rodada 2026-09-19)

Nova auditoria formal executada em 2026-09-16 segundo `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, matriz de transições/dados históricos e overlay do projeto.

## Rodada 2026-09-19 — cobertura estática, sem acesso a produção
- Commit/SHA: branch `claude/auditoria-extrema-v5-97gu15` == `origin/main` == `1f8e2ca9a0a3f39c9d2106fec133f58c004d1686` (fetch confirmou branch atualizada; sem commits pendentes de merge).
- Ambiente de execução: worktree isolado sem SSH às A1 (`shopvivaliz-free-a1`, `always-free-arm-1787907847-26`), sem Remote Desktop Commander conectado a essas VMs e sem browser contra `shopvivaliz.com.br`. **Nenhuma evidência de produção/runtime foi coletada nesta rodada** — o veredito `NÃO APTO` de 2026-09-16 permanece o estado vigente porque a lacuna que o causou (paridade produção↔SHA candidato, mutações críticas via UI real) não foi e não pôde ser fechada aqui.
- Cobertura estática executada e resultado:
  - Lint de sintaxe PHP (`php -l`) em 100% dos `.php` versionados fora de `vendor/`/`node_modules`: **zero erros**.
  - `php scripts/quality/validate-health-output.php`: `COMPROVADO` (health.php válido, sem metadados sensíveis).
  - `php scripts/quality/validate-asset-manifest.php`: `COMPROVADO` (89 entradas válidas).
  - PHPUnit: **NÃO EXECUTADO** — `vendor/` não estava instalado neste worktree e instalar via `composer install` estava fora do orçamento seguro desta rodada (rede/tempo); ausência registrada como dívida de evidência, não como PASS.
  - QA de browser/Playwright: **NÃO EXECUTADO** — nenhum acesso a `shopvivaliz.com.br` nem ao ambiente candidato nesta sessão.
  - PR aberta associada à branch: nenhuma encontrada (`search_pull_requests head:claude/auditoria-extrema-v5-97gu15` retornou 0).
- Achados SAFE corrigíveis nesta rodada: **nenhum**. Nenhuma correção de código foi necessária nem inventada; apenas documentação da lacuna de evidência foi adicionada (este arquivo e `docs/AGENTS.md`).
- Classificação: a lacuna de paridade de runtime/produção permanece `P1` `NÃO VALIDADO` (herdada da rodada 2026-09-16, não uma novidade desta rodada) e bloqueia `APTO` até uma sessão com acesso real à infraestrutura/produção repetir os passos 1–5 de "Saída do NO-GO" abaixo.

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