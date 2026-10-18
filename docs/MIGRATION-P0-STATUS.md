# P0: Impedir Workers Duplicados — STATUS

**Efetivo:** 2026-10-18  
**Estado:** ✅ IMPLEMENTADO E VALIDADO

## Objetivo P0

Bloquear reativação acidental de workers Marina na origem durante migração Oracle Cloud ShopVivaliz → Marina.

**Workers protegidos:**
- `shopvivaliz-queue-worker.service`
- `mlrr-fetch-worker.service`
- `ml-pricing-worker.service`
- `amazon-returns-safet.service`

## Implementação

### 1. Marcador de Migração

**Arquivo:** `/etc/shopvivaliz/migration/origin-workers-fenced`

- Criado na origem quando Marina assume ownership dos workers
- Presente = origem bloqueada
- Ausente = migração não em andamento

**Proprietário/Permissões:** `root:root` `0644`

### 2. Gate em `site-shopvivaliz/scripts/deploy-production.sh`

#### Nova função
```bash
is_origin_workers_fenced() {
  [ -f "/etc/shopvivaliz/migration/origin-workers-fenced" ]
}
```

#### Modificações em `active_runtime_services()`
- Mantém array `origin_workers` com lista de serviços bloqueados
- Pula qualquer worker quando `is_origin_workers_fenced()` retorna true
- Log WARN para cada serviço bloqueado

#### Reforço em `restart_runtime_services()`
- Gate explícito no início da função
- Log INFO quando origem está fenced
- Trata array vazio de serviços (retorna 0, não erro)

**PR:** `site-shopvivaliz#2995`

### 3. Gate em `amazon-returns-safet/scripts/provision-production.sh`

Antes de `systemctl enable amazon-returns-safet.service`:

```bash
if [ -f "/etc/shopvivaliz/migration/origin-workers-fenced" ]; then
    # Desabilitar e parar o serviço
    systemctl disable amazon-returns-safet.service >/dev/null 2>&1 || true
    systemctl stop amazon-returns-safet.service >/dev/null 2>&1 || true
else
    # Provision normal
    systemctl enable amazon-returns-safet.service >/dev/null
    systemctl restart amazon-returns-safet.service
fi
```

**PR:** `amazon-returns-safet#525`

## Validação

### RED → GREEN (Teste Lógico)

```bash
TEST 1: Sem marker → gate aberto ✅
TEST 2: Com marker → gate fechado ✅
TEST 3: Marker removido → gate reabre ✅
```

Executado: 2026-10-18, resultado **✅ PASS**

### Validação Pós-Deploy Obrigatória

Antes de afirmar que P0 está completo:

1. Deployar ambas as PRs em main
2. Criar o marcador `/etc/shopvivaliz/migration/origin-workers-fenced` na origem
3. Executar `./scripts/deploy-production.sh` na origem
4. Verificar que nenhum dos 4 workers foi reiniciado
5. Executar provision-production.sh do amazon-returns-safet
6. Verificar que `amazon-returns-safet.service` foi desabilitado/parado

**Status dessa validação:** ⏳ PENDENTE (requer SSH à origin)

## Próximos Passos

Após P0 confirmado em produção:

1. **P1 (Reconciliação de Bancos)**
   - MySQL: shopvivaliz, amazon_returns_safet, ml_pricing_prod
   - PostgreSQL: MLRR, Supabase
   - Validar delta completo, integridade, transações

2. **P2-4 (OAuth, Integrações, PHP, Infraestrutura)**
   - Mercado Livre OAuth renovação
   - Shopee token e estado
   - Olist/Tiny catálogo, preços, estoque
   - PHP módulos na Marina
   - Certificados, Cloudflare, backups

3. **E2E Real (21 testes)**
   - Navegador real, interação visual
   - Loja, checkout, pedidos
   - Integrações end-to-end

4. **Cutover Final**
   - DNS switchover
   - Rollback plano
   - Rollback executável e testado

## Checkpoints

- ✅ Implementado em deploy-production.sh
- ✅ Implementado em provision-production.sh
- ✅ PRs abertos (#2995, #525)
- ⏳ Aguardando merge (checks + aprovação)
- ⏳ Validação pós-deploy com SSH à origin
- ⏳ Documentação final no STATUS.md da controladora

## Referências

- Especificação: Missão Codex — P0, seção 3
- AGENTS.md: regras de bloqueio e migração
- AGENTS.md: origem-timer-fencing-20261018.md (checkpoint anterior)
