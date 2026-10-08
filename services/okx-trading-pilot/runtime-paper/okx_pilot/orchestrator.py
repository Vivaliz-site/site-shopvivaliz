from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
import json
import time

from .decision import DecisionParser, DecisionValidationError
from .paper import PaperScenario


@dataclass(frozen=True)
class CycleResult:
    mode: str
    candidates: int
    decisions: int
    approved: int
    fills: int
    pending: int = 0
    provider_errors: int = 0


class PilotOrchestrator:
    def __init__(self, scanner, decision_provider, risk_gateway, paper_broker, audit_path: Path):
        self.scanner = scanner
        self.decision_provider = decision_provider
        self.risk_gateway = risk_gateway
        self.paper_broker = paper_broker
        self.audit_path = audit_path
        self.decision_successes_total = 0
        self.provider_errors_total = 0
        self.last_provider_error = None
        self.provider_available = False
        self._provider_retry_not_before = 0.0
        self._provider_retry_delay_seconds = 60.0
        self._pending = {}
        self._decision_pool = None
        if getattr(decision_provider, "async_mode", False):
            workers = max(1, min(int(getattr(decision_provider, "max_concurrency", 2)), 2))
            self._decision_pool = ThreadPoolExecutor(max_workers=workers, thread_name_prefix="okx-decision")
            self._max_pending = workers
        else:
            self._max_pending = 0

    @property
    def pending_count(self):
        return len(self._pending)

    @property
    def provider_cooldown_seconds(self):
        return max(0.0, self._provider_retry_not_before - time.monotonic())

    @property
    def provider_ready(self):
        return self.provider_available and self.provider_cooldown_seconds <= 0

    def close(self):
        if self._decision_pool:
            for _, future in self._pending.values():
                future.cancel()
            self._decision_pool.shutdown(wait=False, cancel_futures=True)

    def _audit(self, payload: dict):
        self.audit_path.parent.mkdir(parents=True, exist_ok=True)
        payload = {"ts": datetime.now(timezone.utc).isoformat(), **payload}
        with self.audit_path.open("a", encoding="utf-8") as f:
            f.write(json.dumps(payload, sort_keys=True, default=str) + "\n")

    def manage_positions(self, snapshots, now: datetime | None = None):
        events = self.paper_broker.manage_positions(snapshots, now=now)
        for event in events:
            self._audit({
                "event": event.kind,
                "instrument": event.instrument,
                "instrument_type": event.instrument_type,
                "pnl": str(event.pnl),
                "price": str(event.price),
                "fee": str(event.fee),
                "equity": str(self.paper_broker.equity),
            })
        return events

    def audit_funding(self, event):
        if event is None:
            return
        self._audit({
            "event": event.kind,
            "instrument": event.instrument,
            "instrument_type": event.instrument_type,
            "pnl": str(event.pnl),
            "price": str(event.price),
            "equity": str(self.paper_broker.equity),
        })

    def _provider_error(self, snap, exc):
        self.provider_errors_total += 1
        self.last_provider_error = str(exc)[:160]
        self.provider_available = False
        lowered = self.last_provider_error.lower()
        if lowered.startswith("decision_browser:") or any(marker in lowered for marker in (
            "decision_bridge:codex_unavailable",
            "decision_bridge:bridge_busy",
            "decision_bridge:timeouterror",
            "decision_bridge:urlerror",
        )):
            self._provider_retry_not_before = max(
                self._provider_retry_not_before,
                time.monotonic() + self._provider_retry_delay_seconds,
            )
        self._audit({
            "event": "DECISION_PROVIDER_ERROR",
            "instrument": snap.instrument,
            "instrument_type": snap.instrument_type.value,
            "reason": self.last_provider_error,
            "provider": getattr(self.decision_provider, "provider_name", type(self.decision_provider).__name__),
        })

    def _process_raw(self, snap, raw, now, state=None, expected_snapshot_ts=None):
        try:
            intent = DecisionParser.parse(raw, now=now)
            if expected_snapshot_ts is not None and intent.market_snapshot_ts != expected_snapshot_ts:
                raise DecisionValidationError("market_snapshot_timestamp_mismatch")
        except DecisionValidationError as exc:
            self._audit({
                "event": "DECISION_REJECT",
                "instrument": snap.instrument,
                "instrument_type": snap.instrument_type.value,
                "decision_id": raw.get("decision_id") if isinstance(raw, dict) else None,
                "reason": str(exc),
            })
            return 1, 0, 0

        self.decision_successes_total += 1
        self.last_provider_error = None
        self.provider_available = True
        effective_state = state if state is not None else self.paper_broker.pilot_state()
        verdict = self.risk_gateway.authorize(intent, snap, effective_state, now=now)
        self._audit({
            "event": "DECISION",
            "instrument": snap.instrument,
            "instrument_type": snap.instrument_type.value,
            "decision_id": intent.decision_id,
            "decision": intent.decision.value,
            "direction": intent.direction.value,
            "confidence": str(intent.confidence),
            "expected_rr": str(intent.expected_rr),
            "time_horizon": intent.time_horizon,
            "supporting_evidence": list(intent.supporting_evidence),
            "contrary_evidence": list(intent.contrary_evidence),
            "layers": list(intent.layers),
            "approved": verdict.approved,
            "reason": verdict.reason,
            "provider": getattr(self.decision_provider, "provider_name", type(self.decision_provider).__name__),
        })

        if not verdict.approved:
            return 1, 0, 0

        ex = self.paper_broker.submit(verdict, snap, PaperScenario())
        if ex.accepted:
            self._audit({
                "event": "FILL",
                "instrument": snap.instrument,
                "instrument_type": snap.instrument_type.value,
                "direction": intent.direction.value,
                "decision_id": intent.decision_id,
                "stop": str(intent.stop),
                "targets": [str(x) for x in intent.targets],
                "leverage": str(intent.suggested_leverage),
                "decision_provider": getattr(self.decision_provider, "provider_name", type(self.decision_provider).__name__),
                "quantity": str(ex.filled_quantity),
                "entry_price": str(ex.entry_price),
                "fee": str(ex.fee),
                "equity": str(self.paper_broker.equity),
            })
            return 1, 1, 1

        self._audit({
            "event": "FILL_REJECT",
            "instrument": snap.instrument,
            "instrument_type": snap.instrument_type.value,
            "decision_id": intent.decision_id,
            "reason": ex.reason,
        })
        return 1, 1, 0

    def _run_async_cycle(self, candidates, snapshots, state, now):
        decisions = approved = fills = provider_errors = 0
        current = {s.instrument: s for s in snapshots}

        # Consume only completed work; never block the market-management cycle.
        for instrument, (submitted, future) in list(self._pending.items()):
            if not future.done():
                continue
            del self._pending[instrument]
            snap = current.get(instrument)
            if snap is None:
                self._audit({
                    "event": "DECISION_REJECT",
                    "instrument": instrument,
                    "instrument_type": submitted.instrument_type.value,
                    "reason": "current_market_snapshot_missing",
                })
                decisions += 1
                continue
            try:
                raw = future.result()
            except Exception as exc:
                self._provider_error(submitted, exc)
                provider_errors += 1
                continue
            d, a, f = self._process_raw(
                snap, raw, now, state=state, expected_snapshot_ts=submitted.timestamp
            )
            decisions += d
            approved += a
            fills += f

        if self.provider_cooldown_seconds > 0:
            return CycleResult(
                "PAPER", len(candidates), decisions, approved, fills,
                pending=len(self._pending), provider_errors=provider_errors,
            )

        available = self._max_pending - len(self._pending)
        if available > 0:
            base_context = (
                self.decision_provider.context_builder.paper_context()
                if getattr(self.decision_provider, "context_builder", None)
                else {}
            )
            for snap in candidates:
                if available <= 0:
                    break
                if snap.instrument in self.paper_broker.positions or snap.instrument in self._pending:
                    continue
                future = self._decision_pool.submit(self.decision_provider.analyze, snap, base_context)
                self._pending[snap.instrument] = (snap, future)
                self._audit({
                    "event": "DECISION_SUBMITTED",
                    "instrument": snap.instrument,
                    "instrument_type": snap.instrument_type.value,
                    "market_snapshot_ts": snap.timestamp.isoformat(),
                    "provider": getattr(self.decision_provider, "provider_name", type(self.decision_provider).__name__),
                })
                available -= 1

        return CycleResult(
            "PAPER", len(candidates), decisions, approved, fills,
            pending=len(self._pending), provider_errors=provider_errors,
        )

    def run_cycle(self, snapshots, state=None, now: datetime | None = None) -> CycleResult:
        now = now or datetime.now(timezone.utc)
        candidates = self.scanner.rank(snapshots, now=now)

        if getattr(self.decision_provider, "async_mode", False):
            return self._run_async_cycle(candidates, snapshots, state, now)

        decisions = approved = fills = provider_errors = 0
        for snap in candidates:
            if snap.instrument in self.paper_broker.positions:
                continue
            try:
                raw = self.decision_provider.analyze(snap)
            except Exception as exc:
                self._provider_error(snap, exc)
                provider_errors += 1
                continue
            d, a, f = self._process_raw(snap, raw, now, state=state, expected_snapshot_ts=snap.timestamp)
            decisions += d
            approved += a
            fills += f
        return CycleResult(
            "PAPER", len(candidates), decisions, approved, fills,
            pending=0, provider_errors=provider_errors,
        )
