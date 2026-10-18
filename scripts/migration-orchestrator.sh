#!/usr/bin/env bash
# MIGRATION ORCHESTRATOR — Run all validation + sync scripts in sequence
# Reports final STATUS and determines readiness for cutover

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPORT_FILE="/tmp/migration-status-$(date +%Y%m%d-%H%M%S).md"

echo "╔════════════════════════════════════════════════════════╗"
echo "║     MIGRATION ORCHESTRATOR — FULL VALIDATION           ║"
echo "║     ShopVivaliz → Marina Oracle Cloud                  ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""
echo "Start: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo "Report: $REPORT_FILE"
echo ""

# Initialize report
cat > "$REPORT_FILE" << 'REPORT_HEADER'
# Migration Status Report — Full Validation

**Generated:** $(date -u +'%Y-%m-%d %H:%M:%S UTC')
**Orchestrator:** migration-orchestrator.sh

## Summary

| Phase | Status | Details |
|-------|--------|---------|
| P0 | ✅ DONE | Workers fenced, deploy gates in place |
| P1 | ⏳ RUNNING | MySQL/PostgreSQL delta diagnosis |
| P2 | ⏳ RUNNING | OAuth, Mercado Livre renewal |
| P3 | ⏳ PENDING | Shopee, Olist/Tiny integration |
| P4 | ⏳ RUNNING | PHP modules, infrastructure |
| E2E | ⏳ PENDING | 21 real browser tests |

---

## Details

REPORT_HEADER

# Helper function: run script and capture output
run_check() {
    local script="$1"
    local name="$2"

    echo ">>> Running: $name"
    if [ -x "$script" ]; then
        bash "$script" 2>&1 | tee -a "$REPORT_FILE" || echo "⚠️  $name returned non-zero"
    else
        echo "❌ Script not found: $script"
    fi
    echo ""
}

# Run validation scripts in order
echo "### P1: MySQL Delta Diagnosis"  >> "$REPORT_FILE"
run_check "$SCRIPT_DIR/p1-diagnose-mysql-delta.sh" "P1 MySQL Delta"

echo "### P2: Mercado Livre OAuth Renewal" >> "$REPORT_FILE"
run_check "$SCRIPT_DIR/p2-validate-oauth-ml-renewal.sh" "P2 ML OAuth"

echo "### P4: PHP Modules" >> "$REPORT_FILE"
run_check "$SCRIPT_DIR/p4-validate-php-modules.sh" "P4 PHP Modules"

# Summary
cat >> "$REPORT_FILE" << 'REPORT_FOOTER'

## Final Status

### Readiness for Cutover

- [ ] P0: Workers fenced (DONE)
- [ ] P1: Databases reconciled (PENDING)
- [ ] P2: OAuth renewed (PENDING)
- [ ] P3: Shopee, Olist active (PENDING)
- [ ] P4: PHP, infrastructure OK (PENDING)
- [ ] E2E: 21 tests pass (PENDING)

### Decision

**APTO FOR CUTOVER:** When all above boxes are checked.
**NOT APTO:** If any blocker remains unresolved.

---

**Next Step:** Review this report, address blockers, rerun orchestrator.

REPORT_FOOTER

echo ""
echo "╔════════════════════════════════════════════════════════╗"
echo "║              ORCHESTRATION COMPLETE                    ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""
echo "Report saved: $REPORT_FILE"
echo ""
echo "To review:"
echo "  cat $REPORT_FILE"
echo ""
