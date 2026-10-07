import json
from datetime import datetime, timedelta, timezone
from decimal import Decimal as D
import pytest

from okx_pilot.decision import CodexBridgeDecisionProvider, ChatGPTBrowserDecisionProvider, LoginFailoverDecisionProvider, DecisionParser, DecisionValidationError, REQUIRED_LAYER_NAMES
from okx_pilot.domain import InstrumentType, MarketSnapshot

def snap(inst="BTC-USDT-SWAP", typ=InstrumentType.SWAP):
    return MarketSnapshot(inst,typ,D("85000"),D("84999"),D("85001"),D("100000000"),datetime.now(timezone.utc),open_24h=D("84000"))

def valid_payload(m=None):
    m=m or snap(); now=datetime.now(timezone.utc)
    return {
        "decision_id":"d-1","decision":"TRADE","instrument":m.instrument,"instrument_type":m.instrument_type.value,"direction":"LONG",
        "entry_low":str(m.bid),"entry_high":str(m.ask),"stop":"84000","targets":["87000"],"suggested_risk":"1.5",
        "suggested_leverage":"2","time_horizon":"intraday","confidence":"78","expected_rr":"2",
        "thesis":"trend plus liquidity","supporting_evidence":["price above open"],"contrary_evidence":["funding can reverse"],
        "invalidation":"break below stop","market_snapshot_ts":m.timestamp.isoformat(),"created_at":now.isoformat(),
        "expires_at":(now+timedelta(seconds=90)).isoformat(),
        "layers":[{"id":i+1,"name":name,"assessment":"supportive","evidence":[f"layer:{name}"],"risk_flags":[]}
                  for i,name in enumerate(REQUIRED_LAYER_NAMES)],
    }

def test_codex_provider_uses_real_bridge_protocol_and_strict_prompt():
    m=snap(); captured={}
    def transport(url,payload,timeout):
        captured.update(payload)
        return {"ok":True,"text":json.dumps(valid_payload(m)),"model":"gpt-5.6-terra","transport":"codex_chatgpt"}
    provider=CodexBridgeDecisionProvider("http://127.0.0.1:17656/v1/respond",transport=transport)
    raw=provider.analyze(m,context={"paper_account":{"equity":"100"},"missing_sections":[]})
    assert raw["decision"]=="TRADE"
    assert set(captured)=={"model","effort","prompt","web_search","profile"}
    assert captured["profile"]=="dev"
    assert captured["model"]=="gpt-5.6-terra" and captured["effort"]=="medium" and captured["web_search"] is False
    prompt=captured["prompt"]; compact=prompt.replace(" ","")
    for i,name in enumerate(REQUIRED_LAYER_NAMES,1):
        assert f'"id":{i}' in compact and name in prompt
    lowered=prompt.lower()
    assert "api_key" not in lowered and "passphrase" not in lowered and "bearer " not in lowered
    assert '"decision":"TRADE"' in compact

def test_chatgpt_browser_provider_requires_sol_xhigh_login_transport():
    m=snap(); captured={}
    def transport(url,payload,timeout):
        captured.update(payload)
        return {"ok":True,"text":json.dumps(valid_payload(m)),"model":"gpt-5.6-sol","effort":"xhigh","transport":"chatgpt_browser","profile":"okx"}
    provider=ChatGPTBrowserDecisionProvider("http://127.0.0.1:17657/v1/respond",transport=transport)
    raw=provider.analyze(m,context={"paper_account":{"equity":"100"}})
    assert raw["decision"]=="TRADE"
    assert captured["model"]=="gpt-5.6-sol"
    assert captured["effort"]=="xhigh"
    assert captured["profile"]=="okx"
    assert captured["web_search"] is False


def test_login_failover_uses_browser_only_for_primary_availability_failures():
    m=snap(); calls=[]
    class Primary:
        provider_name="CODEX_20_LAYER"
        def analyze(self,*_):
            calls.append("primary")
            raise DecisionValidationError("decision_bridge:codex_unavailable")
    class Fallback:
        provider_name="CHATGPT_BROWSER_20_LAYER"; model="gpt-5.6-sol"; effort="xhigh"
        def analyze(self,*_):
            calls.append("fallback")
            return valid_payload(m)
    provider=LoginFailoverDecisionProvider(Primary(),Fallback())
    raw=provider.analyze(m,{})
    assert raw["instrument"]==m.instrument
    assert calls==["primary","fallback"]
    assert provider.last_provider_name=="CHATGPT_BROWSER_20_LAYER"
    assert provider.last_model=="gpt-5.6-sol"
    assert provider.last_effort=="xhigh"
    assert provider.fallback_uses_total==1


def test_login_failover_never_masks_primary_integrity_failure():
    m=snap(); calls=[]
    class Primary:
        provider_name="CODEX_20_LAYER"
        def analyze(self,*_):
            calls.append("primary")
            raise DecisionValidationError("decision_bridge:model_mismatch")
    class Fallback:
        provider_name="CHATGPT_BROWSER_20_LAYER"; model="gpt-5.6-sol"; effort="xhigh"
        def analyze(self,*_):
            calls.append("fallback")
            return valid_payload(m)
    provider=LoginFailoverDecisionProvider(Primary(),Fallback())
    with pytest.raises(DecisionValidationError,match="model_mismatch"):
        provider.analyze(m,{})
    assert calls==["primary"]


def test_provider_rejects_non_json_wrong_model_and_unavailable():
    m=snap()
    for response in [
        {"ok":True,"text":"not-json","model":"gpt-5.6-terra","transport":"codex_chatgpt"},
        {"ok":True,"text":json.dumps(valid_payload(m)),"model":"gpt-5.6-sol"},
        {"ok":False,"error":"codex_unavailable","attempts":["transport"]},
    ]:
        provider=CodexBridgeDecisionProvider("http://127.0.0.1:17656/v1/respond",transport=lambda *a,response=response: response)
        with pytest.raises(DecisionValidationError): provider.analyze(m,context={})

def test_parser_requires_named_20_layers_and_semantic_evidence():
    m=snap(); raw=valid_payload(m)
    intent=DecisionParser.parse(raw,now=datetime.now(timezone.utc))
    assert intent.time_horizon=="intraday" and intent.market_snapshot_ts==m.timestamp
    assert intent.supporting_evidence and intent.contrary_evidence
    broken=valid_payload(m); broken["layers"][4]["name"]="wrong"
    with pytest.raises(DecisionValidationError): DecisionParser.parse(broken,datetime.now(timezone.utc))
    broken=valid_payload(m); broken["layers"][0]["evidence"]=[]
    with pytest.raises(DecisionValidationError): DecisionParser.parse(broken,datetime.now(timezone.utc))

@pytest.mark.parametrize("field,value",[("confidence","101"),("confidence","NaN"),("suggested_risk","11"),("suggested_leverage","21"),("expected_rr","NaN")])
def test_parser_rejects_invalid_model_controls(field,value):
    raw=valid_payload(); raw[field]=value
    with pytest.raises(DecisionValidationError): DecisionParser.parse(raw,datetime.now(timezone.utc))

def test_hold_may_omit_order_shape_but_keeps_audit_evidence():
    raw=valid_payload(); raw.update({"decision":"HOLD","direction":"LONG","entry_low":"0","entry_high":"0","stop":"0","targets":[],"suggested_risk":"0","suggested_leverage":"1","expected_rr":"0"})
    intent=DecisionParser.parse(raw,datetime.now(timezone.utc))
    assert intent.decision.value=="HOLD"

def test_context_builder_sanitizes_public_and_paper_context():
    from okx_pilot.decision import DecisionContextBuilder
    class Client:
        def decision_market_context(self,m):
            return {"candles":{"1H":[["1","2"]]},"order_book":{"bids":[["1","2"]]},"funding":None,"open_interest":None}
    class Broker:
        def summary(self): return {"total_equity":D("99"),"open_positions":{"X":{"risk":D("1")}},"run_id":"opaque"}
        def pilot_state(self):
            class S:
                open_risk=D("1"); correlated_risk=D("1"); daily_loss=D("0"); cumulative_loss=D("1")
            return S()
    ctx=DecisionContextBuilder(Client(),Broker()).build(snap())
    text=json.dumps(ctx,default=str).lower()
    assert "secret" not in text and "token" not in text and "auth" not in text
    assert ctx["paper_account"]["total_equity"]=="99" and ctx["risk_state"]["open_risk"]=="1"

def test_async_orchestrator_limits_inflight_to_two_and_never_blocks(tmp_path):
    import time
    from okx_pilot.orchestrator import PilotOrchestrator
    from okx_pilot.paper import PaperBroker
    from okx_pilot.risk import RiskGateway
    from okx_pilot.domain import PilotLimits
    from okx_pilot.scanner import MarketScanner

    class Provider:
        async_mode=True; max_concurrency=2; provider_name="TEST_20_LAYER"; context_builder=None
        def analyze(self,m,context=None):
            time.sleep(0.02)
            return valid_payload(m)

    rows=tuple(snap(f"A{i}-USDT-SWAP") for i in range(6))
    orch=PilotOrchestrator(MarketScanner(),Provider(),RiskGateway(PilotLimits()),PaperBroker(),tmp_path/"audit.jsonl")
    first=orch.run_cycle(rows)
    assert first.pending==2 and first.decisions==0
    time.sleep(0.04)
    second=orch.run_cycle(rows)
    assert second.decisions==2
    assert second.pending<=2
    assert orch.provider_ready is True
    orch.close()


def test_async_provider_failure_is_fail_closed_not_daemon_crash(tmp_path):
    import time
    from okx_pilot.orchestrator import PilotOrchestrator
    from okx_pilot.paper import PaperBroker
    from okx_pilot.risk import RiskGateway
    from okx_pilot.domain import PilotLimits
    from okx_pilot.scanner import MarketScanner

    class Provider:
        async_mode=True; max_concurrency=2; provider_name="BROKEN"; context_builder=None
        def analyze(self,m,context=None):
            raise DecisionValidationError("bridge_down")

    rows=(snap("A-USDT-SWAP"),)
    orch=PilotOrchestrator(MarketScanner(),Provider(),RiskGateway(PilotLimits()),PaperBroker(),tmp_path/"audit.jsonl")
    assert orch.run_cycle(rows).pending==1
    time.sleep(0.01)
    out=orch.run_cycle(rows)
    assert out.provider_errors==1 and not orch.paper_broker.positions
    orch.close()


def test_async_bridge_unavailable_enters_cooldown_without_resubmission(tmp_path):
    import time
    from okx_pilot.orchestrator import PilotOrchestrator
    from okx_pilot.paper import PaperBroker
    from okx_pilot.risk import RiskGateway
    from okx_pilot.domain import PilotLimits
    from okx_pilot.scanner import MarketScanner

    calls=[]
    class Provider:
        async_mode=True; max_concurrency=2; provider_name="CODEX_20_LAYER"; context_builder=None
        def analyze(self,m,context=None):
            calls.append(m.instrument)
            raise DecisionValidationError("decision_bridge:codex_unavailable")

    rows=tuple(snap(f"Q{i}-USDT-SWAP") for i in range(4))
    orch=PilotOrchestrator(MarketScanner(),Provider(),RiskGateway(PilotLimits()),PaperBroker(),tmp_path/"audit.jsonl")
    first=orch.run_cycle(rows)
    assert first.pending==2
    time.sleep(0.02)
    second=orch.run_cycle(rows)
    assert second.provider_errors==2
    assert second.pending==0
    assert orch.provider_cooldown_seconds > 0
    assert orch.provider_ready is False
    attempts=len(calls)
    third=orch.run_cycle(rows)
    assert third.pending==0
    time.sleep(0.02)
    assert len(calls)==attempts
    orch.close()


def test_production_runner_has_no_heuristic_fallback():
    from pathlib import Path
    source=(Path(__file__).parents[1]/"scripts"/"run.py").read_text()
    assert "HeuristicDecisionProvider" not in source
    assert "CodexBridgeDecisionProvider" in source
    assert "ChatGPTBrowserDecisionProvider" in source
    assert "LoginFailoverDecisionProvider" in source
    assert "gpt-5.6-sol" in source and "xhigh" in source
    assert "'ai_20_layers_active':orch.provider_ready" in source
    assert "'decision_provider_cooldown_seconds':round(orch.provider_cooldown_seconds,3)" in source


def test_hold_allows_zero_leverage_when_no_position_is_opened():
    raw=valid_payload()
    raw.update({
        "decision":"HOLD",
        "direction":"LONG",
        "entry_low":"0",
        "entry_high":"0",
        "stop":"0",
        "targets":[],
        "suggested_risk":"0",
        "suggested_leverage":"0",
        "expected_rr":"0",
        "confidence":"62",
    })
    intent=DecisionParser.parse(raw,datetime.now(timezone.utc))
    assert intent.decision.value=="HOLD"
    assert intent.suggested_leverage==D("0")


def test_trade_still_rejects_zero_leverage():
    raw=valid_payload()
    raw["suggested_leverage"]="0"
    with pytest.raises(DecisionValidationError):
        DecisionParser.parse(raw,datetime.now(timezone.utc))
