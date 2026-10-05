from decimal import Decimal
from okx_pilot.execution import ExecutionManager
from okx_pilot.risk import RiskVerdict
from okx_pilot.domain import Mode

class FakeOkx:
    def __init__(self): self.submits=0; self.orders={}; self.protection_ok=True
    def submit_order(self,intent):
        self.submits+=1; self.orders[intent['client_order_id']]={'order_id':'o1','filled_qty':Decimal('1')}; return self.orders[intent['client_order_id']]
    def lookup_order(self,cid): return self.orders.get(cid)
    def place_protection(self,order,stop): return self.protection_ok

class TimeoutAccepted(FakeOkx):
    def submit_order(self,intent):
        self.submits+=1; self.orders[intent['client_order_id']]={'order_id':'o1','filled_qty':Decimal('1')}; raise TimeoutError('timeout')

def approved(): return RiskVerdict(True,(),Decimal('5'),Decimal('2'))
def rejected(): return RiskVerdict(False,('RR',),Decimal('5'),Decimal('2'))

def test_rejected_verdict_never_submits():
    x=FakeOkx(); m=ExecutionManager(x,Mode.LIVE_PILOT,live_enabled=True)
    r=m.execute({'order_intent_id':'i1','stop':Decimal('90')},rejected())
    assert r.status=='REJECTED' and x.submits==0

def test_shadow_never_submits():
    x=FakeOkx(); m=ExecutionManager(x,Mode.SHADOW,live_enabled=False)
    assert m.execute({'order_intent_id':'i1','stop':Decimal('90')},approved()).status=='SHADOW'
    assert x.submits==0

def test_timeout_after_acceptance_reconciles_without_duplicate():
    x=TimeoutAccepted(); m=ExecutionManager(x,Mode.LIVE_PILOT,live_enabled=True)
    r=m.execute({'order_intent_id':'i1','stop':Decimal('90')},approved())
    assert r.status=='PROTECTED' and x.submits==1

def test_protection_failure_blocks_new_entries():
    x=FakeOkx(); x.protection_ok=False
    m=ExecutionManager(x,Mode.LIVE_PILOT,live_enabled=True)
    r=m.execute({'order_intent_id':'i1','stop':Decimal('90')},approved())
    assert r.status=='CRITICAL' and m.block_new_entries
