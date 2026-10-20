# Marina abandoned-cart recovery: single writer and final DB delta

**STATUS: DRAFT / NOT INSTALLED / LIVE SOURCE REMAINS EXPOSED TO RECOVERY JOBS.**

At 2026-10-20 10:35 UTC the source VM shopvivaliz-free-a1 remained protected by
root-owned /etc/shopvivaliz/migration/origin-workers-fenced, and its four
migrated workers (queue, MLRR, Pricing, SAFE-T) were inactive/disabled.
However the old source still had BOTH a user ubuntu cron every 30 minutes
and an enabled/active systemd shopvivaliz-abandoned-cart-recovery.timer.
The Marina had its own enabled/active canonical systemd timer and no user
cron. All recent recovered-email logs examined showed 0 sent / 0 failed /
0 candidates; no duplicate delivery was established, but the old source
scheduler can query a stale database, modify abandonment rows and send mail.

## Root cause

1. scripts/send-abandoned-cart-emails.php previously did not inspect the
   origin-workers-fenced marker before bootstrapping PDO or calling the mailer.
2. User cron on the source used /var/lock/shopvivaliz-abandoned-cart-email.lock;
   systemd used shared/locks/abandoned-cart-recovery.lock, so they were
   independently scheduled writers, without a mutual lock.
3. scripts/install-abandoned-cart-recovery-service.sh always installed/enabled
   the timer, including when invoked on a fenced source. The GitHub
   abandoned-cart-cron-install workflow also installed the cron on push and
   did not consult the fence; its SSH transport disabled StrictHostKeyChecking.
4. Both VMs held the same web release 99416b61, but final 102-table exact
   COUNT(*) comparison showed source 14,704 rows versus Marina 14,701.
   Differences were isolated to blog_articles (59 versus 58) and
   email_campaign_runs (53 versus 51). Source-only blog article ID 30430 was
   scheduled. Common article ID 16206 was published on source at 2026-10-19
   13:00 UTC, but remained scheduled on Marina, without source's newer content
   metadata. Source-only email_campaign_runs IDs 52 and 53 were completed
   with zero recipients/sends/failures. Other common campaign records matched.
   No blog draft payload, recipient PII, email credentials or customer records
   have been transferred or logged.

## This DRAFT prevention (NOT DEPLOYED)

- Fenced source scripts return
  ABANDONED_CART_ORIGIN_FENCED=SKIP before any DB or mail code.
- The source systemd sender has
  ConditionPathExists=!/etc/shopvivaliz/migration/origin-workers-fenced.
- The installer **fails closed** on a fenced origin BEFORE root checks or
  writing unit files, with exit code 75 and
  `ORIGIN_CART_MAINTENANCE_LEASE_REQUIRED=NO_MUTATION`. Root does NOT replace
  a valid maintenance lease. The operator must stop+disable the source timer
  **through the official broker under a scoped lease and fencing token**, then
  reconcile the extra source cron using the same authorized maintenance
  transaction. On an approved non-fenced Marina host the installer may enable
  the designated timer.
- The legacy cron installer rejects a fenced source, prefers the active
  canonical Marina timer, uses a shared file lock when timer is absent, and
  verifies SSH host keys (no StrictHostKeyChecking=no).
- Unit and code regressions ensure the above are not silently dropped.

This branch/PR deliberately remains DRAFT until maintenance access is
available.

**2026-10-20 security regression:** the previous staged installer called
`systemctl disable --now` when detecting the root-owned origin fence, but
had no way to validate the control-plane runtime lease. That was an unsafe
privilege-equivalence assumption. Fixed in the **unmerged** branch by returning
exit 75 before all root/systemd operations. RED reproduced on the real Marina
with a synthetic fenced-origin fixture; GREEN 5/5 tests on Marina and a staged
read-only command on the actual fenced origin returned exactly 75, while the
timer remained active/enabled. No source cron/service was changed.

**Official operator capability gap:** the remote service_action tool currently
accepts stop/start/restart with a valid existing runtime lease/fencing token;
there is no externally exposed approved lease issuance operation in the
available MCP tools, and stop alone does not persist across reboot. See
[operator-owned maintenance capability issue #3169](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3169).
 A push/merge of scripts/send-abandoned-cart-emails.php can trigger
a live GitHub workflow that modifies cron or production services; merging
without the appropriate host lease is not an acceptable workaround.

## Binding ownership and repair gates

The official ShopVivaliz Remote Control
service_action(shopvivaliz-free-a1,
shopvivaliz-abandoned-cart-recovery.timer, stop) was explicitly denied:
runtime_lock_required, audit 9197c5d2-9546-4900-ba74-56e24ac7a584.
Do not use RDC shell, task_submit, SSH, GitHub workflow, OCI Run Command
or another connector to bypass this refusal. Acquire a real correctly scoped
runtime maintenance lease/fencing token through the authorized broker and
re-run the official action. Validate source timer disabled through restart,
user cron removed under the same authorization and Marina sole timer
active/enabled. Source remains a rollback asset, do not delete it.

Do not directly copy blog content or campaign rows through chat/tool logs,
and do not re-run or duplicate campaign sends. The blog ID 16206 discrepancy
requires an authorized selective CAS/data reconciliation with exact source
and target version validation, backed-up source/target, production editor
ownership and real UI verification. Source-only scheduled ID 30430 and two
zero-send campaign logs require explicit business-safe reconciliation. Avoid
blind SQL replacement or overriding newer Marina edits.

The previous protected Supabase data-transfer refusal remains binding,
as does the root/privilege refusal on Marina. No cutover or deletion permitted.
GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION.

Evidence: GitHub issue #3163, the private Marina migration checkpoint, and
the tested Pricing snapshot in /home/ubuntu/migration-checks/pricing-db/.
