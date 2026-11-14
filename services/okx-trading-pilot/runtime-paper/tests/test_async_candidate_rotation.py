"""Regression: sparse asynchronous AI slots must not starve 11 of 12 candidates."""
from datetime import datetime, timezone
from decimal import Decimal
from okx_pilot.domain import InstrumentType, MarketSnapshot, PilotLimits, PilotState
from okx_pilot.orchestrator import PilotOrchestrator
from okx_pilot.scanner import MarketScanner
from okx_pilot.paper import PaperBroker
from okx_pilot.risk import RiskGateway


class StubAsyncProvider:
    async_mode = True
    provider_name = "TEST_ASYNC"
    context_builder = None

    def __init__(self, max_concurrency=1):
        self.max_concurrency = max_concurrency

    def analyze(self, snapshot, context):
        # Deliberately invalid: tests scheduling only, never authorization/fills.
        return {"decision_id": "unit-test-only"}


def rows_of(n):
    now = datetime.now(timezone.utc)
    return tuple(MarketSnapshot(
        f"ASSET{i}-USDT", InstrumentType.SPOT,
        Decimal("100"), Decimal("99.99"), Decimal("100.01"),
        Decimal("100000"), now, open_24h=Decimal("100")
    ) for i in range(n))


def make_orchestrator(tmp_path, concurrency=1):
    broker = PaperBroker()
    orchestrator = PilotOrchestrator(
        MarketScanner(),
        StubAsyncProvider(max_concurrency=concurrency),
        RiskGateway(PilotLimits()),
        broker,
        tmp_path / "audit.jsonl",
    )
    return orchestrator, broker


def test_sparse_inference_visits_all_ranked_candidates_before_repeat(tmp_path):
    snapshots = rows_of(12)
    orchestrator, broker = make_orchestrator(tmp_path)
    selected = []
    try:
        for _ in range(13):
            result = orchestrator.run_cycle(snapshots, PilotState.zero())
            assert result.candidates == 12
            assert result.pending == 1
            name, (_, future) = next(iter(orchestrator._pending.items()))
            selected.append(name)
            future.result(timeout=5)
        expected = [s.instrument for s in MarketScanner().rank(snapshots)]
        assert selected[:12] == expected
        assert selected[12] == expected[0]
        assert broker.positions == {}
        assert broker.closed_pnls == []
    finally:
        orchestrator.close()


def test_two_workers_advance_once_per_successful_submission(tmp_path):
    snapshots = rows_of(4)
    orchestrator, broker = make_orchestrator(tmp_path, concurrency=2)
    try:
        result = orchestrator.run_cycle(snapshots, PilotState.zero())
        expected = [s.instrument for s in MarketScanner().rank(snapshots)]
        assert result.pending == 2
        assert list(orchestrator._pending) == expected[:2]
        for _, future in orchestrator._pending.values():
            future.result(timeout=5)
        result = orchestrator.run_cycle(snapshots, PilotState.zero())
        assert result.pending == 2
        assert list(orchestrator._pending) == expected[2:]
        assert not broker.positions
    finally:
        orchestrator.close()


def test_open_paper_position_is_skipped_without_starving_next_candidate(tmp_path):
    snapshots = rows_of(3)
    orchestrator, broker = make_orchestrator(tmp_path)
    expected = [s.instrument for s in MarketScanner().rank(snapshots)]
    # Selection only: the dummy value is never valued or passed to the broker.
    broker.positions[expected[0]] = object()
    try:
        orchestrator.run_cycle(snapshots, PilotState.zero())
        assert list(orchestrator._pending) == [expected[1]]
    finally:
        orchestrator.close()
