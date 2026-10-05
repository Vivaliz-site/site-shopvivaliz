from __future__ import annotations
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
import json
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from .domain import InstrumentType, MarketSnapshot

class MarketDataError(RuntimeError):
    pass

class OkxPublicMarketClient:
    BASE='https://www.okx.com'
    STABLE_QUOTES={'USD','USDT','USDC'}

    def __init__(self):
        self.metadata={}

    @classmethod
    def _get_json(cls,path,params=None,timeout=8.0):
        query='?'+urlencode(params) if params else ''
        try:
            req=Request(cls.BASE+path+query,headers={'User-Agent':'shopvivaliz-okx-paper/0.3','Cache-Control':'no-cache'},method='GET')
            with urlopen(req,timeout=timeout) as response:
                payload=json.load(response)
        except Exception as exc:
            raise MarketDataError(type(exc).__name__) from exc
        if not isinstance(payload,dict) or payload.get('code')!='0' or not isinstance(payload.get('data'),list):
            raise MarketDataError('invalid_okx_response')
        return payload

    def eligible_instrument_ids(self,inst_type,timeout=8.0):
        if inst_type not in (InstrumentType.SPOT,InstrumentType.SWAP,InstrumentType.FUTURES): return set()
        payload=self._get_json('/api/v5/public/instruments',{'instType':inst_type.value},timeout)
        result=set(); now_ms=int(datetime.now(timezone.utc).timestamp()*1000)
        for row in payload['data']:
            try:
                inst=row['instId']; step=Decimal(row['lotSz']); minimum=Decimal(row['minSz'])
                expiration=int(row.get('expTime') or '0')
                if inst_type is InstrumentType.SPOT:
                    if row.get('quoteCcy') not in self.STABLE_QUOTES: continue
                    unit=Decimal('1')
                else:
                    if row.get('ctType')!='linear' or row.get('settleCcy') not in self.STABLE_QUOTES: continue
                    unit=Decimal(row['ctVal'])
                    if row.get('ctValCcy')!=inst.split('-')[0]: continue
                    # Only unambiguous face-value contracts; never guess a multiplier.
                    if Decimal(row.get('ctMult') or '1')!=1: continue
                if not all(x.is_finite() and x>0 for x in (step,minimum,unit)): continue
                self.metadata[inst]={'step':step*unit,'minimum':minimum*unit,'expires':expiration}
                if row.get('state')!='live': continue
                if inst_type is InstrumentType.FUTURES and expiration and expiration-now_ms<=86700000: continue
                result.add(inst)
            except (KeyError,ValueError,InvalidOperation,TypeError): continue
        return result

    @staticmethod
    def parse_tickers(payload,eligible_ids=None,metadata=None):
        result=[]
        for row in payload.get('data',[]):
            try:
                inst=row['instId']
                if eligible_ids is not None and inst not in eligible_ids: continue
                typ=InstrumentType(row.get('instType','SPOT'))
                last=Decimal(row['last']); cv=row.get('volCcy24h')
                if cv not in (None,''):
                    volume=Decimal(cv)*(1 if typ is InstrumentType.SPOT else last)
                elif typ is InstrumentType.SPOT:
                    volume=Decimal(row.get('vol24h') or '0')*last
                else:
                    continue  # Contract counts cannot be substituted for base volume.
                m=(metadata or {}).get(inst,{})
                if metadata is not None and not m: continue
                result.append(MarketSnapshot(inst,typ,last,Decimal(row['bidPx']),Decimal(row['askPx']),volume,
                    datetime.fromtimestamp(int(row['ts'])/1000,tz=timezone.utc),
                    open_24h=Decimal(row.get('open24h') or row['last']),
                    quantity_step=m.get('step',Decimal('0.00000001')),
                    minimum_quantity=m.get('minimum',Decimal('0.00000001')),expires_at_ms=m.get('expires',0)))
            except (KeyError,ValueError,InvalidOperation,TypeError): continue
        return tuple(result)

    def tickers(self,inst_type=InstrumentType.SPOT,eligible_ids=None,timeout=8.0):
        payload=self._get_json('/api/v5/market/tickers',{'instType':inst_type.value},timeout)
        for row in payload['data']: row.setdefault('instType',inst_type.value)
        return self.parse_tickers(payload,eligible_ids,self.metadata)

    def realized_funding_since(self,instrument,since_ms,timeout=8.0):
        payload=self._get_json('/api/v5/public/funding-rate-history',{'instId':instrument,'before':str(since_ms),'limit':'400'},timeout)
        now_ms=int(datetime.now(timezone.utc).timestamp()*1000); out=[]
        for row in payload['data']:
            try:
                at=int(row['fundingTime']); rate=Decimal(row['realizedRate'])
                if rate.is_finite() and since_ms<at<=now_ms: out.append((rate,at))
            except (KeyError,ValueError,InvalidOperation,TypeError):
                raise MarketDataError('invalid_realized_funding')
        return sorted(out,key=lambda x:x[1])
