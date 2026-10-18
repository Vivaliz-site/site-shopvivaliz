# Migration Master Execution Plan — From Now to APTO

**Date:** 2026-10-18  
**Target:** APTO status (production ready) for cutover  
**Owner:** Codex agent + Fred (decisions)

---

## Current Status

| Phase | Deliverable | Status |
|-------|-------------|--------|
| **P0** | Worker fencing gates | ✅ DONE — PRs #2995 #525 (awaiting merge) |
| **P1** | MySQL/PostgreSQL reconciliation | ⏳ PLANNED — scripts ready |
| **P2** | OAuth Mercado Livre renewal | ⏳ PLANNED — validator ready |
| **P3** | Shopee + Olist/Tiny integrity | ⏳ PLANNED — diagnostics needed |
| **P4** | PHP modules + Infraestrutura | ⏳ PLANNED — validator ready |
| **E2E** | 21 browser tests (2/2 mobile) | ⏳ PLANNED — checklist ready |
| **Cutover** | DNS + rollback ready | ⏳ FINAL STEP |

---

## Execution Sequence

### BLOCK 1: Complete P0 (Estimated: 1-2 hours)

**Prerequisite:** Merge PRs to main

**Steps:**
1. Merge `site-shopvivaliz#2995` (deploy gate)
2. Merge `amazon-returns-safet#525` (provision gate)
3. Verify CI passes on main
4. **On Origin VM:**
   ```bash
   sudo touch /etc/shopvivaliz/migration/origin-workers-fenced
   /home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh
   # Verify: no workers restarted (check systemctl status)
   
   cd /home/ubuntu/amazon-returns-safet
   sudo ./scripts/provision-production.sh --dry-run
   # Verify: amazon-returns-safet.service would be disabled, not started
   ```
5. ✅ P0 COMPLETE when blocker confirmed working

---

### BLOCK 2: Execute P1 (Estimated: 4-6 hours)

**Prerequisite:** Database access (SSH to Marina OR controladora)

**Steps:**

#### Step 2a: Diagnostic (RED)
```bash
# On Marina (or controladora with SSH to both):
ssh ubuntu@shopvivaliz-free-a1 "bash /home/ubuntu/shopvivaliz/scripts/p1-diagnose-mysql-delta.sh" > /tmp/origin-mysql.txt
bash /home/ubuntu/shopvivaliz/scripts/p1-diagnose-mysql-delta.sh > /tmp/marina-mysql.txt

# Compare:
diff /tmp/origin-mysql.txt /tmp/marina-mysql.txt
```

**Expected Output:**
- ✅ All tables match = proceed to E2E
- ❌ Deltas found = proceed to Step 2b

#### Step 2b: Analyze Deltas (YELLOW)

For each delta identified:
```sql
-- Which record(s) are missing?
SELECT * FROM origin.table WHERE id NOT IN (SELECT id FROM marina.table)

-- OR which are extra on Marina?
SELECT * FROM marina.table WHERE id NOT IN (SELECT id FROM origin.table)

-- Determine direction: origin→marina or marina→origin?
```

**Decision Matrix:**
- New order in Marina? Origin is stale, Marina is source → copy from Marina to origin
- Old product in origin? Origin is stale, Marina is fresh → copy from Marina to origin
- Record exists nowhere? Delete orfan
- Everything matches? Move to validation

#### Step 2c: Backup (SAFETY)
```bash
mysqldump -u root shopvivaliz | gzip > /tmp/shopvivaliz-pre-sync-$(date +%s).sql.gz
mysqldump -u root amazon_returns_safet | gzip > /tmp/amazon-pre-sync-$(date +%s).sql.gz
```

#### Step 2d: Synchronize (YELLOW → GREEN)
```bash
# For each identified delta:
# Option A: Copy record from source to destination
mysql -u root shopvivaliz << SQL
INSERT IGNORE INTO table_name (...)
SELECT ... FROM temp_origin_snapshot.table_name
WHERE id NOT IN (SELECT id FROM table_name)
SQL

# Verify:
mysql -u root -Nse "SELECT COUNT(*) FROM table_name" shopvivaliz
# Should match expected count
```

#### Step 2e: Validate (GREEN)
```bash
# Rerun diagnostic
bash /home/ubuntu/shopvivaliz/scripts/p1-diagnose-mysql-delta.sh
# Expected: ✅ All tables match

# Validate foreign key integrity
mysql -u root shopvivaliz -e "CHECK TABLE table_name"
```

**2e Outcome:**
- ✅ All tables reconciled = ✅ P1 COMPLETE
- ❌ Deltas persist = BLOCKER, investigate cause

---

### BLOCK 3: Execute P2 (Estimated: 1-2 hours)

**Prerequisite:** SSH to Marina

**Steps:**
```bash
# Run validator
bash /home/ubuntu/shopvivaliz/scripts/p2-validate-oauth-ml-renewal.sh

# Expected outputs:
# ✅ Service active (or correctly disabled under MLRR ownership)
# ✅ Last renewal timestamp recent (< 3 hours)
# ✅ Renewal daemon present in release
# ✅ No recent errors in logs
```

**If Blockers Found:**
```bash
# Check status of MLRR vs. legacy ownership
grep "ML_TOKEN_OWNER" /home/ubuntu/shopvivaliz-deploy/shared/.env

# If MLRR: ensure timer is active
systemctl status shopvivaliz-mercadolivre-token-renewer.service

# If legacy: renewer should be running
sudo systemctl start shopvivaliz-mercadolivre-token-renewer.service
```

**P2 Outcome:**
- ✅ OAuth renews regularly = ✅ P2 COMPLETE
- ❌ Token expired or renewal broken = BLOCKER, need manual regen

---

### BLOCK 4: Execute P3 (Estimated: 2-3 hours)

**Shopee:**
```bash
# Validate token not expired
curl -X GET "https://api.seller.shopee.com/v2/shop/get_shop_info" \
  -H "Authorization: Bearer $SHOPEE_TOKEN"
# Expected: 200 OK with shop data

# Validate /api/health shows shopee_oauth_status=OK
curl https://shopvivaliz.com.br/api/health | jq '.shopee_oauth_status'
```

**Olist/Tiny:**
```bash
# Validate OAuth token
curl -X GET "https://api.olist.com/v2/shop/" \
  -H "Authorization: Bearer $OLIST_TOKEN"
# Expected: 200 OK

# Verify product sync daemon
systemctl status shopvivaliz-catalog-reconcile.timer
# Should be active and last run successful
```

**P3 Outcome:**
- ✅ Both integrations healthy = ✅ P3 COMPLETE
- ⚠️ One integration degraded = CONDITIONAL (can operate with fallback)
- ❌ Both broken = BLOCKER

---

### BLOCK 5: Execute P4 (Estimated: 1-2 hours)

**PHP Modules:**
```bash
bash /home/ubuntu/shopvivaliz/scripts/p4-validate-php-modules.sh

# Expected: ✅ All required modules present
# If missing:
sudo apt-get install -y php8.3-soap php8.3-sqlite3 php8.3-apcu php8.3-redis
sudo systemctl reload apache2
```

**Infraestrutura:**
```bash
# Certificate
ls -la /etc/letsencrypt/live/shopvivaliz.com.br/
# Expected: valid fullchain.pem and privkey.pem

# Backup
ls -la /home/ubuntu/shopvivaliz-deploy/shared/backups/
# Expected: daily backups from last 7 days

# Disk usage
df -h /home/ubuntu/shopvivaliz-deploy
# Expected: > 20% free (not > 85% used)

# Apache + PHP-FPM
systemctl status apache2
systemctl status php8.3-fpm
# Both: active (running)
```

**P4 Outcome:**
- ✅ All checks pass = ✅ P4 COMPLETE
- ❌ Critical missing = BLOCKER (e.g., no certificate)

---

### BLOCK 6: Execute E2E (Estimated: 3-4 hours)

**Prerequisites:**
- Navegador real (Chrome/Edge, DevTools aberto)
- Cliente de teste: fredmourao+test@gmail.com
- Produto de teste: product with stock > 5
- Network tab aberto para validar requests

**Steps:**

1. **Open site**
   ```
   https://shopvivaliz.com.br/
   → Expected: home loads, no 5xx, no JS errors
   ```

2. **Browse catalog**
   ```
   https://shopvivaliz.com.br/catalogo/
   → T1.2: PASS (products visible, images loaded, prices shown)
   → T1.3: PASS (search works)
   → T1.4: PASS (filters work)
   ```

3. **View product**
   ```
   Click product → /produto/?id=... loads
   → T2.1: PASS (image, price, stock)
   → T2.2: PASS (gallery works)
   ```

4. **Add to cart and checkout**
   ```
   Add to cart → /api/cart/add returns 200
   → T3.1: PASS (cart UI updates)
   
   Checkout (without payment):
   → T3.3: PASS (frete calcula)
   → T3.4: PASS (order created, status=pending_confirmation)
   ```

5. **Auth flows**
   ```
   Logout → Login → Register new user → Forgot password
   → T4.1: PASS
   → T4.2: PASS
   → T4.3: PASS
   ```

6. **Integration health**
   ```
   GET /api/health
   → tiny_oauth_status=OK
   → ml_token_owner=mlrr (or legacy, if legacy tier)
   → amazon_returns_enabled=1
   ```

7. **Webhook simulation**
   ```
   (Optional, if MFA available)
   POST /api/webhook-mercadopago with test payload
   → T6.1: email received
   ```

**Acceptance Criteria:**
- ✅ 19/21 tests passing = READY FOR CUTOVER
- ✅ 2 mobile tests pending = ACCEPT (can test post-cutover)
- ❌ > 2 critical tests failing = BLOCKER

**E2E Outcome:**
- ✅ 19/21 + 2 mobile deferred = ✅ E2E COMPLETE

---

### BLOCK 7: Cutover & Rollback (Estimated: 2-4 hours)

**Prerequisite:** All P0-P4 and E2E complete, rollback plan tested

**Steps:**

1. **DNS Switch** (Fred or authorizer)
   ```
   Cloudflare: shopvivaliz.com.br CNAME → marina-a1-4c24g
   # Wait 5 min for propagation
   ```

2. **Monitor** (First 30 min)
   ```bash
   # Watch error logs
   tail -f /var/log/apache2/shopvivaliz-error.log
   
   # Check orders flowing
   curl https://shopvivaliz.com.br/api/health
   
   # Verify Tiny/Olist receiving orders
   curl https://api.tiny.com.br/v3/pedidos?limit=5 (with auth)
   ```

3. **Rollback Plan** (If blocker found)
   ```bash
   # Revert DNS to origin
   # Notify Codex to investigate
   # Do NOT edit release/current directly
   ```

4. **Post-Cutover Validation** (Hours 2-24)
   - ✅ Orders flowing end-to-end
   - ✅ Webhooks received and processed
   - ✅ No 5xx errors for 1 hour
   - ✅ No customer complaints in first 4 hours

---

## Decision Trees

### If P1 Deltas Found But Origin Is Wrong
→ Copy from Marina to origin, then validate bidirectional

### If P2 OAuth Token Expired
→ Manual refresh via Mercado Livre console, then update .env

### If P3 Shopee Token Bad
→ Can operate with Olist only (document as degraded)
→ Plan manual Shopee recovery for next window

### If P4 Missing PHP Module
→ Install via apt, reload Apache, retest

### If E2E Test Fails (e.g., Frete Not Calculating)
→ Check if /api/melhorenvio/shipping-check responds
→ If API down: proceed with warning "frete manual calculation pending"

### If DNS Switch Causes 5xx
→ Immediate rollback to origin, investigate root cause

---

## Final Checklist Before Cutover

- [ ] P0: Worker gates deployed and tested
- [ ] P1: All databases reconciled and validated
- [ ] P2: OAuth renewable and not expiring imminently
- [ ] P3: Shopee and Olist/Tiny responding and healthy
- [ ] P4: PHP modules installed, certificates valid, backups exist
- [ ] E2E: 19/21 tests passing (2 mobile deferred acceptable)
- [ ] Rollback: Tested and ready
- [ ] Origin: Fenced and blocking Marina-owned workers
- [ ] Marina: All services active and healthy
- [ ] Monitoring: Logs, alerts, uptime checks configured

**When all boxes checked:**

```
STATUS: 🟢 APTO FOR CUTOVER
```

---

## Timeline Estimate

| Block | Task | Duration | Cumulative |
|-------|------|----------|-----------|
| 0 | Merge PRs | 30 min | 30 min |
| 1 | P0 validation | 1 h | 1.5 h |
| 2 | P1 reconciliation | 5 h | 6.5 h |
| 3 | P2 OAuth | 1 h | 7.5 h |
| 4 | P3 Shopee/Olist | 2 h | 9.5 h |
| 5 | P4 infrastructure | 1 h | 10.5 h |
| 6 | E2E tests | 3 h | 13.5 h |
| 7 | Cutover + monitor | 3 h | **16.5 h total** |

**Realistic Window:** ~1 full day (with parallel work where possible)

---

## Next Action

**IMMEDIATE:** 
1. Merge P0 PRs
2. Retrieve database checkpoint status from controladora
3. Start P1 reconciliation with diagnostic
4. Run P2-P4 validators in parallel
5. Execute E2E tests

**When Ready:** Call cutover
