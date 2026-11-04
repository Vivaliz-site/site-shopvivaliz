#!/usr/bin/env python3
"""Regression: queued old Master Production Pipeline must not overwrite new main.

Executes the exact inline activation guard from the GitHub workflow with a
fake, read-only git ls-remote binary and a fake GitHub output file. No deploy,
no network and no access to production credentials or release symlinks.
"""
import os
import subprocess
import tempfile
import textwrap
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
WF=(ROOT/'.github/workflows/master-production-pipeline.yml').read_text(encoding='utf-8')
LINES=WF.splitlines()

start=next(i for i,line in enumerate(LINES) if 'assert_deploy_sha_is_current_main() {' in line)
end=next(i for i,line in enumerate(LINES[start+1:],start+1)
         if line.strip()=='assert_deploy_sha_is_current_main')
GUARD=textwrap.dedent('\n'.join(LINES[start:end+1]))
assert 'git -C "$root/sync-repo" ls-remote origin refs/heads/main' in GUARD
assert "printf 'activated=false\\n' >> \"$GITHUB_OUTPUT\"" in GUARD
assert WF.count('assert_deploy_sha_is_current_main')>=3
assert 'assert_deploy_sha_is_current_main\n          ln -sfn "releases/' in WF
assert "activated: ${{ steps.activate.outputs.activated }}" in WF
assert "if: ${{ needs.deploy.outputs.activated == 'true' }}" in WF
assert 'printf \'activated=true\\n\' >> "$GITHUB_OUTPUT"' in WF
assert WF.index('flock -w 120 8') < WF.index('assert_deploy_sha_is_current_main() {')

def scenario(expected:str, remote:str, failure:bool=False):
    with tempfile.TemporaryDirectory(prefix='sv-deploy-fence-test-') as root:
        path=Path(root)
        (path/'bin').mkdir()
        git=path/'bin/git'
        git.write_text('#!/bin/sh\n'
           'if [ "$TEST_GIT_FAILURE" = "1" ]; then exit 14; fi\n'
           'printf "%s\\trefs/heads/main\\n" "$TEST_MAIN_SHA"\n',encoding='utf-8')
        git.chmod(0o755)
        output=path/'github-output'
        output.touch()
        script='set -euo pipefail\nroot="/nonexistent"\nsha="'+expected+'"\n'+GUARD+'\necho AFTER_GUARD\n'
        env=os.environ.copy()
        env.update({'PATH':str(path/'bin')+':'+env.get('PATH',''),
                    'GITHUB_OUTPUT':str(output),'TEST_MAIN_SHA':remote,
                    'TEST_GIT_FAILURE':'1' if failure else '0'})
        result=subprocess.run(['/bin/bash','-c',script],env=env,capture_output=True,text=True,timeout=7)
        return result,output.read_text(encoding='utf-8')

fresh='1'*40
newer='2'*40
run,output=scenario(fresh,fresh)
assert run.returncode==0 and 'AFTER_GUARD' in run.stdout, (run,output)
assert output=='',output

run,output=scenario(fresh,newer)
assert run.returncode==0 and 'DEPLOY_SUPERSEDED=SKIP' in run.stdout,(run,output)
assert 'AFTER_GUARD' not in run.stdout and output=='activated=false\n',(run,output)

run,output=scenario(fresh,'unexpected')
assert run.returncode==2 and 'INVALID' not in run.stdout.upper(),(run,output)
assert 'AFTER_GUARD' not in run.stdout and not output,(run,output)

run,output=scenario(fresh,'',failure=True)
assert run.returncode==2 and 'AFTER_GUARD' not in run.stdout and not output,(run,output)

print('PASS master deploy stale fence: latest, stale, invalid, unreachable + monitor/evidence guard')
