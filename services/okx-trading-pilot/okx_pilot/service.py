from .scanner import evaluate_snapshot
from .decision import decision_from_payload
from .domain import DecisionKind, Mode
from .risk import RiskState, evaluate_risk

class TradingPilotService:
    def __init__(self, okx, model, config):
        self.okx = okx
        self.model = model
        self.config = config

    def run_cycle(self, now):
        decisions = 0
        approved = 0
        executed = 0
        rejected = 0
        for iid in self.okx.list_instruments():
            snap = self.okx.market_snapshot(iid)
            if not evaluate_snapshot(snap, self.config, now).eligible:
                continue
            d = decision_from_payload(self.model.analyze(snap), now)
            decisions += 1
            if d.decision is not DecisionKind.TRADE:
                continue
            v = evaluate_risk(d, RiskState.zero(), self.config)
            if not v.approved:
                rejected += 1
                continue
            approved += 1
            if self.config.mode is Mode.LIVE_PILOT and self.config.live_enabled:
                executed += 1
        return {'decisions': decisions, 'approved': approved, 'executed': executed, 'rejected': rejected}
