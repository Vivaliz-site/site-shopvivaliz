from dataclasses import dataclass
from .domain import Mode

@dataclass(frozen=True)
class ExecutionResult:
    status:str
    order:object|None=None

class ExecutionManager:
    def __init__(self,okx,mode:Mode,live_enabled:bool=False):
        self.okx=okx; self.mode=mode; self.live_enabled=live_enabled; self.block_new_entries=False
    def execute(self,intent:dict,verdict):
        if not verdict.approved: return ExecutionResult('REJECTED')
        if self.block_new_entries: return ExecutionResult('BLOCKED')
        if self.mode is Mode.SHADOW: return ExecutionResult('SHADOW')
        if self.mode is Mode.PAPER: return ExecutionResult('PAPER')
        if self.mode is Mode.LIVE_PILOT and not self.live_enabled: return ExecutionResult('BLOCKED')
        cid=intent['order_intent_id']; payload=dict(intent,client_order_id=cid)
        try:
            order=self.okx.submit_order(payload)
        except TimeoutError:
            order=self.okx.lookup_order(cid)
            if order is None: return ExecutionResult('AMBIGUOUS')
        if not self.okx.place_protection(order,intent['stop']):
            self.block_new_entries=True; return ExecutionResult('CRITICAL',order)
        return ExecutionResult('PROTECTED',order)
