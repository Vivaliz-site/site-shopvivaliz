# OKX 20-layer PAPER decision engine validation

## Implemented
- Exact 20 ordered analytical layers, ending in adversarial review.
- Strict whole-response JSON parsing and finite numeric validation.
- gpt-5.6-sol model identity enforcement; medium effort; web search disabled.
- Maximum two asynchronous decisions in flight; position protection remains non-blocking.
- Public OKX decision context: 5m/1h/4h candles, top order book, open interest and funding where applicable.
- PAPER portfolio/risk state only; no exchange secrets or live-order surface.
- Deterministic Risk Gateway remains authoritative; no heuristic fallback in the production runner.
- Bridge health requires a real refresh-token check, preventing stale account metadata from reporting authenticated.

## Test evidence
- AI Squad Codex bridge unit test PASS.
- Runtime Python suite: 47 tests PASS before auth-expiry gate.
- Alternate-port candidate bridge correctly discovered both configured ChatGPT profiles.
- Direct app-server turn probe proved both persisted refresh tokens are revoked. This is an external authentication gate: model-turn E2E cannot be certified until a profile is reauthenticated.

## Rollout gate
Do not deploy the new decision engine into the 24/7 PAPER service until a fresh real gpt-5.6-sol turn through the candidate bridge succeeds and a real 20-layer OKX decision parses successfully. Existing PAPER service stays on the previously validated release meanwhile.
