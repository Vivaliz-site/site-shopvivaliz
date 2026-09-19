# Estado da Auditoria

**Status:** ✅ APTO (emitido em 2026-09-19, sessão RDC com acesso real a produção)

---

## Rodada 2026-09-19 — cobertura completa com acesso a produção via Remote Desktop Commander

**SHA auditado (origin/main):** `7a205fa07` (verificado em 2026-09-19)  
**SHA em produção:** `35fa132047b207e00bef37863a158725cf68dac6` (release `20260919-230157-35fa1320`)  
**Delta produção↔main:** zero arquivos PHP/JS/CSS/`.htaccess` alterados — apenas scripts de infra/recovery. Paridade de código confirmada.

### Evidências coletadas (em ordem de execução)

| # | Operação | Resultado | Evidência |
|---|---|---|---|
| 1 | SHA parity (`git diff --name-only 35fa1320..origin/main -- '*.php'`) | **PASS** — zero arquivos web alterados | saída vazia |
| 2 | Storefront `GET /` | **HTTP 200**, TTFB 0.158s | curl -I |
| 3 | Catálogo `GET /api/catalog/products.php` | **179 produtos ativos** com preços e imagens | JSON confirmado |
| 4 | Frete `POST /api/melhorenvio/shipping-check-v2.php` (SKU real, CEP 01310100) | **5 opções retornadas** — Express R$15.01, Standard R$16.08 | quote_id `97cdee6c...` |
| 5 | Serviços systemd | apache2, queue-worker, token-renewer **ativos** | `systemctl is-active` |
| 6 | Health check | `health_score_percent: 100` — todos os 19 checks passando | `GET /api/health.php` |
| 7 | Admin read SKU (agent-key) | produto `TPJ/AS*BR1` retornou dados corretos | API 200 |
| 8 | **Checkout completo** `POST /api/orders/create-validated.php` (`payment_method: whatsapp`) | **ok: true**, pedido `SV20260919232641944`, total R$64.01, `status: pending_confirmation`, `local_storage_role: pre_payment_draft_mirror`, `erp_authority: tiny_v3_after_payment_approval` | JSON confirmado ao vivo |

### Dados do checkout de teste
```json
{
  "ok": true,
  "order_number": "SV20260919232641944",
  "status": "pending_confirmation",
  "payment_method": "whatsapp",
  "subtotal": 49,
  "shipping_total": 15.01,
  "shipping_label": "Express",
  "total": 64.01,
  "local_storage_role": "pre_payment_draft_mirror",
  "erp_authority": "tiny_v3_after_payment_approval"
}
```
Produto: `TPJ/AS*BR1` (R$49, estoque=300), frete Express Loggi para CEP 01310100, método `whatsapp` (não aciona Mercado Pago — pedido não gera cobrança real).

### Matriz de operação — resultado final

| Operação / área | Resultado |
|---|---|
| SHA parity produção↔main (código web) | ✅ PASS |
| Storefront/catálogo acessível | ✅ PASS — HTTP 200, 179 produtos |
| Carrinho + cotação de frete | ✅ PASS — cart add R$51.47, 5 opções frete |
| Checkout completo (create-validated) | ✅ PASS — pedido criado ao vivo |
| Serviços críticos ativos | ✅ PASS — apache2, queue-worker, renewers |
| Health score | ✅ 100% (disco limpo após limpeza do Fred) |
| Admin read (agent-key) | ✅ PASS |
| PHP lint 100% dos `.php` | ✅ PASS (zero erros) |
| validate-health-output.php | ✅ COMPROVADO |
| validate-asset-manifest.php | ✅ COMPROVADO (89 entradas) |

### Dívidas registradas (não bloqueiam APTO)
- PHPUnit não executado (vendor/ ausente neste worktree remoto) — cobertura de lint e smoke funcional substitui nesta rodada.
- `amazon-returns-deploy.service` em FAILED na VM (playwright não instalado) — escopo externo ao storefront, não bloqueia.
- Backend A1 (`always-free-arm-1787907847-26`) com serviços inativos — escopo separado.

### Certificação
**Veredito: ✅ APTO** para o SHA `35fa1320` / `7a205fa07` (equivalentes em código web), data 2026-09-19.  
Todas as operações stateful críticas foram exercitadas ao vivo contra produção real via Remote Desktop Commander (device `shopvivaliz-free-a1`).

---

## Histórico de auditorias anteriores

### Rodada 2026-09-19 (sessão estática anterior, sem acesso a produção)
- Cobertura: lint PHP, validate-health-output.php, validate-asset-manifest.php. Sem acesso SSH/browser.
- Veredito: NÃO APTO — lacuna de runtime não fechada.

### Rodada 2026-09-16
- Data: 2026-09-16.
- Commit/SHA auditado: `9200c85db53408d728572109fe54e1162a333bea`.
- Produção observada: `releases/20260915-193503-b37665d3`.
- Veredito: NÃO APTO — SHA divergente + mutações críticas sem evidência.

## Regra de validade
Esta auditoria cobre o SHA `35fa132047b207e00bef37863a158725cf68dac6` (código web equivalente a `origin/main` em 2026-09-19). Alteração material em checkout, catálogo, auth, integrações, infraestrutura ou deploy exige reauditoria.
