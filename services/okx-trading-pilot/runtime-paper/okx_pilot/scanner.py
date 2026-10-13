from datetime import datetime, timezone
from decimal import Decimal
from collections import defaultdict
from .domain import InstrumentType

class MarketScanner:
    def __init__(self, max_candidates=12, max_age_seconds=5):
        self.max_candidates=max_candidates
        self.max_age_seconds=max_age_seconds

    def rank(self, snapshots, now=None):
        now=now or datetime.now(timezone.utc)
        groups=defaultdict(list)
        for s in snapshots:
            if s.instrument_type not in (InstrumentType.SPOT,InstrumentType.SWAP,InstrumentType.FUTURES):
                continue
            age=(now-s.timestamp).total_seconds()
            if not -1 <= age <= self.max_age_seconds or s.volume_24h<=0:
                continue
            spread=(s.ask-s.bid)/s.last*Decimal('10000')
            if spread>200: continue
            score=s.volume_24h/(1+spread)
            groups[s.instrument_type].append((score,s.instrument,s))
        for values in groups.values(): values.sort(key=lambda x:(-x[0],x[1]))
        # Fair class interleaving; no fixed symbol whitelist and no class starvation.
        chosen=[]
        while len(chosen)<self.max_candidates and any(groups.values()):
            round_heads=sorted(((v[0][0],k.value,k) for k,v in groups.items() if v),reverse=True)
            for _,_,kind in round_heads:
                chosen.append(groups[kind].pop(0)[2])
                if len(chosen)>=self.max_candidates: break
        return tuple(chosen)
