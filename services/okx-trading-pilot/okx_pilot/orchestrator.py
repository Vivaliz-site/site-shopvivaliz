from datetime import datetime, timezone
from .config import PilotConfig
from .domain import Mode, TradeDecision
from .paper import PaperBroker
from .risk import RiskState, evaluate_risk

class PaperOrchestrator:
    """Autonomous decision execution in PAPER only."""
    def __init__(self, config: PilotConfig, broker: PaperBroker | None=None):
        if config.mode is not Mode.PAPER:
            raise ValueError('PaperOrchestrator requires PAPER mode')
        if config.live_enabled:
            raise ValueError('live execution is prohibited in paper runtime')
        self.config=config
        self.broker=broker or PaperBroker()

    @property
    def live_execution_available(self):
        return False

    def process(self, decision: TradeDecision, state: RiskState, now: datetime | None=None):
        now=now or datetime.now(timezone.utc)
        if now.tzinfo is None:
            return {'status':'REJECTED','reasons':('NAIVE_CLOCK',)}
        if decision.expires_at <= now:
            return {'status':'REJECTED','reasons':('STALE_DECISION',)}
        verdict=evaluate_risk(decision,state,self.config)
        if not verdict.approved:
            return {'status':'REJECTED','reasons':verdict.reasons}
        fill=self.broker.execute(decision,verdict)
        return {'status':'PAPER_FILLED','fill':fill}
