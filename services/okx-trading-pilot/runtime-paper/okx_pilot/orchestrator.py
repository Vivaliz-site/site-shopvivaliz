from __future__ import annotations
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
import json
from .decision import DecisionParser, DecisionValidationError
from .paper import PaperScenario


@dataclass(frozen=True)
class CycleResult:
    mode: str
    candidates: int
    decisions: int
    approved: int
    fills: int


class PilotOrchestrator:
    def __init__(self, scanner, decision_provider, risk_gateway, paper_broker, audit_path: Path):
        self.scanner = scanner
        self.decision_provider = decision_provider
        self.risk_gateway = risk_gateway
        self.paper_broker = paper_broker
        self.audit_path = audit_path

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

    def run_cycle(self, snapshots, state=None, now: datetime | None = None) -> CycleResult:
        now = now or datetime.now(timezone.utc)
        candidates = self.scanner.rank(snapshots, now=now)
        decisions = approved = fills = 0

        for snap in candidates:
            if snap.instrument in self.paper_broker.positions:
                continue
            raw = self.decision_provider.analyze(snap)
            decisions += 1
            try:
                intent = DecisionParser.parse(raw, now=now)
            except DecisionValidationError as exc:
                self._audit({
                    "event": "DECISION_REJECT",
                    "instrument": snap.instrument,
                    "instrument_type": snap.instrument_type.value,
                    "decision_id": raw.get("decision_id"),
                    "reason": str(exc),
                })
                continue

            effective_state = state if state is not None else self.paper_broker.pilot_state()
            verdict = self.risk_gateway.authorize(intent, snap, effective_state, now=now)
            self._audit({
                "event": "DECISION",
                "instrument": snap.instrument,
                "instrument_type": snap.instrument_type.value,
                "decision_id": intent.decision_id,
                "decision": intent.decision.value,
                "direction": intent.direction.value,
                "approved": verdict.approved,
                "reason": verdict.reason,
            })

            if verdict.approved:
                approved += 1
                ex = self.paper_broker.submit(verdict, snap, PaperScenario())
                if ex.accepted:
                    fills += 1
                    self._audit({
                        "event": "FILL",
                        "instrument": snap.instrument,
                        "instrument_type": snap.instrument_type.value,
                        "direction": intent.direction.value,
                        "decision_id": intent.decision_id,
                        "stop": str(intent.stop),
                        "targets": [str(x) for x in intent.targets],
                        "leverage": str(intent.suggested_leverage),
                        "decision_provider": "HEURISTIC_BASELINE" if type(self.decision_provider).__name__ == "HeuristicDecisionProvider" else type(self.decision_provider).__name__,
                        "quantity": str(ex.filled_quantity),
                        "entry_price": str(ex.entry_price),
                        "fee": str(ex.fee),
                        "equity": str(self.paper_broker.equity),
                    })
                else:
                    self._audit({
                        "event": "FILL_REJECT",
                        "instrument": snap.instrument,
                        "instrument_type": snap.instrument_type.value,
                        "reason": ex.reason,
                    })

        return CycleResult("PAPER", len(candidates), decisions, approved, fills)
