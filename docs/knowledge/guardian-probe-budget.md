# Guardian probe budget and readiness

The guardian timer remains enabled at 30 seconds. Healthy transport with
AUTH_FLOW, AUTH_TERMINAL or LOGGED_OUT is a successfully observed negative
session state, not an execution failure and not authenticated readiness.

The guardian reuses only negative session observations for at most 300 seconds.
Local /json/version and /json metadata, browser identity, relevant page ID/path,
and the set of RUNNING/READY_TO_COMPLETE checkpoints are compared before reuse.
No account requests or renderer/DOM evaluations occur on cache hits. A new page,
changed relevant path, browser restart or checkpoint-set change wakes the probe
on the next timer cycle. Progress-text changes alone do not defeat backoff.
Query strings, OAuth parameters and page contents are neither stored nor logged.

A same-path change without a target/checkpoint change is observed within the
300-second bound. Renderer-only hangs can therefore take up to that bound to be
rechecked during negative auth. Failed local transport bypasses the cache and
uses the existing recovery path immediately. Positive authentication and unknown
states are never reused as fresh observations. An authorized operator can run
CHATGPT_BROWSER_FORCE_SESSION_PROBE=1 for a one-shot fresh observation.

Health updated_at is the current heartbeat; session_observed_at is the time of
the actual session probe and is NOT advanced by cache hits. probe_skipped marks
a reused negative state; authenticated stays false and degraded stays true.
Installation and authenticated readiness remain separate: the install script
requires a real authenticated observation within 90 seconds, never oneshot exit
status alone. No login, CAPTCHA, consent or platform safety check is bypassed.

Unit/loopback tests isolate GUI and systemd; these are not authenticated account
E2E evidence. Post-deployment validation must observe timer cache hits, unchanged
session_observed_at, advancing heartbeat, and an independent readiness failure
while authentication is pending. Never stop automatic continuation for this test.
