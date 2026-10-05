# ShopVivaliz OKX Trading Pilot

Staged OKX analysis and risk-control subsystem.

Modes:
- SHADOW: real reads/analysis, no writes.
- PAPER: simulated execution with costs.
- LIVE_PILOT: intentionally not exposed by the CLI in this implementation; real order submission requires a separate external execution approval path.

Hard limits are defined in `okx_pilot/config.py`. Withdrawals are prohibited.
