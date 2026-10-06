#!/usr/bin/env python3
"""Public market data + local PAPER ledger. No exchange order client."""
from __future__ import annotations
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import fcntl
import json
import os
from pathlib import Path
import signal
import time
from okx_pilot.domain import PilotLimits, InstrumentType
from okx_pilot.market import OkxPublicMarketClient, MarketDataError
from okx_pilot.scanner import MarketScanner
from okx_pilot.decision import CodexBridgeDecisionProvider, DecisionContextBuilder
from okx_pilot.risk import RiskGateway
from okx_pilot.paper import PaperBroker
from okx_pilot.orchestrator import PilotOrchestrator

TYPES=(InstrumentType.SPOT,InstrumentType.SWAP,InstrumentType.FUTURES)
STOP=False

def stop_handler(*_):
    global STOP
    STOP=True

def atomic_json(path,payload):
    path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_suffix(path.suffix+'.tmp')
    with tmp.open('w',encoding='utf-8') as f:
        json.dump(payload,f,default=str,sort_keys=True); f.flush(); os.fsync(f.fileno())
    tmp.replace(path)

def main():
    p=argparse.ArgumentParser(description='OKX PAPER-only continuous simulation')
    p.add_argument('--cycles',type=int,default=1,help='0 = continuous')
    p.add_argument('--interval',type=float,default=5)
    p.add_argument('--audit',type=Path,required=True)
    p.add_argument('--state',type=Path,required=True)
    p.add_argument('--status',type=Path,required=True)
    p.add_argument('--decision-url',default='http://127.0.0.1:17656/v1/respond')
    p.add_argument('--decision-model',default='gpt-5.6-terra')
    p.add_argument('--decision-effort',default='medium',choices=('low','medium','high','xhigh'))
    args=p.parse_args()
    if args.cycles<0 or not 1<=args.interval<=3600: p.error('invalid cycles/interval')
    args.state.parent.mkdir(parents=True,exist_ok=True)
    lock=args.state.with_suffix('.lock').open('a')
    try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError: raise SystemExit('another_writer_active')
    signal.signal(signal.SIGTERM,stop_handler); signal.signal(signal.SIGINT,stop_handler)
    broker=PaperBroker(state_path=args.state)
    client=OkxPublicMarketClient()
    context_builder=DecisionContextBuilder(client,broker)
    decision_provider=CodexBridgeDecisionProvider(
        args.decision_url, model=args.decision_model, effort=args.decision_effort,
        timeout_seconds=45, context_builder=context_builder,
    )
    orch=PilotOrchestrator(MarketScanner(),decision_provider,RiskGateway(PilotLimits()),broker,args.audit)
    orch._audit({'event':'RUN_START','run_id':broker.run_id,'started_at':broker.started_at,'mode':'PAPER',
                 'decision_provider':'CODEX_20_LAYER','decision_model':args.decision_model})
    eligible={}; refreshed=0.; cycle=0
    with ThreadPoolExecutor(max_workers=3) as pool:
        while not STOP and (args.cycles==0 or cycle<args.cycles):
            cycle+=1; errors=[]; now_clock=time.monotonic()
            if not eligible or now_clock-refreshed>=3600:
                futures={typ:pool.submit(client.eligible_instrument_ids,typ) for typ in TYPES}
                for typ,job in futures.items():
                    try: eligible[typ]=job.result()
                    except MarketDataError as exc:
                        eligible[typ]=set(); errors.append('metadata:'+typ.value+':'+str(exc))
                refreshed=now_clock if not errors else now_clock-3550
            jobs={typ:pool.submit(client.tickers,typ,eligible.get(typ,set())|set(broker.position_instruments(typ))) for typ in TYPES}
            snapshots=[]; counts={}
            for typ,job in jobs.items():
                try:
                    rows=job.result(); snapshots.extend(rows); counts[typ.value]=len(rows)
                except MarketDataError as exc:
                    counts[typ.value]=0; errors.append('market:'+typ.value+':'+str(exc))
            by_inst={m.instrument:m for m in snapshots}
            broker.mark_to_market(snapshots)
            funding_jobs={}
            for inst,pos in broker.positions.items():
                if pos.instrument_type is InstrumentType.SWAP or '_XPERP-' in inst:
                    funding_jobs[inst]=pool.submit(client.realized_funding_since,inst,max(pos.opened_at_ms,pos.last_funding_time_ms))
            for inst,job in funding_jobs.items():
                try:
                    history=job.result()
                    if inst not in by_inst: errors.append('missing_funding_mark:'+inst); continue
                    for rate,at in history: orch.audit_funding(broker.apply_funding(inst,by_inst[inst],rate,at))
                except MarketDataError as exc: errors.append('funding:'+inst+':'+str(exc))
            orch.manage_positions(snapshots)
            now=datetime.now(timezone.utc)
            fresh={m.instrument for m in snapshots if -1<=(now-m.timestamp).total_seconds()<=5}
            if set(broker.positions)-fresh: errors.append('missing_or_stale_position_quotes')
            if not all(counts.get(typ.value,0)>0 for typ in TYPES): errors.append('incomplete_market_universe')
            candidates=[m for m in snapshots if m.instrument in eligible.get(m.instrument_type,set())]
            result=orch.run_cycle(candidates) if not errors else None
            broker.mark_to_market(snapshots)
            report=broker.summary()
            report.update({'updated_at':datetime.now(timezone.utc).isoformat(),'cycle':cycle,'markets':counts,
                'eligible':{k.value:len(v) for k,v in eligible.items()},'entries_blocked':bool(errors),'errors':errors,
                'decision_provider':'CODEX_20_LAYER','decision_model':args.decision_model,
                'ai_20_layers_configured':True,'ai_20_layers_active':orch.decision_successes_total>0,
                'decision_pending':orch.pending_count,'decision_provider_errors_total':orch.provider_errors_total,
                'decision_provider_last_error':orch.last_provider_error,'real_orders_enabled':False,
                'candidates':result.candidates if result else 0,'decisions':result.decisions if result else 0,
                'fills_this_cycle':result.fills if result else 0,'release':str(Path(__file__).resolve().parents[1]),
                'valuation_note':'USD/USDT/USDC assumed 1:1; open PnL uses current bid/ask before future exit costs; funding uses realized rates with current observed price; no exchange-exact liquidation model'})
            atomic_json(args.status,report)
            orch._audit({'event':'CYCLE','run_id':broker.run_id,'cycle':cycle,'markets':counts,'entries_blocked':bool(errors),
                'equity':str(broker.marked_equity),'positions':len(broker.positions),'errors':errors})
            print(json.dumps(report,default=str,sort_keys=True),flush=True)
            if args.cycles and cycle>=args.cycles: break
            deadline=time.monotonic()+args.interval
            while not STOP and time.monotonic()<deadline: time.sleep(min(.2,max(0,deadline-time.monotonic())))
    orch.close()
    broker._persist()
    fcntl.flock(lock,fcntl.LOCK_UN); lock.close()

if __name__=='__main__': main()
