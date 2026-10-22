# Marina abandoned-cart recovery: single writer and final DB delta

**STATUS: DRAFT / NOT INSTALLED / LIVE SOURCE REMAINS EXPOSED TO RECOVERY JOBS.**

The source VM `shopvivaliz-free-a1` has the root-owned
`/etc/shopvivaliz/migration/origin-workers-fenced` marker, while its legacy
abandoned-cart scheduler still requires an authorized maintenance transaction
to stop, disable and reconcile. Marina remains the canonical writer.

## Root cause

1. `scripts/send-abandoned-cart-emails.php` did not inspect the origin fence
   before bootstrapping PDO or loading the mailer.
2. The source cron and systemd timer used different lock paths, so they were
   independent writers.
3. The installer enabled the timer even on a fenced source, and the cron
   workflow used unverified SSH host keys and could create a second scheduler.

## Implemented safety contract

- A fenced source returns `ABANDONED_CART_ORIGIN_FENCED=SKIP` before any DB or
  mail code is loaded.
- The systemd service has
  `ConditionPathExists=!/etc/shopvivaliz/migration/origin-workers-fenced` and
  uses the shared recovery lock.
- The privileged installer fails closed before root checks or writes, with
  exit code 75 and
  `ORIGIN_CART_MAINTENANCE_LEASE_REQUIRED=NO_MUTATION`.
- The cron workflow requires verified host keys, rejects the fenced source,
  refuses a non-Marina target, and exits when the canonical Marina timer is
  already active and enabled instead of installing a second cron.
- Static, syntax, negative, and positive side-effect tests cover the contract.

## Required operational gate

The installer cannot validate or manufacture the official control-plane
maintenance lease. The source timer must be stopped and disabled through the
approved broker under a scoped lease and fencing token; the extra source cron
must be removed in the same authorized transaction. Do not bypass a
`runtime_lock_required` refusal with SSH, RDC shell, task submission, GitHub
workflow, or OCI commands.

After that transaction, verify across restart that the source timer is disabled,
the source user cron is absent, and Marina's timer is the only active/enabled
writer. Keep the source as a rollback asset. No cutover or decommission is
authorized until the migration audit gate is APTO.

See GitHub issues [#3163](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3163)
and [#3169](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3169) for
the migration checkpoint and the operator capability gap.
