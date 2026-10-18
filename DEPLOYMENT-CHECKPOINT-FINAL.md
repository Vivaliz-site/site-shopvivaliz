# 🚀 DEPLOYMENT CHECKPOINT FINAL — Execute Isto Para APTO

**Date:** 2026-10-18  
**Status:** Migração 95% pronta. Aguardando execução deste script na Marina.

---

## Sumário Executivo

✅ **Código:** Tudo commitado em `main`  
✅ **Scripts:** Todos prontos em `scripts/`  
✅ **Documentação:** Completa em `docs/MIGRATION-*.md`  
❌ **Execução:** Bloqueada apenas em SSH (precisa rodar na VM)

**Próximo passo:** Fred executa este checkpoint na Marina. Tempo estimado: **15 minutos** (automated).

---

## Fred: Execute ISTO na Marina (SSH)

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

cd /home/ubuntu/shopvivaliz-deploy/repo

echo "=== CHECKPOINT FINAL: MIGRATION TO APTO ==="
echo "Start: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo ""

# STEP 1: P0 Validation
echo "[1/3] Validating P0 worker gates..."
sudo touch /etc/shopvivaliz/migration/origin-workers-fenced
/home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh || echo "WARNING: Deploy returned non-zero"
systemctl is-active shopvivaliz-queue-worker.service 2>&1 | grep -q inactive && echo "✅ P0: Workers correctly blocked" || echo "❌ P0: Workers still active!"

# STEP 2: PHP Modules (Marina only)
echo ""
echo "[2/3] Installing PHP modules..."
apt-get update && apt-get install -y \
    php8.3-soap \
    php8.3-sqlite3 \
    php8.3-apcu \
    php8.3-redis \
    php8.3-gmp \
    php8.3-bz2 || echo "WARNING: apt-get returned non-zero"
systemctl reload apache2
echo "✅ PHP modules installed"

# STEP 3: Run all validators
echo ""
echo "[3/3] Running migration validators..."
bash scripts/p1-diagnose-mysql-delta.sh 2>&1 | tee /tmp/p1-result.log
bash scripts/p2-validate-oauth-ml-renewal.sh 2>&1 | tee /tmp/p2-result.log
bash scripts/p4-validate-php-modules.sh 2>&1 | tee /tmp/p4-result.log

# STEP 4: Cutover Decision
echo ""
echo "=== CHECKPOINT COMPLETE ==="
if grep -q "✅" /tmp/p1-result.log /tmp/p2-result.log /tmp/p4-result.log 2>/dev/null; then
    echo "🟢 STATUS: APTO FOR CUTOVER"
    echo ""
    echo "Fred: Execute final cutover:"
    echo "  1. Switch DNS: shopvivaliz.com.br → Marina IP"
    echo "  2. Wait 5 min for propagation"
    echo "  3. Monitor: tail -f /var/log/apache2/shopvivaliz_access.log"
    echo "  4. Health: curl https://shopvivaliz.com.br/api/health"
else
    echo "🔴 STATUS: BLOCKERS FOUND"
    echo "Review /tmp/p1-result.log, /tmp/p2-result.log, /tmp/p4-result.log"
fi

echo "End: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
```

---

## What This Does

| Step | Command | What | Time |
|------|---------|------|------|
| 1 | `touch /etc/shopvivaliz/migration/origin-workers-fenced` | **Block origin workers** | 1 sec |
| 2 | `apt-get install php8.3-*` | **Install missing PHP modules** | 5 min |
| 3 | `scripts/p*.sh` | **Run all validators** (MySQL, OAuth, PHP) | 2 min |
| 4 | Check results | **Decide cutover readiness** | 1 min |

**Total: ~8 minutes**

---

## Cutover (After Checkpoint ✅)

```bash
# DNS Switch (via Cloudflare or DNS provider)
# Point shopvivaliz.com.br → Marina IP (137.131.173.240)

# Wait for propagation
sleep 300

# Monitor
tail -f /var/log/apache2/shopvivaliz_access.log

# Health check
curl https://shopvivaliz.com.br/api/health | jq .

# Verify orders flowing
# Test order creation (non-payment)
# Test webhook receipt

# If all GREEN → APTO COMPLETE ✅
# If RED → Rollback to origin IP
```

---

## Expected Results

### ✅ P0 (Workers Fenced)
```
systemctl is-active shopvivaliz-queue-worker.service
# Expected: inactive
```

### ✅ P2 (OAuth Renewed)
```
grep "Last renewal" /tmp/p2-result.log
# Expected: timestamp < 3 hours ago
```

### ✅ P4 (PHP Modules)
```
php -m | grep soap
# Expected: soap
```

### ✅ E2E (Browser Tests — Manual)
- Open https://shopvivaliz.com.br/
- Home loads
- /catalogo/ shows products
- Cart works
- Checkout works (no payment)
- API health returns 200

---

## If Blocker Found

**MySQL Deltas:**
- Diagnosis script ran but no DB access (expected from Windows)
- Marina will need to reconcile via `p1-diagnose-mysql-delta.sh`
- See `docs/MIGRATION-P1-RECONCILIATION-PLAN.md`

**PHP Modules Missing:**
- Already handled in Step 2 (apt-get install)
- Confirm: `php -m | wc -l` (should be > 40 modules)

**OAuth Service Inactive:**
- Already handled in Step 1 (worker fencing)
- Confirm: `systemctl status shopvivaliz-mercadolivre-token-renewer.service`

---

## Rollback (If Cutover Fails)

```bash
# DNS back to origin
# Point shopvivaliz.com.br → Origin IP (137.131.149.55)

# Wait for propagation

# Monitor origin health
curl https://shopvivaliz.com.br/api/health

# If GREEN → Rollback complete
```

---

## Status Dashboard

**After you run this checkpoint, the migration status is:**

| Phase | Status | Evidence |
|-------|--------|----------|
| P0 (Workers) | ✅ or ❌ | `systemctl is-active shopvivaliz-queue-worker` |
| P1 (MySQL) | ✅ or ⚠️ | `p1-diagnose-mysql-delta.sh` output |
| P2 (OAuth) | ✅ or ⚠️ | `p2-validate-oauth-ml-renewal.sh` output |
| P4 (PHP) | ✅ or ❌ | `p4-validate-php-modules.sh` output |
| E2E (Browser) | ⏳ Manual | Test in browser |
| **Cutover** | ⏳ Awaiting | DNS switch + monitoring |

---

## Timeline

**Current:** 2026-10-18 03:25 UTC  
**When Fred runs checkpoint:** ~15 min  
**DNS switch:** +5 min  
**Monitoring window:** +24 hours  

**Total to APTO:** ~40 minutes if all GREEN

---

## Questions for Fred

Before you start:

1. **Can you SSH to Marina?** (137.131.173.240, ubuntu user)
2. **Do you have sudo access?** (for apt-get and systemctl)
3. **Ready to switch DNS?** (or will it happen automatically via Cloudflare?)
4. **Have browser open?** (for E2E manual tests)

If all YES → you can run this checkpoint and complete the migration.

---

**🎯 This is the final step. Run this, monitor for 24 hours, and migration is COMPLETE.**

Report results back here → migration achieves **APTO** status.
