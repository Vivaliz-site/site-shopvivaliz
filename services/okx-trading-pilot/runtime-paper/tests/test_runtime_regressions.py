from dataclasses import replace
from datetime import datetime, timezone, timedelta
from decimal import Decimal as D
import pytest
from okx_pilot.domain import InstrumentType as T, MarketSnapshot, PilotLimits, PilotState
from okx_pilot.decision import HeuristicDecisionProvider, DecisionParser
from okx_pilot.risk import RiskGateway
from okx_pilot.paper import PaperBroker
from okx_pilot.orchestrator import PilotOrchestrator
from okx_pilot.scanner import MarketScanner

def snap(inst='AAA-USDT',typ=T.SPOT,price='100',when=None):
 p=D(price)
 return MarketSnapshot(inst,typ,p,p-D('0.01'),p+D('0.01'),D('1000000'),when or datetime.now(timezone.utc),open_24h=p)

def verdict(m,risk='0.2'):
 now=datetime.now(timezone.utc); raw=HeuristicDecisionProvider().analyze(m); raw['suggested_risk']=risk
 return RiskGateway(PilotLimits()).authorize(DecisionParser.parse(raw,now),m,PilotState.zero(),now)

def test_all_candidates_not_only_two(tmp_path):
 class Provider(HeuristicDecisionProvider):
  def analyze(self,m):
   out=super().analyze(m); out['suggested_risk']='0.1'; return out
 b=PaperBroker(); rows=tuple(snap(f'ASSET{i}-USDT') for i in range(5))
 o=PilotOrchestrator(MarketScanner(),Provider(),RiskGateway(PilotLimits()),b,tmp_path/'audit.jsonl')
 assert o.run_cycle(rows).decisions==5
 assert o.run_cycle(rows).fills==0

def test_persistence_including_empty_portfolio(tmp_path):
 path=tmp_path/'state.json'; b=PaperBroker(state_path=path); assert path.exists()
 m=snap('AAA-USDT-SWAP',T.SWAP); assert b.submit(verdict(m),m).accepted
 other=PaperBroker(state_path=path)
 assert other.equity==b.equity and other.positions==b.positions

def test_open_loss_counts_for_loss_limit():
 m=snap('AAA-USDT-SWAP',T.SWAP); b=PaperBroker(); assert b.submit(verdict(m,'1'),m).accepted
 b.mark_to_market([snap(m.instrument,T.SWAP,'60')])
 assert b.summary()['unrealized_pnl']<0 and b.pilot_state().daily_loss>=D('15')

def test_sao_paulo_day_reset():
 b=PaperBroker(); b._roll_day(datetime(2026,10,6,2,59,tzinfo=timezone.utc)); assert b.day_key=='2026-10-05'
 b._roll_day(datetime(2026,10,6,3,0,tzinfo=timezone.utc)); assert b.day_key=='2026-10-06'

@pytest.mark.parametrize('number',['NaN','Infinity','-Infinity'])
def test_nonfinite_rejected(number):
 with pytest.raises((ValueError,TypeError)): snap(price=number)

def test_identity_mismatch_denied():
 m=snap(); v=verdict(m)
 assert not RiskGateway(PilotLimits()).authorize(v.intent,replace(m,instrument='OTHER-USDT'),PilotState.zero()).approved

def test_options_not_linear_fallback():
 m=snap('OPT-TEST',T.OPTION)
 assert not PaperBroker().submit(verdict(m),m).accepted

def test_stale_quote_cannot_exit():
 m=snap(); b=PaperBroker(); assert b.submit(verdict(m),m).accepted
 stale=snap(price='50',when=datetime.now(timezone.utc)-timedelta(seconds=60))
 assert b.manage_positions([stale])==() and len(b.positions)==1

def test_xperp_funding_is_charged_once():
 m=snap('AAA-USD_UM_XPERP-310404',T.FUTURES); b=PaperBroker(); assert b.submit(verdict(m),m).accepted
 at=b.positions[m.instrument].opened_at_ms+1000
 event=b.apply_funding(m.instrument,m,D('0.001'),at)
 assert event is not None and event.pnl<0
 assert b.apply_funding(m.instrument,m,D('0.001'),at) is None

def test_total_equity_includes_open_pnl():
 m=snap(); b=PaperBroker(); assert b.submit(verdict(m),m).accepted
 b.mark_to_market([snap(price='101')]); report=b.summary()
 assert report['total_equity']>b.equity
 assert report['net_pnl']==report['total_equity']-D('100')

def test_contract_step_and_minimum():
 m=replace(snap('AAA-USDT-SWAP',T.SWAP),quantity_step=D('0.1'),minimum_quantity=D('0.2'))
 ex=PaperBroker().submit(verdict(m,'0.3'),m)
 assert not ex.accepted or (ex.filled_quantity % D('0.1')==0 and ex.filled_quantity>=D('0.2'))

def test_supported_classes_are_not_starved():
 rows=[replace(snap(f'A{i}-USDT'),volume_24h=D('1000000000')) for i in range(15)]
 rows += [snap('X-USDT-SWAP',T.SWAP),snap('Y-USD_UM-261225',T.FUTURES)]
 picked=MarketScanner().rank(rows)
 assert len(picked)<=12 and {r.instrument_type for r in picked}=={T.SPOT,T.SWAP,T.FUTURES}

def test_corrupt_state_is_not_reset(tmp_path):
 path=tmp_path/'state.json'; path.write_text('{broken')
 with pytest.raises(ValueError): PaperBroker(state_path=path)
 assert path.read_text()=='{broken'

def test_closed_trade_reconciles_fees_and_funding():
 m=snap('AAA-USDT-SWAP',T.SWAP); b=PaperBroker(); assert b.submit(verdict(m),m).accepted
 at=b.positions[m.instrument].opened_at_ms+1000
 b.apply_funding(m.instrument,m,D('0.001'),at)
 events=b.manage_positions([snap(m.instrument,T.SWAP,'110')])
 assert len(events)==1
 assert b.equity-D('100')==events[0].pnl
 assert b.summary()['closed_trades']==1

def test_insufficient_margin_does_not_create_exposure():
 m=snap(); b=PaperBroker(); result=b.submit(verdict(m,'10'),m)
 assert not result.accepted and result.reason=='insufficient_paper_margin'
 assert b.equity==100 and not b.positions

def test_size_includes_cost_buffer():
 m=snap('AAA-USDT-SWAP',T.SWAP); b=PaperBroker(); v=verdict(m)
 ex=b.submit(v,m); assert ex.accepted
 pos=b.positions[m.instrument]
 assert 0<pos.risk_usd<=v.risk_usd
 assert pos.risk_usd>abs(pos.entry_price-pos.stop)*pos.quantity
