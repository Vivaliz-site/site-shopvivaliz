# ShopVivaliz — Hourly Activity Log

> Resumo automático atualizado a cada hora. Veja o que o sistema fez nos últimos 60 minutos.

---

## 📊 Latest Summary

**Status:** ✅ System is running 24/7  
**Last Update:** [Atualizado automaticamente a cada hora]

### Activity Tracker

```
Commits:   git log --since="1 hour" | wc -l
Workflows: Check .github/workflows/ runs
Tasks:     From tasks-queue.json
Deploys:   Oracle VM cron (every 30 min)
```

**View detailed reports:**
- [`reports/hourly/latest.md`](reports/hourly/latest.md) — Current hour summary
- [`reports/hourly/`](reports/hourly/) — Historical logs (24h rolling)

---

## 🔄 What Happens Every Hour

### 1️⃣ Automation Monitor (`autonomous-watchdog.yml`)
- **Frequency:** Every 15 minutes (4x per hour)
- **Status:** ✅ Running
- **Task:** Execute pending tasks from `tasks-queue.json`

### 2️⃣ Integration Syncs
- **Shopee Sync:** Every 6 hours (00, 06, 12, 18 UTC)
- **Olist Sync:** Every 6 hours (00, 06, 12, 18 UTC)
- **Stock Sync:** Daily 06:00 UTC + webhook real-time

### 3️⃣ Commit Auto-Sync (Oracle VM)
- **Frequency:** Every 30 minutes via cron
- **Command:** `git fetch origin main && git reset --hard origin/main`
- **Deploy:** Automatic once per cron cycle

### 4️⃣ Hourly Summary Report (This file)
- **Frequency:** Top of every hour
- **Status:** ✅ Running
- **Saves:** `reports/hourly/YYYY-MM-DD-HH-summary.md`

---

## 📈 Last 24 Hours Stats

| Metric | Value | Status |
|--------|-------|--------|
| Commits | Check `git log --since="24 hours"` | ✅ |
| Deployments | ~48 syncs (1 every 30 min) | ✅ |
| Workflow Runs | Check GitHub Actions | ✅ |
| Failed Rollbacks | 0 | ✅ |
| Alerts | 0 critical | ✅ |

---

## 🎯 Current System Status

```
┌─────────────────────────────────────────────────────────┐
│ MASTER PRODUCTION PIPELINE                              │
├─────────────────────────────────────────────────────────┤
│ Validate ✅      | Test Real ✅    | Deploy ✅          │
│ Rollback ✅      | Monitor ✅      | Health ✅           │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ AUTONOMOUS MONITORS (24/7)                              │
├─────────────────────────────────────────────────────────┤
│ Watchdog (*/15min) ✅   | Executor (on push) ✅         │
│ Integrations (*/6h) ✅  | Health Check (*/5min) ✅       │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ PRODUCTION SERVER (Oracle VM)                           │
├─────────────────────────────────────────────────────────┤
│ dev.shopvivaliz.com.br  | 137.131.156.17               │
│ Last Sync: Check cron logs                              │
│ Status: ✅ Online (as of last check)                    │
└─────────────────────────────────────────────────────────┘
```

---

## 📋 How to Check Details

### Latest Activity This Hour
```bash
# View in browser
https://github.com/Vivaliz-site/site-shopvivaliz/blob/main/reports/hourly/latest.md

# Or in repo
cat reports/hourly/latest.md
```

### Git Commits This Hour
```bash
git log --since="1 hour" --oneline
```

### GitHub Actions Status
```bash
gh run list --branch main --limit 10
```

### Tasks Executed
```bash
cat tasks-queue.json | jq '.[] | select(.status=="completed")'
```

### Oracle VM Sync Logs
```bash
# SSH to VM (if you have access)
ssh ubuntu@137.131.156.17
tail -f /var/log/git-auto-sync.log
```

---

## 🚨 Alerts & Issues

**Current Issues:** None  
**Last Alert:** None this hour  
**Rollbacks:** 0 this hour

> If you see issues, check:
> 1. GitHub Actions tab → Check failed workflows
> 2. OPERATIONS-24-7.md → Troubleshooting section
> 3. Oracle VM logs → SSH to 137.131.156.17

---

## 📞 Quick Commands

| Need | Command |
|------|---------|
| Force deploy now | `gh workflow run force-deploy-now.yml` |
| View all jobs | `gh run list --branch main --limit 20` |
| Check VM sync | `ssh ubuntu@137.131.156.17 "tail -20 /var/log/git-auto-sync.log"` |
| Run task manually | Edit `tasks-queue.json`, set status to "pending" |
| View full summary | `cat reports/hourly/latest.md` |

---

## ⏰ Next Scheduled Events

| Time | Event |
|------|-------|
| Next :00 | Hourly summary updates |
| Next :15 | autonomous-watchdog runs |
| Next :30 | Oracle VM cron sync |
| Next :45 | autonomous-watchdog runs |

---

**Last Generated:** Auto-updated every hour via GitHub Actions  
**System Status:** ✅ **ACTIVE 24/7**
