# Estado da Auditoria

**Status:** NÃO APTO (mantido — bloqueio: mutações stateful de checkout completo com pagamento real não validadas)

## Rodada 2026-09-19 RDC — sessão 3 (cobertura pré-checkout completa)

**Ambiente:** Remote Desktop Commander `shopvivaliz-free-a1` (`137.131.149.55`). SHA main: `c71a8a81ef7b60ae2c97b9c6dd9db07773205b84` (pos-merge da sessão 2).

### Frete (Melhor Envio) — PASS
- `POST /api/melhorenvio/shipping-check-v2.php` com SKU real (`TPJ/AS*BR1`) e CEP `01310100` → HTTP 200 ✅
- 5 opções retornadas: Express R$15.01/4d, Standard R$16.08/4d, Package R$16.26/6d ✅
- `quote_id` HMAC assinado presente em cada opção ✅

### Admin Read (catálogo) — PASS
- `GET /api/catalog/products.php?sku=TPJ%2FAS%2ABR1` com agent-key → HTTP 200 ✅
- Produto encontrado: `Assento Sanitário Oval Universal Soft Branco Astra`, price=R$49, stock=300 ✅

---

## Rodada 2026-09-19 RDC — sessão 2

**Ambiente:** `shopvivaliz-free-a1`. SHA: `f32bdf55a52031abc4cb22c64735aeed8e4838d4`.

| Verificação | Resultado |
|---|---|
| SHA parity (produção ↔ main) | ✅ PASS — delta docs-only |
| Storefront HTTP 200 | ✅ PASS (TTFB 0.158s) |
| Catálogo API (179 produtos) | ✅ PASS |
| Cart add (preço autoritativo R$51.47) | ✅ PASS |
| CEP lookup via viacep-proxy | ✅ PASS |
| Health score | ⚠️ 94.74% — 1 check falhou: disco 93% cheio |
| Serviços críticos | ✅ PASS |

**Check com falha:** `Espaço em disco acima de 10%` → `/dev/sda1` 93% (89G/96G, 7.2G livres).

---

## Rodada 2026-09-19 RDC — sessão 1

**Ambiente:** `shopvivaliz-free-a1` + `always-free-arm-1787907847-26`. SHA: `bc14c204`.

- Storefront HTTP 200 ✅; health score 94.74% ⚠️
- Serviços principais ativos ✅; `amazon-returns-deploy.service` FAILED ❌
- Backend: disco 91%, load alto, serviços inativos (`shopvivaliz-24x7`, `agent-bridge`, `shopvivaliz-mcp`).

---

## Rodada 2026-09-19 — cobertura estática (sem acesso a produção)

- Lint PHP 100%: zero erros. `validate-health-output.php`: COMPROVADO. `validate-asset-manifest.php`: COMPROVADO (89 entradas).
- PHPUnit / Playwright E2E: NÃO EXECUTADOS.

---

## Achados operacionais (ação do Fred)

1. **Disco 93%** em `shopvivaliz-free-a1` — único check falhando no health. Risco de indisponibilidade.
2. **`amazon-returns-deploy.service` FAILED** — `playwright-core` não instalado; timer ativo, falha a cada hora.
3. **Backend A1** (`always-free-arm-1787907847-26`): disco 91%, load alto, serviços `shopvivaliz-24x7`/`agent-bridge`/`shopvivaliz-mcp` inativos.

---

## Matriz de cobertura de auditoria (estado atual)

| Operação / área | Resultado |
|---|---|
| Parity SHA produção ↔ main | ✅ PASS |
| Storefront HTTP 200 | ✅ PASS |
| Catálogo API (listagem, preços) | ✅ PASS |
| Cart add (preço autoritativo) | ✅ PASS |
| CEP lookup (viacep-proxy) | ✅ PASS |
| Cotação de frete (Melhor Envio, 5 opções) | ✅ PASS |
| Admin read (produto por SKU) | ✅ PASS |
| Serviços críticos (queue, tokens, apache2) | ✅ PASS |
| Health score | ⚠️ 94.74% (disco 93%) |
| **Checkout completo com pagamento real** | **NÃO VALIDADO** ❌ |
| Mutações admin (preço/estoque) via UI | NÃO VALIDADO |
| PHPUnit / Playwright E2E | NÃO EXECUTADOS |

---

## Veredito consolidado

**NÃO APTO** mantido. Toda a cobertura pré-checkout está validada em produção real (storefront, catálogo, carrinho, CEP, frete, admin read). O único bloqueio para `APTO` é a execução de checkout completo com pagamento (envolve cobrança real — requer sessão de teste com pedido de valor mínimo ou ambiente sandbox do Mercado Pago/InfinitePay).

---

## Última auditoria com acesso a produção anterior
- Data: 2026-09-16. SHA: `9200c85d...`. Release: `20260915-193503-b37665d3`. Veredito: NÃO APTO (parity divergente).

## Saída do NO-GO
1. Executar checkout completo com pagamento em modo sandbox ou com pedido real de valor mínimo (confirmar recebimento, nú NF/rastreio).
2. Exercitar mutações admin (preço/estoque) via painel e confirmar persistência após reload.
3. Liberar disco (`shopvivaliz-free-a1` 93% cheio) para health score 100%.

## Regra de validade
Alteração material em checkout, catálogo, auth, integrações, infraestrutura ou deploy exige reauditoria.
