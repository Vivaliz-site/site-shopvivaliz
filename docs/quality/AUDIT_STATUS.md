# Estado da Auditoria

**Status:** NÃO APTO (mantido — aguardando validação de mutações stateful em produção)

## Rodada 2026-09-19 RDC — acesso real à VM produção (sessão 2)

**Ambiente:** Remote Desktop Commander `shopvivaliz-free-a1` (`137.131.149.55`). SHA main HEAD e produção: `f32bdf55a52031abc4cb22c64735aeed8e4838d4`.

### Parity SHA — PASS
- Produção (`current` symlink): `20260919-205351-bc14c204` — alinhado funcionalmente com `origin/main`.
- Delta `bc14c204..f32bdf55`: apenas docs (`docs/quality/AUDIT_STATUS.md`, `docs/AGENTS.md`). **Sem deploy drift de código operacional.**

### Storefront — PASS
- `https://shopvivaliz.com.br/` → HTTP 200, TTFB 0.158s ✅
- Conteúdo HTML válido retornado ✅

### Catálogo API — PASS
- `GET /api/catalog/products.php?limit=5` → HTTP 200, 179 produtos disponíveis ✅
- Preços corretos (ex.: R$51.47 produto de referência) ✅

### Carrinho — PASS
- `POST /api/cart/add.php` com produto real → HTTP 200 ✅
- Preço retornado autoritativo pelo servidor (R$51.47) ✅

### CEP / Endereço — PASS
- `GET /api/viacep-proxy.php?cep=01310100` → HTTP 200, JSON `{cep, logradouro, bairro, localidade, uf}` correto ✅
- Av. Paulista / Bela Vista / São Paulo / SP ✅

### Health Check — ATENÇÃO
- Score: **94.74%** (`status: attention`)
- 19 checks avaliados; 1 falhou:
  - ❌ `Espaço em disco acima de 10%` = FALSE → disco `/dev/sda1` com **93% de uso** (89G/96G usados, apenas 7.2G livres).
- Todos os outros 18 checks: OK ✅

### Serviços shopvivaliz-free-a1 — PASS parcial
| Serviço | Estado |
|---|---|
| shopvivaliz-queue-worker | active/running ✅ |
| shopvivaliz-shopee-token-renewer | active/running ✅ |
| shopvivaliz-token-renewer | active/running ✅ |
| shopvivaliz-sync-safe.timer | active/waiting ✅ |
| apache2 | active ✅ |
| **amazon-returns-deploy.service** | **FAILED** ❌ |

### Achados operacionais (ação necessária do Fred)

1. **Disco 93% cheio** em `shopvivaliz-free-a1` — único check com falha no health. Risco de indisponibilidade se não houver limpeza.
2. **`amazon-returns-deploy.service` FAILED** — `playwright-core` não instalado no host. Timer ativo, falha a cada execução. Desabilitar timer ou instalar playwright.
3. **Backend `always-free-arm-1787907847-26`**: disco 91%, load alto (4–6), serviços `shopvivaliz-24x7`/`agent-bridge`/`shopvivaliz-mcp` inativos (rodada anterior).

### Operações stateful — NÃO VALIDADO
- Checkout completo com pagamento real: **não executado** (envolve cobrança real).
- Mutações admin de catálogo/preço/estoque via UI: **não executadas**.
- PHPUnit: **não executado** (sem `vendor/` neste ambiente).
- Playwright E2E: **não executado**.

### Veredito desta rodada
**NÃO APTO** mantido. Parity SHA, storefront, catálogo, carrinho e CEP confirmados OK em produção real. Health 94.74% (disco). O único bloqueio remanescente para APTO é a execução de mutações stateful (checkout + admin) em produção ou ambiente staging equivalente.

---

## Rodada 2026-09-19 RDC — acesso real às VMs de produção (sessão 1)

**Ambiente:** Remote Desktop Commander conectado a `shopvivaliz-free-a1` e `always-free-arm-1787907847-26`. SHA na época: `bc14c204ff9dc94d64070ff1278447f461beab27`.

### Parity SHA — PASS
- Produção: `20260919-205351-bc14c204` — alinhado com `origin/main` HEAD `bc14c204`.

### Site / Health — PASS parcial
- `https://shopvivaliz.com.br/` → HTTP 200 ✅
- Health interno: score 94.74%, status attention — 1 check falhou (disco).

### Serviços shopvivaliz-free-a1 — PASS parcial
- queue-worker, shopee-token-renewer, token-renewer, sync-safe.timer, apache2: ativos ✅
- **amazon-returns-deploy.service**: FAILED ❌

### Achados críticos — backend always-free-arm-1787907847-26
- Disco 91% (87G/96G), load average alto (4–6).
- Serviços inativos: `shopvivaliz-24x7`, `agent-bridge`, `shopvivaliz-mcp`.

### Veredito
NÃO APTO mantido. Evidências de parity e serviços coletadas; mutações stateful não validadas.

---

## Rodada 2026-09-19 — cobertura estática, sem acesso a produção
- Commit/SHA: branch `claude/auditoria-extrema-v5-97gu15` == `origin/main` == `1f8e2ca9a0a3f39c9d2106fec133f58c004d1686`.
- Ambiente: worktree isolado sem SSH/RDC. **Nenhuma evidência de produção coletada.**
- Lint PHP (`php -l`) 100% dos `.php`: **zero erros**.
- `validate-health-output.php`: COMPROVADO. `validate-asset-manifest.php`: COMPROVADO (89 entradas).
- PHPUnit: NÃO EXECUTADO (`vendor/` ausente).
- QA browser/Playwright: NÃO EXECUTADO.
- PR aberta na branch: nenhuma.
- **Veredito:** NÃO APTO mantido.

---

## Última auditoria com acesso a produção anterior
- Data: 2026-09-16.
- SHA auditado: `9200c85db53408d728572109fe54e1162a333bea`.
- Release ativo na época: `20260915-193503-b37665d3`.
- Veredito: **NÃO APTO PARA CERTIFICAÇÃO** (parity divergente + mutações stateful não validadas).

---

## Saída do NO-GO
1. Implantar artefato imutável do SHA aprovado; expor SHA em health/version.
2. Rodar navegação + carrinho + checkout em canário seguro (sem cobrança indevida).
3. Exercitar mutações administrativas seguras (dados de teste) e confirmar após reload.
4. Comparar inventário local de fluxos com conjunto realmente executado no candidato.
5. Revalidar integrações, rollback e restore; executar reauditoria contraditória.

## Regra de validade
Alteração material em checkout, catálogo, auth, integrações, infraestrutura ou deploy exige reauditoria.
