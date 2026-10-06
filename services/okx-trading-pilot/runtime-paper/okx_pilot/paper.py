from __future__ import annotations
from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal, ROUND_DOWN
from zoneinfo import ZoneInfo
from uuid import uuid4
import os
import json
from pathlib import Path
from .domain import Direction, InstrumentType, MarketSnapshot, RiskVerdict, PilotState


@dataclass(frozen=True)
class PaperScenario:
    slippage_bps: Decimal = Decimal("5")
    fill_fraction: Decimal = Decimal("1")
    reject: bool = False
    timeout: bool = False


@dataclass(frozen=True)
class SimulatedExecution:
    accepted: bool
    reason: str
    filled_quantity: Decimal
    entry_price: Decimal
    fee: Decimal


@dataclass(frozen=True)
class SimulatedEvent:
    kind: str
    instrument: str
    pnl: Decimal
    instrument_type: str = ""
    price: Decimal = Decimal("0")
    fee: Decimal = Decimal("0")


@dataclass
class _Position:
    instrument_type: InstrumentType
    direction: Direction
    stop: Decimal
    targets: tuple[Decimal, ...]
    risk_usd: Decimal
    leverage: Decimal
    quantity: Decimal
    entry_price: Decimal
    entry_fee: Decimal
    margin: Decimal
    opened_at_ms: int
    last_funding_time_ms: int = 0
    funding_pnl: Decimal = Decimal("0")


class PaperBroker:
    STATE_VERSION = 2

    def __init__(
        self,
        starting_equity: Decimal = Decimal("100"),
        fee_bps: Decimal = Decimal("6"),
        state_path: Path | None = None,
        max_holding_seconds: int = 86400,
    ):
        self.starting_equity = starting_equity
        self.equity = starting_equity
        self.fee_bps = fee_bps
        self.state_path = state_path
        self.max_holding_seconds = max_holding_seconds
        self.positions: dict[str, _Position] = {}
        self.marks = {}
        self.closed_pnls = []
        self.total_fees = Decimal("0")
        self.total_funding = Decimal("0")
        self.peak_equity = starting_equity
        self.max_drawdown = Decimal("0")
        self.run_id = str(uuid4())
        self.started_at = datetime.now(timezone.utc).isoformat()
        self.day_key = datetime.now(ZoneInfo("America/Sao_Paulo")).date().isoformat()
        self.day_start_equity = starting_equity
        if self.state_path and self.state_path.exists():
            self._load()
        else:
            self._persist()

    @property
    def reserved_margin(self) -> Decimal:
        return sum((p.margin for p in self.positions.values()), Decimal("0"))

    @property
    def available_equity(self) -> Decimal:
        return max(Decimal("0"), self.marked_equity - self.reserved_margin)

    def _persist(self) -> None:
        if not self.state_path:
            return
        payload = {
            "version": self.STATE_VERSION,
            "run_id": self.run_id, "started_at": self.started_at,
            "marks": {k:str(v) for k,v in self.marks.items()},
            "closed_pnls": [str(x) for x in self.closed_pnls],
            "total_fees": str(self.total_fees), "total_funding": str(self.total_funding),
            "peak_equity": str(self.peak_equity), "max_drawdown": str(self.max_drawdown),
            "starting_equity": str(self.starting_equity),
            "equity": str(self.equity),
            "day_key": self.day_key,
            "day_start_equity": str(self.day_start_equity),
            "positions": {
                inst: {
                    "instrument_type": p.instrument_type.value,
                    "direction": p.direction.value,
                    "stop": str(p.stop),
                    "targets": [str(x) for x in p.targets],
                    "risk_usd": str(p.risk_usd),
                    "leverage": str(p.leverage),
                    "quantity": str(p.quantity),
                    "entry_price": str(p.entry_price),
                    "entry_fee": str(p.entry_fee),
                    "margin": str(p.margin),
                    "opened_at_ms": p.opened_at_ms,
                    "last_funding_time_ms": p.last_funding_time_ms,
                    "funding_pnl": str(p.funding_pnl),
                }
                for inst, p in self.positions.items()
            },
        }
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.state_path.with_suffix(self.state_path.suffix + ".tmp")
        with tmp.open("w", encoding="utf-8") as f:
            json.dump(payload, f, sort_keys=True)
            f.flush(); os.fsync(f.fileno())
        tmp.replace(self.state_path)

    def _load(self) -> None:
        data = json.loads(self.state_path.read_text(encoding="utf-8"))
        if data.get("version") != self.STATE_VERSION:
            raise RuntimeError("paper_state_version")
        self.run_id = data.get("run_id", self.run_id)
        self.started_at = data.get("started_at", self.started_at)
        self.marks = {k:Decimal(v) for k,v in data.get("marks",{}).items()}
        self.closed_pnls = [Decimal(x) for x in data.get("closed_pnls",[])]
        self.total_fees = Decimal(data.get("total_fees","0"))
        self.total_funding = Decimal(data.get("total_funding","0"))
        self.peak_equity = Decimal(data.get("peak_equity",data["starting_equity"]))
        self.max_drawdown = Decimal(data.get("max_drawdown","0"))
        self.starting_equity = Decimal(data["starting_equity"])
        self.equity = Decimal(data["equity"])
        self.day_key = str(data["day_key"])
        self.day_start_equity = Decimal(data["day_start_equity"])
        positions: dict[str, _Position] = {}
        for inst, row in data.get("positions", {}).items():
            positions[inst] = _Position(
                instrument_type=InstrumentType(row["instrument_type"]),
                direction=Direction(row["direction"]),
                stop=Decimal(row["stop"]),
                targets=tuple(Decimal(x) for x in row.get("targets", [])),
                risk_usd=Decimal(row["risk_usd"]),
                leverage=Decimal(row["leverage"]),
                quantity=Decimal(row["quantity"]),
                entry_price=Decimal(row["entry_price"]),
                entry_fee=Decimal(row["entry_fee"]),
                margin=Decimal(row["margin"]),
                opened_at_ms=int(row["opened_at_ms"]),
                last_funding_time_ms=int(row.get("last_funding_time_ms", 0)),
                funding_pnl=Decimal(row.get("funding_pnl","0")),
            )
        self.positions = positions

    def _roll_day(self, now=None) -> None:
        now = now or datetime.now(timezone.utc)
        today = now.astimezone(ZoneInfo("America/Sao_Paulo")).date().isoformat()
        if today != self.day_key:
            self.day_key = today
            self.day_start_equity = self.marked_equity
            self._persist()

    def submit(
        self,
        verdict: RiskVerdict,
        market: MarketSnapshot,
        scenario: PaperScenario | None = None,
    ) -> SimulatedExecution:
        scenario = scenario or PaperScenario()
        if market.instrument_type not in (InstrumentType.SPOT, InstrumentType.SWAP, InstrumentType.FUTURES):
            return SimulatedExecution(False,"unsupported_instrument",Decimal("0"),Decimal("0"),Decimal("0"))
        if verdict.intent.instrument != market.instrument or verdict.intent.instrument_type is not market.instrument_type:
            return SimulatedExecution(False,"instrument_mismatch",Decimal("0"),Decimal("0"),Decimal("0"))
        if verdict.intent.instrument in self.positions:
            return SimulatedExecution(False, "position_exists", Decimal("0"), Decimal("0"), Decimal("0"))
        if not verdict.approved:
            return SimulatedExecution(False, verdict.reason, Decimal("0"), Decimal("0"), Decimal("0"))
        if scenario.reject:
            return SimulatedExecution(False, "simulated_reject", Decimal("0"), Decimal("0"), Decimal("0"))
        if scenario.timeout:
            return SimulatedExecution(False, "simulated_timeout", Decimal("0"), Decimal("0"), Decimal("0"))
        if market.instrument_type is InstrumentType.SPOT and verdict.intent.direction is Direction.SHORT:
            return SimulatedExecution(False, "spot_short_disabled", Decimal("0"), Decimal("0"), Decimal("0"))

        qty = verdict.quantity * max(Decimal("0"), min(Decimal("1"), scenario.fill_fraction))
        if qty <= 0:
            return SimulatedExecution(False, "no_fill", Decimal("0"), Decimal("0"), Decimal("0"))

        slip = scenario.slippage_bps / Decimal("10000")
        if verdict.intent.direction is Direction.LONG:
            price = market.ask * (Decimal("1") + slip)
        else:
            price = market.bid * (Decimal("1") - slip)

        leverage = Decimal("1") if market.instrument_type is InstrumentType.SPOT else verdict.intent.suggested_leverage
        if leverage <= 0 or leverage > 20:
            return SimulatedExecution(False,"leverage",Decimal("0"),Decimal("0"),Decimal("0"))
        # Stop risk includes two fees and a 25-bps adverse execution buffer.
        risk_per_unit = abs(price-verdict.intent.stop) + price * (2*self.fee_bps + Decimal("25")) / Decimal("10000")
        qty = min(qty, verdict.risk_usd/risk_per_unit)
        qty = (qty/market.quantity_step).to_integral_value(rounding=ROUND_DOWN)*market.quantity_step
        if qty < market.minimum_quantity:
            return SimulatedExecution(False,"below_minimum_size",Decimal("0"),Decimal("0"),Decimal("0"))
        fee = price * qty * self.fee_bps / Decimal("10000")
        notional = price * qty
        margin = notional / leverage
        if margin + fee > self.available_equity:
            return SimulatedExecution(False, "insufficient_paper_margin", Decimal("0"), Decimal("0"), Decimal("0"))

        self.equity -= fee
        self.total_fees += fee
        self.positions[verdict.intent.instrument] = _Position(
            instrument_type=market.instrument_type,
            direction=verdict.intent.direction,
            stop=verdict.intent.stop,
            targets=verdict.intent.targets,
            risk_usd=risk_per_unit*qty,
            leverage=leverage,
            quantity=qty,
            entry_price=price,
            entry_fee=fee,
            margin=margin,
            opened_at_ms=int(datetime.now(timezone.utc).timestamp() * 1000),
        )
        self._persist()
        return SimulatedExecution(True, "filled", qty, price, fee)

    def pilot_state(self) -> PilotState:
        self._roll_day()
        open_risk = sum((p.risk_usd for p in self.positions.values()), Decimal("0"))
        correlated_risk = open_risk
        daily_loss = max(Decimal("0"), self.day_start_equity - self.marked_equity)
        cumulative_loss = max(Decimal("0"), self.starting_equity - self.marked_equity)
        return PilotState(open_risk, correlated_risk, daily_loss, cumulative_loss)

    def position_instruments(self, inst_type: InstrumentType | None = None) -> tuple[str, ...]:
        return tuple(
            inst for inst, p in self.positions.items()
            if inst_type is None or p.instrument_type is inst_type
        )

    def apply_funding(
        self,
        instrument: str,
        market: MarketSnapshot,
        realized_rate: Decimal,
        funding_time_ms: int,
    ) -> SimulatedEvent | None:
        pos = self.positions.get(instrument)
        if not pos or not (pos.instrument_type is InstrumentType.SWAP or "_XPERP-" in instrument):
            return None
        if funding_time_ms <= pos.opened_at_ms or funding_time_ms <= pos.last_funding_time_ms:
            return None
        notional = market.last * pos.quantity
        signed = -notional * realized_rate if pos.direction is Direction.LONG else notional * realized_rate
        self.equity += signed
        self.total_funding += signed
        pos.funding_pnl += signed
        pos.last_funding_time_ms = funding_time_ms
        self._persist()
        return SimulatedEvent("FUNDING", instrument, signed, pos.instrument_type.value, market.last, Decimal("0"))

    def manage_positions(
        self,
        market_updates,
        now: datetime | None = None,
    ) -> tuple[SimulatedEvent, ...]:
        now = now or datetime.now(timezone.utc)
        now_ms = int(now.timestamp() * 1000)
        events: list[SimulatedEvent] = []
        by_inst = {m.instrument: m for m in market_updates if -1 <= (now-m.timestamp).total_seconds() <= 5}
        changed = False

        for inst, pos in list(self.positions.items()):
            m = by_inst.get(inst)
            if not m:
                continue
            kind = None
            if pos.direction is Direction.LONG:
                if m.last <= pos.stop:
                    kind = "STOP"
                elif pos.targets and m.last >= pos.targets[0]:
                    kind = "TARGET"
                exit_price = m.bid * Decimal("0.9995")
                gross = (exit_price - pos.entry_price) * pos.quantity
            else:
                if m.last >= pos.stop:
                    kind = "STOP"
                elif pos.targets and m.last <= pos.targets[0]:
                    kind = "TARGET"
                exit_price = m.ask * Decimal("1.0005")
                gross = (pos.entry_price - exit_price) * pos.quantity

            if kind is None and now_ms - pos.opened_at_ms >= self.max_holding_seconds * 1000:
                kind = "TIME_EXIT"

            if kind:
                exit_fee = exit_price * pos.quantity * self.fee_bps / Decimal("10000")
                realized = gross - exit_fee
                self.equity += realized
                self.total_fees += exit_fee
                net_trade = realized - pos.entry_fee + pos.funding_pnl
                self.closed_pnls.append(net_trade)
                self.marks.pop(inst, None)
                events.append(SimulatedEvent(
                    kind,
                    inst,
                    net_trade,
                    pos.instrument_type.value,
                    exit_price,
                    exit_fee,
                ))
                del self.positions[inst]
                changed = True

        if changed:
            self._persist()
        return tuple(events)

    @property
    def marked_equity(self) -> Decimal:
        return self.equity + sum((self._open_pnl(inst,p) for inst,p in self.positions.items()), Decimal("0"))

    def _open_pnl(self, inst, pos) -> Decimal:
        mark = self.marks.get(inst, pos.entry_price)
        direction = Decimal("1") if pos.direction is Direction.LONG else Decimal("-1")
        return (mark-pos.entry_price)*pos.quantity*direction

    def mark_to_market(self, snapshots, now=None) -> None:
        now = now or datetime.now(timezone.utc)
        self._roll_day(now)
        for m in snapshots:
            pos=self.positions.get(m.instrument)
            if pos and -1 <= (now-m.timestamp).total_seconds() <= 5:
                self.marks[m.instrument] = m.bid if pos.direction is Direction.LONG else m.ask
        self.peak_equity=max(self.peak_equity,self.marked_equity)
        self.max_drawdown=max(self.max_drawdown,self.peak_equity-self.marked_equity)
        self._persist()

    def summary(self) -> dict:
        n=len(self.closed_pnls)
        wins=sum(x>0 for x in self.closed_pnls)
        return {
            "mode":"PAPER", "run_id":self.run_id, "started_at":self.started_at,
            "initial_equity":self.starting_equity,
            "cash_equity":self.equity, "total_equity":self.marked_equity,
            "unrealized_pnl":self.marked_equity-self.equity,
            "net_pnl":self.marked_equity-self.starting_equity,
            "return_pct":(self.marked_equity/self.starting_equity-1)*100,
            "fees":self.total_fees, "funding_pnl":self.total_funding,
            "closed_trades":n, "wins":wins, "losses":sum(x<0 for x in self.closed_pnls),
            "win_rate_pct":Decimal(wins)*100/n if n else None,
            "max_drawdown":self.max_drawdown,
            "reserved_margin":self.reserved_margin, "available_equity":self.available_equity,
            "open_positions":{inst:{"type":p.instrument_type.value,"direction":p.direction.value,
                "quantity":str(p.quantity),"entry_price":str(p.entry_price),"risk":str(p.risk_usd),
                "unrealized_pnl":str(self._open_pnl(inst,p))} for inst,p in self.positions.items()},
        }
