#!/usr/bin/env python3
"""Bounded read-only operational check; never places even a paper order."""
import json
from datetime import datetime,timezone
from pathlib import Path
import subprocess

ROOT=Path('/opt/shopvivaliz-okx-paper/current')
STATE=Path('/var/lib/shopvivaliz-okx-paper/paper-state-v3.json')
STATUS=Path('/var/lib/shopvivaliz-okx-paper/paper-status-v3.json')

def main():
    subprocess.run(['/usr/bin/systemctl','is-active','--quiet','shopvivaliz-okx-paper.service'],check=True,timeout=10)
    subprocess.run(['/usr/bin/systemctl','is-enabled','--quiet','shopvivaliz-okx-paper.service'],check=True,timeout=10)
    report=json.loads(STATUS.read_text()); ledger=json.loads(STATE.read_text())
    age=(datetime.now(timezone.utc)-datetime.fromisoformat(report['updated_at'])).total_seconds()
    assert 0<=age<=45, 'stale_status'
    assert report['mode']=='PAPER' and report['real_orders_enabled'] is False
    assert report.get('decision_provider')=='CODEX_20_LAYER'
    assert report.get('ai_20_layers_configured') is True
    assert report.get('ai_20_layers_active') is True, 'ai_20_layers_not_active'
    assert report.get('decision_model')=='gpt-5.6-terra', 'wrong_decision_model'
    assert report['run_id']==ledger['run_id'] and ledger['starting_equity']=='100'
    assert report['errors']==[] and not report['entries_blocked'], 'market_data_not_ready'
    assert all(report['markets'].get(x,0)>0 for x in ('SPOT','SWAP','FUTURES'))
    assert Path(report['release'])==ROOT.resolve(), 'release_mismatch'
    sha=(ROOT/'SOURCE_COMMIT').read_text().strip()
    assert len(sha)==40
    unit=subprocess.check_output(['/usr/bin/systemctl','show','shopvivaliz-okx-paper.service','-p','ExecStart'],text=True,timeout=10)
    assert '--cycles 0' in unit and '--state' in unit and '--status' in unit
    report.update({'runtime_validation':'PASS','source_commit':sha,'status_age_seconds':round(age,3)})
    print(json.dumps(report,sort_keys=True))

if __name__=='__main__': main()
