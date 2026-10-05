from __future__ import annotations
from dataclasses import dataclass
from decimal import Decimal
from .decision import DecisionParser, DecisionValidationError
from .domain import PilotState
from .metrics import PerformanceMetrics, PerformanceTracker
from .paper import PaperScenario


@dataclass(frozen=True)
class SimulationReport:
    mode: str
    final_equity: Decimal
    metrics: PerformanceMetrics
    decisions: int
    approvals: int
    rejections: int


class SimulationRunner:
    """Deterministic PAPER-only series runner; it has no exchange-write dependency."""
    def __init__(self, scanner, decision_provider, risk_gateway, paper_broker):
        self.scanner = scanner
        self.decision_provider = decision_provider
        self.risk_gateway = risk_gateway
        self.paper_broker = paper_broker

    def run_series(self, snapshots) -> SimulationReport:
        tracker = PerformanceTracker(self.paper_broker.starting_equity)
        decisions = approvals = rejections = 0
        for snap in snapshots:
            for event in self.paper_broker.manage_positions([snap], now=snap.timestamp):
                tracker.record(event.pnl)
            if snap.instrument in self.paper_broker.positions:
                continue
            candidates = self.scanner.rank([snap], now=snap.timestamp)
            if not candidates:
                continue
            raw = self.decision_provider.analyze(snap)
            decisions += 1
            try:
                intent = DecisionParser.parse(raw, now=snap.timestamp)
            except DecisionValidationError:
                rejections += 1
                continue
            verdict = self.risk_gateway.authorize(intent, snap, self.paper_broker.pilot_state(), now=snap.timestamp)
            if not verdict.approved:
                rejections += 1
                continue
            approvals += 1
            self.paper_broker.submit(verdict, snap, PaperScenario())
        return SimulationReport("PAPER", self.paper_broker.equity, tracker.snapshot(), decisions, approvals, rejections)
