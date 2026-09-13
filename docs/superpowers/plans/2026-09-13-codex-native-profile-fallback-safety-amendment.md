# Codex Native Profile Failover Safety Amendment

**Date:** 2026-09-13
**Applies to:** `docs/superpowers/plans/2026-09-13-codex-native-profile-fallback.md`

This amendment was explicitly approved before implementation and overrides any earlier Task 2 wording that could be read as retrying the real Codex task on the alternate profile.

## Required behavior

Before every model-using invocation (`exec`, `e`, `review`, or a new interactive session), the selector performs a bounded, ephemeral, read-only availability probe against the preferred native ChatGPT profile. The alternate profile is probed only when the preferred profile fails with a recognized authentication/capacity signature.

The real requested task starts only after a usable profile has been selected. Once the real task starts, it is executed exactly once. If that task later returns a failover-worthy usage/authentication condition, the wrapper must not replay the task automatically because the first execution may already have produced repository or external side effects. Instead, the alternate profile becomes preferred for the next explicit invocation.

Administrative commands and flags that do not consume the model, including `login status`, `doctor`, `--version`, `-V`, `--help`, and `-h`, do not run model probes.

The selector never launches `codex login`, device authorization, or credential collection automatically. Network/time-out failures and user cancellation do not cause profile switching.

## Validation additions

Tests must prove that a capacity failure after the real task begins results in exactly one real-task execution, while the next preferred profile changes to the alternate account. Tests must also prove that administrative version/help commands are probe-free and that no recurring or background paid-AI process is introduced.
