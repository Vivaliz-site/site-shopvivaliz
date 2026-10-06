# Scope-specific validation, 2026-10-05

- Investigated candidate symlink pointing to the active release; rebuilt in a real isolated worktree.
- Regression tests first reproduced candidate starvation, missing initial persistence, marked-loss omission, wrong reset timezone, stale exits, missing X-Perps funding, unsupported instrument fallback and missing size rounding.
- 34 tests cover the corrected code, including closed-trade fee/funding reconciliation and invalid-state fail-closed behavior.
- Public-data smoke observed all three supported instrument classes and simulated entries in SWAP and FUTURES under US$100.
- Restart smoke retained the same run ID, start time, cash equity and all open positions.
- This is a baseline simulator validation, not validation of the full AI trading architecture or readiness for real money.
- SUPERPOWERS_UNAVAILABLE; GEPETO_UNAVAILABLE in this ChatGPT runtime. No independent agent review is claimed.
