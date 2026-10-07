from __future__ import annotations

from datetime import datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
import json
from urllib.parse import urlsplit
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
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


def _json_safe(value):
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, datetime):
        return value.isoformat()
    if isinstance(value, dict):
        return {str(k): _json_safe(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_safe(v) for v in value]
    if value is None or isinstance(value, (str, int, bool)):
        return value
    return str(value)


class DecisionContextBuilder:
    """Builds normalized PAPER/public-market context. It never reads exchange credentials."""
    def __init__(self, market_client, paper_broker):
        self.market_client = market_client
        self.paper_broker = paper_broker

    def paper_context(self) -> dict:
        report = self.paper_broker.summary()
        state = self.paper_broker.pilot_state()
        positions = report.get("open_positions", {})
        return {
            "paper_account": {
                "initial_equity": str(report.get("initial_equity", "")),
                "total_equity": str(report.get("total_equity", "")),
                "available_equity": str(report.get("available_equity", "")),
                "net_pnl": str(report.get("net_pnl", "")),
                "open_positions": _json_safe(positions),
            },
            "risk_state": {
                "open_risk": str(state.open_risk),
                "correlated_risk": str(state.correlated_risk),
                "daily_loss": str(state.daily_loss),
                "cumulative_loss": str(state.cumulative_loss),
            },
        }

    def build(self, snapshot: MarketSnapshot, paper_context: dict | None = None) -> dict:
        base = _json_safe(paper_context or self.paper_context())
        public = self.market_client.decision_market_context(snapshot)
        return {
            **base,
            "market_research": _json_safe(public),
        }


class CodexBridgeDecisionProvider:
    """Strict 20-layer model provider over the local authenticated ChatGPT bridge."""
    async_mode = True
    max_concurrency = 2
    max_decisions_per_cycle = 2
    provider_name = "CODEX_20_LAYER"

    def __init__(
        self,
        url: str = "http://127.0.0.1:17656/v1/respond",
        model: str = "gpt-5.6-terra",
        effort: str = "medium",
        timeout_seconds: int = 45,
        context_builder: DecisionContextBuilder | None = None,
        transport=None,
    ):
        parsed = urlsplit(url)
        if (parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "localhost", "::1"}
                or parsed.path != "/v1/respond" or parsed.username is not None
                or parsed.password is not None or parsed.query or parsed.fragment):
            raise DecisionValidationError("decision_bridge:loopback_url_required")
        self.url = url
        self.model = model
        self.effort = effort
        self.timeout_seconds = timeout_seconds
        self.context_builder = context_builder
        self.transport = transport or self._post_json

    @staticmethod
    def _post_json(url: str, payload: dict, timeout: int) -> dict:
        data = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        req = Request(
            url,
            data=data,
            headers={"Content-Type": "application/json", "User-Agent": "shopvivaliz-okx-paper/0.4"},
            method="POST",
        )
        try:
            with urlopen(req, timeout=timeout) as resp:
                raw = json.loads(resp.read().decode("utf-8"))
        except HTTPError as exc:
            try:
                body = json.loads(exc.read().decode("utf-8"))
                reason = str(body.get("error") or f"http_{exc.code}")
            except Exception:
                reason = f"http_{exc.code}"
            raise DecisionValidationError(f"decision_bridge:{reason}") from exc
        except (URLError, TimeoutError, json.JSONDecodeError) as exc:
            raise DecisionValidationError(f"decision_bridge:{type(exc).__name__}") from exc
        return raw

    def _prompt(self, snapshot: MarketSnapshot, context: dict) -> str:
        layers = [{"id": i + 1, "name": name} for i, name in enumerate(REQUIRED_LAYER_NAMES)]
        output_example = {
            "decision_id": "uuid",
            "decision": "TRADE",
            "instrument": snapshot.instrument,
            "instrument_type": snapshot.instrument_type.value,
            "direction": "LONG",
            "entry_low": str(snapshot.bid),
            "entry_high": str(snapshot.ask),
            "stop": str(snapshot.bid),
            "targets": [str(snapshot.ask)],
            "suggested_risk": "1.0",
            "suggested_leverage": "1",
            "time_horizon": "intraday",
            "confidence": "70",
            "expected_rr": "1.5",
            "thesis": "short evidence-based thesis",
            "supporting_evidence": ["fact"],
            "contrary_evidence": ["fact"],
            "invalidation": "specific invalidation",
            "market_snapshot_ts": snapshot.timestamp.isoformat(),
            "created_at": datetime.now(timezone.utc).isoformat(),
            "expires_at": (datetime.now(timezone.utc) + timedelta(seconds=90)).isoformat(),
            "layers": [
                {"id": row["id"], "name": row["name"], "assessment": "neutral", "evidence": ["fact"], "risk_flags": []}
                for row in layers
            ],
        }
        market = {
            "instrument": snapshot.instrument,
            "instrument_type": snapshot.instrument_type.value,
            "last": str(snapshot.last),
            "bid": str(snapshot.bid),
            "ask": str(snapshot.ask),
            "open_24h": str(snapshot.open_24h),
            "volume_24h_usd_equivalent": str(snapshot.volume_24h),
            "timestamp": snapshot.timestamp.isoformat(),
        }
        return (
            "You are the decision engine for a PAPER-only OKX simulation. "
            "You cannot place exchange orders and cannot change risk limits. "
            "Use only INPUT_CONTEXT. Web research is disabled. "
            "Return one JSON object only: no markdown, prose wrapper, comments, NaN or Infinity. "
            "Analyze exactly the 20 ordered layers in REQUIRED_LAYERS. Every layer must contain "
            "the exact id and name plus a non-empty assessment, non-empty evidence array, and risk_flags array. "
            "If evidence is missing or contradictory, say so in that layer and prefer HOLD or REJECT. "
            "Do not count correlated indicators as independent confirmation. "
            "TRADE requires a concrete entry band, stop, target, positive proposed risk <= 10, leverage <= 20, "
            "confidence 70..100 and expected R:R >= 1.5. HOLD/REJECT may use zero entry/stop/risk and no targets. "
            "Expiry must be no more than 120 seconds after created_at. "
            "supporting_evidence and contrary_evidence must both be non-empty. "
            "Adversarial review must actively try to refute a TRADE. "
            "REQUIRED_LAYERS=" + json.dumps(layers, separators=(",", ":")) +
            "\nOUTPUT_SCHEMA_EXAMPLE=" + json.dumps(output_example, separators=(",", ":")) +
            "\nMARKET_SNAPSHOT=" + json.dumps(market, separators=(",", ":")) +
            "\nINPUT_CONTEXT=" + json.dumps(_json_safe(context), separators=(",", ":"))
        )

    def analyze(self, snapshot: MarketSnapshot, context: dict | None = None) -> dict:
        if context is None:
            context = self.context_builder.build(snapshot) if self.context_builder else {}
        elif self.context_builder:
            context = self.context_builder.build(snapshot, context)
        payload = {
            "model": self.model,
            "effort": self.effort,
            "profile": "dev",
            "prompt": self._prompt(snapshot, context),
            "web_search": False,
        }
        result = self.transport(self.url, payload, self.timeout_seconds)
        if not isinstance(result, dict) or result.get("ok") is not True:
            reason = result.get("error") if isinstance(result, dict) else "non_object"
            raise DecisionValidationError(f"decision_bridge:{reason}")
        if str(result.get("model")) != self.model:
            raise DecisionValidationError("decision_bridge:model_mismatch")
        if result.get("transport") != "codex_chatgpt":
            raise DecisionValidationError("decision_bridge:transport_mismatch")
        text = result.get("text")
        if not isinstance(text, str):
            raise DecisionValidationError("decision_bridge:missing_text")
        try:
            parsed = json.loads(text)
        except json.JSONDecodeError as exc:
            raise DecisionValidationError("decision_bridge:non_json_response") from exc
        if not isinstance(parsed, dict):
            raise DecisionValidationError("decision_bridge:non_object_response")
        return parsed


class ChatGPTBrowserDecisionProvider(CodexBridgeDecisionProvider):
    """Strict 20-layer fallback over a dedicated normal ChatGPT login."""
    async_mode = True
    max_concurrency = 1
    max_decisions_per_cycle = 1
    provider_name = "CHATGPT_BROWSER_20_LAYER"

    def __init__(
        self,
        url: str = "http://127.0.0.1:17657/v1/respond",
        model: str = "gpt-5.6-sol",
        effort: str = "xhigh",
        timeout_seconds: int = 180,
        context_builder: DecisionContextBuilder | None = None,
        transport=None,
    ):
        super().__init__(
            url=url,
            model=model,
            effort=effort,
            timeout_seconds=timeout_seconds,
            context_builder=context_builder,
            transport=transport,
        )
        if model != "gpt-5.6-sol":
            raise DecisionValidationError("decision_browser:sol_required")
        if effort != "xhigh":
            raise DecisionValidationError("decision_browser:xhigh_required")

    def analyze(self, snapshot: MarketSnapshot, context: dict | None = None) -> dict:
        if context is None:
            context = self.context_builder.build(snapshot) if self.context_builder else {}
        elif self.context_builder:
            context = self.context_builder.build(snapshot, context)
        payload = {
            "model": self.model,
            "effort": self.effort,
            "profile": "okx",
            "prompt": self._prompt(snapshot, context),
            "web_search": False,
        }
        result = self.transport(self.url, payload, self.timeout_seconds)
        if not isinstance(result, dict) or result.get("ok") is not True:
            reason = result.get("error") if isinstance(result, dict) else "non_object"
            raise DecisionValidationError(f"decision_browser:{reason}")
        if str(result.get("model")) != self.model:
            raise DecisionValidationError("decision_browser:model_mismatch")
        if str(result.get("effort")) != self.effort:
            raise DecisionValidationError("decision_browser:effort_mismatch")
        if result.get("transport") != "chatgpt_browser":
            raise DecisionValidationError("decision_browser:transport_mismatch")
        if result.get("profile") != "okx":
            raise DecisionValidationError("decision_browser:profile_mismatch")
        text = result.get("text")
        if not isinstance(text, str):
            raise DecisionValidationError("decision_browser:missing_text")
        try:
            parsed = json.loads(text)
        except json.JSONDecodeError as exc:
            raise DecisionValidationError("decision_browser:non_json_response") from exc
        if not isinstance(parsed, dict):
            raise DecisionValidationError("decision_browser:non_object_response")
        return parsed


class LoginFailoverDecisionProvider:
    """Codex login primary with a normal ChatGPT login fallback."""
    async_mode = True
    max_concurrency = 2
    max_decisions_per_cycle = 2
    provider_name = "LOGIN_20_LAYER_FAILOVER"
    _PRIMARY_AVAILABILITY_MARKERS = (
        "decision_bridge:codex_unavailable",
        "decision_bridge:bridge_busy",
        "decision_bridge:timeouterror",
        "decision_bridge:urlerror",
        "decision_bridge:http_429",
        "decision_bridge:usage_limit",
        "decision_bridge:quota",
        "decision_bridge:authentication_required",
    )

    def __init__(self, primary, fallback, context_builder: DecisionContextBuilder | None = None):
        import threading
        self.primary = primary
        self.fallback = fallback
        self.context_builder = context_builder
        self._status_lock = threading.Lock()
        self.last_provider_name = ""
        self.last_model = ""
        self.last_effort = ""
        self.last_primary_error = ""
        self.primary_failures_total = 0
        self.fallback_uses_total = 0

    @classmethod
    def _fallback_allowed(cls, exc: Exception) -> bool:
        reason = str(exc).lower()
        return any(marker in reason for marker in cls._PRIMARY_AVAILABILITY_MARKERS)

    def _record_effective(self, provider, *, fallback_used: bool):
        with self._status_lock:
            self.last_provider_name = str(getattr(provider, "provider_name", ""))
            self.last_model = str(getattr(provider, "model", ""))
            self.last_effort = str(getattr(provider, "effort", ""))
            if fallback_used:
                self.fallback_uses_total += 1

    def analyze(self, snapshot: MarketSnapshot, context: dict | None = None) -> dict:
        if context is None:
            context = self.context_builder.build(snapshot) if self.context_builder else {}
        elif self.context_builder:
            context = self.context_builder.build(snapshot, context)
        try:
            result = self.primary.analyze(snapshot, context)
        except DecisionValidationError as exc:
            if not self._fallback_allowed(exc):
                raise
            with self._status_lock:
                self.primary_failures_total += 1
                self.last_primary_error = str(exc)[:160]
            result = self.fallback.analyze(snapshot, context)
            self._record_effective(self.fallback, fallback_used=True)
            return result
        self._record_effective(self.primary, fallback_used=False)
        return result


class HeuristicDecisionProvider:
    """Test fixture/baseline only. Production runtime must not select this provider."""
    async_mode = False
    provider_name = "HEURISTIC_BASELINE"

    def analyze(self, snapshot: MarketSnapshot, context: dict | None = None) -> dict:
        spread_bps = ((snapshot.ask - snapshot.bid) / snapshot.last) * Decimal("10000")
        trend_up = snapshot.open_24h <= 0 or snapshot.last >= snapshot.open_24h
        direction = "LONG" if trend_up else "SHORT"
        tradable = spread_bps <= Decimal("50") and snapshot.volume_24h > 0
        if snapshot.instrument_type is InstrumentType.SPOT and direction == "SHORT":
            tradable = False
        decision = "TRADE" if tradable else "HOLD"
        now = datetime.now(timezone.utc)
        stop = snapshot.last * (Decimal("0.98") if direction == "LONG" else Decimal("1.02"))
        target = snapshot.last * (Decimal("1.04") if direction == "LONG" else Decimal("0.96"))
        return {
            "decision_id": str(uuid4()), "decision": decision, "instrument": snapshot.instrument,
            "instrument_type": snapshot.instrument_type.value, "direction": direction,
            "entry_low": str(min(snapshot.bid, snapshot.last)), "entry_high": str(max(snapshot.ask, snapshot.last)),
            "stop": str(stop) if decision == "TRADE" else "0",
            "targets": [str(target)] if decision == "TRADE" else [],
            "confidence": "75", "expected_rr": "2" if decision == "TRADE" else "0",
            "suggested_risk": "1.5" if decision == "TRADE" else "0",
            "suggested_leverage": "1" if snapshot.instrument_type is InstrumentType.SPOT else "2",
            "time_horizon": "test_fixture",
            "created_at": now.isoformat(), "expires_at": (now + timedelta(seconds=90)).isoformat(),
            "market_snapshot_ts": snapshot.timestamp.isoformat(),
            "layers": [
                {"id": i + 1, "name": name, "assessment": "not_evaluated",
                 "evidence": [f"snapshot:{snapshot.instrument}"], "risk_flags": ["HEURISTIC_BASELINE_NOT_20_LAYER_AI"]}
                for i, name in enumerate(REQUIRED_LAYER_NAMES)
            ],
            "thesis": "test fixture", "supporting_evidence": ["test fixture"],
            "contrary_evidence": ["not an AI decision"], "invalidation": "test fixture",
        }


class DecisionParser:
    @staticmethod
    def _decimal(name: str, value) -> Decimal:
        try:
            out = Decimal(str(value))
        except (InvalidOperation, ValueError, TypeError) as exc:
            raise DecisionValidationError(f"invalid_{name}") from exc
        if not out.is_finite():
            raise DecisionValidationError(f"invalid_{name}")
        return out

    @staticmethod
    def parse(payload: dict, now: datetime) -> DecisionIntent:
        if not isinstance(payload, dict):
            raise DecisionValidationError("decision object required")
        layers = payload.get("layers")
        if not isinstance(layers, list) or len(layers) != 20:
            raise DecisionValidationError("exactly 20 ordered layers required")
        for i, (layer, name) in enumerate(zip(layers, REQUIRED_LAYER_NAMES), 1):
            if not isinstance(layer, dict) or layer.get("id") != i or layer.get("name") != name:
                raise DecisionValidationError("layer identity mismatch")
            if not isinstance(layer.get("assessment"), str) or not layer["assessment"].strip():
                raise DecisionValidationError("layer assessment required")
            if not isinstance(layer.get("evidence"), list) or not layer["evidence"]:
                raise DecisionValidationError("layer evidence required")
            if not all(isinstance(x, str) and x.strip() for x in layer["evidence"]):
                raise DecisionValidationError("invalid layer evidence")
            if not isinstance(layer.get("risk_flags"), list) or not all(isinstance(x, str) for x in layer["risk_flags"]):
                raise DecisionValidationError("invalid layer risk flags")
        try:
            decision = DecisionKind(payload["decision"])
            created_at = datetime.fromisoformat(str(payload["created_at"]))
            expires_at = datetime.fromisoformat(str(payload["expires_at"]))
            market_ts = datetime.fromisoformat(str(payload["market_snapshot_ts"]))
            if any(x.tzinfo is None or x.utcoffset() is None for x in (created_at, expires_at, market_ts)):
                raise DecisionValidationError("aware timestamps required")
            if created_at > now + timedelta(seconds=5):
                raise DecisionValidationError("decision created in future")
            if expires_at <= now or expires_at - created_at > timedelta(seconds=120):
                raise DecisionValidationError("decision expired")
            confidence = DecisionParser._decimal("confidence", payload["confidence"])
            rr = DecisionParser._decimal("expected_rr", payload.get("expected_rr", "0"))
            risk = DecisionParser._decimal("suggested_risk", payload.get("suggested_risk", "0"))
            leverage = DecisionParser._decimal("suggested_leverage", payload.get("suggested_leverage", "1"))
            if confidence < 0 or confidence > 100 or rr < 0 or risk < 0 or risk > 10 or leverage < 0 or leverage > 20:
                raise DecisionValidationError("decision numerical controls out of range")
            supporting = tuple(str(x) for x in payload.get("supporting_evidence", ()) if str(x).strip())
            contrary = tuple(str(x) for x in payload.get("contrary_evidence", ()) if str(x).strip())
            if not supporting or not contrary:
                raise DecisionValidationError("supporting and contrary evidence required")
            entry_low = DecisionParser._decimal("entry_low", payload.get("entry_low", "0"))
            entry_high = DecisionParser._decimal("entry_high", payload.get("entry_high", "0"))
            stop = DecisionParser._decimal("stop", payload.get("stop", "0"))
            targets = tuple(DecisionParser._decimal("target", x) for x in payload.get("targets", []))
            thesis = str(payload.get("thesis", "")).strip()
            invalidation = str(payload.get("invalidation", "")).strip()
            if not thesis or not invalidation:
                raise DecisionValidationError("thesis and invalidation required")
            if decision is DecisionKind.TRADE:
                if confidence < Decimal("70") or rr < Decimal("1.5"):
                    raise DecisionValidationError("trade below minimum confidence or rr")
                if entry_low <= 0 or entry_high <= 0 or stop <= 0 or risk <= 0 or leverage <= 0 or not targets:
                    raise DecisionValidationError("trade order shape incomplete")
            return DecisionIntent(
                decision_id=str(payload["decision_id"]), decision=decision, instrument=str(payload["instrument"]),
                instrument_type=InstrumentType(payload["instrument_type"]), direction=Direction(payload.get("direction", "LONG")),
                entry_low=entry_low, entry_high=entry_high, stop=stop, targets=targets,
                confidence=confidence, expected_rr=rr, suggested_risk=risk, suggested_leverage=leverage,
                created_at=created_at, expires_at=expires_at, layers=tuple(layers), thesis=thesis,
                invalidation=invalidation, time_horizon=str(payload.get("time_horizon", "")),
                supporting_evidence=supporting, contrary_evidence=contrary, market_snapshot_ts=market_ts,
            )
        except DecisionValidationError:
            raise
        except Exception as exc:
            raise DecisionValidationError(type(exc).__name__) from exc
