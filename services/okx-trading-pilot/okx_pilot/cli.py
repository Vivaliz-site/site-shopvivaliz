import argparse, json
from .domain import Mode

def main(argv=None):
    p=argparse.ArgumentParser(prog='okx-pilot')
    sub=p.add_subparsers(dest='cmd',required=True)
    sub.add_parser('status')
    sub.add_parser('shadow-once')
    sub.add_parser('paper-once')
    ns=p.parse_args(argv)
    if ns.cmd=='status':
        print(json.dumps({'mode':Mode.SHADOW.value,'live_execution_enabled':False,'withdrawals_enabled':False}))
        return 0
    print(json.dumps({'mode':Mode.SHADOW.value if ns.cmd=='shadow-once' else Mode.PAPER.value,'live_execution_enabled':False}))
    return 0

if __name__=='__main__':
    raise SystemExit(main())
