# ChatGPT Continuity Structural Optimization Spec

## Goal
Keep continuity effective for real nonterminal checkpoints while eliminating account/browser activity when no task needs recovery, preventing concurrent browser ownership, and treating platform additional-check states as cooldown rather than retry pressure.

## Required behavior
- With no RUNNING/READY_TO_COMPLETE checkpoint, reinforcement performs no browser/account discovery or send and publishes a healthy `idle_no_checkpoint` heartbeat.
- `idle_no_checkpoint` clears stale recovery degradation/detail from earlier failures; history remains in logs, not in current health.
- AUTH_FLOW/AUTH_TERMINAL/LOGGED_OUT suppress recovery activity; browser authentication health remains independently observable.
- `additional_checks` causes a five-minute no-browser/no-account cooldown with heartbeat renewal and stops the current candidate sweep.
- Only the canonical browser guardian owns browser recovery; the legacy one-minute CDP healthcheck is absent/disabled.
- Installer activates the corrected worker before final guardian validation and fails closed if required health is not achieved.
- No edits occur in `current/` or an active immutable release.

## Validation
Unit tests must prove current-health state transitions and zero recovery calls while idle/cooldown. Runtime validation must prove a single guardian, installed worker parity with main, fresh idle heartbeat, and zero discovery/send events during an idle observation window.
