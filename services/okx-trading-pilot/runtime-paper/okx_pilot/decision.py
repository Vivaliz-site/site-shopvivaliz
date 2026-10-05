from __future__ import annotations
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from uuid import uuid4
from .domain import DecisionIntent, DecisionKind, Direction, InstrumentType, MarketSnapshot


class DecisionValidationError(ValueError):
    pass


REQUIRED_LAYER_NAMES = (
    "market_regime", "multi_timeframe", "price_structure", "momentum", "volume",
    "order_book", "microstructure", "spread_slippage", "volatility", "derivatives",
    "funding_basis", "liquidation_leverage", "correlation", "account_context", "asymmetry",
    "probability_payoff", "instrument_selection", "timing_staleness", "invalidation", "adversarial_review",
)


class HttpDecisionProvider:
    """Calls a loopback/local AI bridge with normalized PAPER-only context."""
    def __init__(self, url: str, model: str = "gpt-5.6-sol", effort: str = "medium", timeout_seconds: int = 45, transport=None):
        self.url = url
        self.model = model
        self.effort = effort
        self.timeout_seconds = timeout_seconds
        self.transport = transport or self._post_json

    @staticmethod
    def _post_json(url: str, payload: dict, timeout: int) -> dict:
        import json
        from urllib.request import Request, urlopen
        data = json.dumps(payload).encode("utf-8")
        req = Request(url, data=data, headers={"Content-Type": "application/json", "User-Agent": "shopvivaliz-okx-paper/0.1"}, method="POST")
        with urlopen(req, timeout=timeout) as resp:
            raw = json.loads(resp.read().decode("utf-8"))
        if isinstance(raw, dict) and isinstance(raw.get("output"), dict):
            return raw["output"]
        return raw

    def analyze(self, snapshot: MarketSnapshot) -> dict:
        payload = {
            "model": self.model,
            "effort": self.effort,
            "web_search": False,
            "mode": "PAPER",
            "required_layers": [{"id": i + 1, "name": name} for i, name in enumerate(REQUIRED_LAYER_NAMES)],
            "market": {
                "instrument": snapshot.instrument,
                "instrument_type": snapshot.instrument_type.value,
                "last": str(snapshot.last),
                "bid": str(snapshot.bid),
                "ask": str(snapshot.ask),
                "volume_24h": str(snapshot.volume_24h),
                "timestamp": snapshot.timestamp.isoformat(),
            },
            "constraints": {
                "decision_values": ["TRADE", "HOLD", "REJECT"],
                "min_confidence": "70",
                "min_expected_rr": "1.5",
                "max_risk_usd": "10",
                "max_leverage": "20",
                "no_exchange_write_capability": True,
            },
        }
        result = self.transport(self.url, payload, self.timeout_seconds)
        if not isinstance(result, dict):
            raise DecisionValidationError("AI provider returned non-object payload")
        return result


class HeuristicDecisionProvider:
    """Credential-free autonomous PAPER decision provider used as a safe baseline."""
    def analyze(self, snapshot: MarketSnapshot) -> dict:
        spread_bps = ((snapshot.ask - snapshot.bid) / snapshot.last) * Decimal("10000")
        trend_up = snapshot.open_24h <= 0 or snapshot.last >= snapshot.open_24h
        direction = "LONG" if trend_up else "SHORT"
        tradable = spread_bps <= Decimal("50") and snapshot.volume_24h > 0
        if snapshot.instrument_type is InstrumentType.SPOT and direction == "SHORT":
            tradable = False
        decision = "TRADE" if tradable else "HOLD"
        now = datetime.now(timezone.utc)

        if direction == "LONG":
            stop = snapshot.last * Decimal("0.98")
            target = snapshot.last * Decimal("1.04")
        else:
            stop = snapshot.last * Decimal("1.02")
            target = snapshot.last * Decimal("0.96")

        risk = Decimal("1.5")
        leverage = Decimal("1") if snapshot.instrument_type is InstrumentType.SPOT else Decimal("2")
        layers = [
            {"id": i, "assessment": "not_evaluated", "evidence": [f"snapshot:{snapshot.instrument}"], "risk_flags": ["HEURISTIC_BASELINE_NOT_20_LAYER_AI"]}
            for i in range(1, 21)
        ]
        return {
            "decision_id": str(uuid4()), "decision": decision, "instrument": snapshot.instrument,
            "instrument_type": snapshot.instrument_type.value, "direction": direction,
            "entry_low": str(min(snapshot.bid, snapshot.last)), "entry_high": str(max(snapshot.ask, snapshot.last)), "stop": str(stop),
            "targets": [str(target)], "confidence": "75", "expected_rr": "2",
            "suggested_risk": str(risk), "suggested_leverage": str(leverage), "created_at": now.isoformat(),
            "expires_at": (now + timedelta(seconds=120)).isoformat(), "layers": layers,
            "decision_provider": "HEURISTIC_BASELINE",
            "confidence_semantics": "fixed_rule_score_not_calibrated_probability",
            "thesis": "liquid narrow-spread trend candidate", "invalidation": "protective stop reached",
        }


class DecisionParser:
    @staticmethod
    def parse(payload: dict, now: datetime) -> DecisionIntent:
        layers = payload.get("layers")
        if not isinstance(layers, list) or len(layers) != 20 or [x.get("id") for x in layers] != list(range(1, 21)):
            raise DecisionValidationError("exactly 20 ordered layers required")
        try:
            decision = DecisionKind(payload["decision"])
            created_at = datetime.fromisoformat(payload["created_at"])
            expires_at = datetime.fromisoformat(payload["expires_at"])
            if created_at.tzinfo is None or expires_at.tzinfo is None:
                raise DecisionValidationError("aware timestamps required")
            confidence = Decimal(payload["confidence"])
            rr = Decimal(payload["expected_rr"])
            if expires_at < now or expires_at - created_at > timedelta(seconds=120):
                raise DecisionValidationError("decision expired")
            if decision is DecisionKind.TRADE and (confidence < Decimal("70") or rr < Decimal("1.5")):
                raise DecisionValidationError("trade below minimum confidence or rr")
            return DecisionIntent(
                decision_id=str(payload["decision_id"]), decision=decision, instrument=str(payload["instrument"]),
                instrument_type=InstrumentType(payload["instrument_type"]), direction=Direction(payload.get("direction", "LONG")),
                entry_low=Decimal(payload.get("entry_low", "0")), entry_high=Decimal(payload.get("entry_high", "0")),
                stop=Decimal(payload.get("stop", "0")), targets=tuple(Decimal(x) for x in payload.get("targets", [])),
                confidence=confidence, expected_rr=rr, suggested_risk=Decimal(payload.get("suggested_risk", "0")),
                suggested_leverage=Decimal(payload.get("suggested_leverage", "1")), created_at=created_at, expires_at=expires_at,
                layers=tuple(layers), thesis=str(payload.get("thesis", "")), invalidation=str(payload.get("invalidation", "")),
            )
        except DecisionValidationError:
            raise
        except Exception as exc:
            raise DecisionValidationError(type(exc).__name__) from exc
