from pathlib import Path
import yaml

violations=[]
for path in sorted(Path('.github/workflows').glob('*.yml')):
    data=yaml.safe_load(path.read_text(encoding='utf-8')) or {}
    for job_name, job in (data.get('jobs') or {}).items():
        if not isinstance(job, dict):
            continue
        runs=job.get('runs-on')
        labels=' '.join(runs) if isinstance(runs,list) else str(runs or '')
        if 'shopvivaliz-a1-deploy' not in labels:
            continue
        if path.name == 'master-production-pipeline.yml' and job_name == 'deploy':
            continue
        for step in job.get('steps') or []:
            if not isinstance(step,dict):
                continue
            blob=(str(step.get('name',''))+'\n'+str(step.get('run',''))).lower()
            waits=('sleep ' in blob or 'seq 1' in blob) and (
                '.release-sha' in blob or 'deployment/latest.json' in blob or
                'wait for production' in blob or 'wait for exact' in blob or 'aguardar release' in blob
            )
            if waits:
                violations.append(f'{path}:{job_name}:{step.get("name", "unnamed")}')
if violations:
    raise SystemExit('production runner release-wait violations:\n'+'\n'.join(violations))
print('production runner release-wait policy: PASS')
