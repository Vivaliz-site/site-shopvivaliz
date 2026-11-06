from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile
import unittest

class SecurityMergeGuardTest(unittest.TestCase):
    def test_pending_human_review_is_denied_before_merge(self):
        if shutil.which("jq") is None:
            self.skipTest("jq is required by the real PR merge script")
        script = Path(__file__).resolve().parents[1] / "scripts" / "merge_green_pr_via_vm.sh"
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            meta = root / "pr.json"
            log = root / "calls"
            sha = "a" * 40
            meta.write_text(json.dumps({
                "state": "open", "draft": False, "base": {"ref": "main"},
                "head": {"repo": {"full_name": "Vivaliz-site/site-shopvivaliz"}, "sha": sha},
                "labels": [], "requested_reviewers": [{"login": "shopvivaliz-human"}],
            }), encoding="utf-8")
            gh = root / "gh"
            gh.write_text(
                '#!/bin/sh\n'
                'printf "%s\\n" "$*" >> "$STUB_GH_LOG"\n'
                'case "$*" in\n'
                '  "api user --jq .login") echo fredmourao-ai ;;\n'
                '  "api repos/Vivaliz-site/site-shopvivaliz/pulls/3725") cat "$STUB_GH_META" ;;\n'
                '  *) exit 93 ;;\n'
                'esac\n',
                encoding="utf-8",
            )
            gh.chmod(0o755)
            env = dict(os.environ)
            env["PATH"] = str(root) + os.pathsep + env.get("PATH", "")
            env["STUB_GH_META"] = str(meta)
            env["STUB_GH_LOG"] = str(log)
            result = subprocess.run(
                ["bash", str(script), "3725", sha], env=env,
                text=True, capture_output=True, check=False, timeout=8,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("owner human review is pending", result.stderr)
            self.assertNotIn("--method PUT", log.read_text(encoding="utf-8"))

    def test_owner_security_files_denied_before_merge(self):
        script=(Path(__file__).resolve().parents[1]/"scripts/merge_green_pr_via_vm.sh").read_text()
        self.assertIn("security/owner-approvals/*",script)
        self.assertIn("security/requests/*",script)
        self.assertIn("owner-protected PR must not be autonomously merged",script)
        self.assertIn('technical_gh() {', script)
        self.assertIn('env -u GH_TOKEN -u GITHUB_TOKEN -u GIT_ASKPASS -u SSH_ASKPASS gh "$@"', script)
        self.assertIn("[[ \"$independent_actor\" == 'fredmourao-ai' ]]", script)
        self.assertLess(script.index('independent_actor='), script.index('result="$(technical_gh api --method PUT'))
        self.assertLess(script.index("changed_files="), script.index('result="$(technical_gh api --method PUT'))

if __name__=="__main__": unittest.main()
