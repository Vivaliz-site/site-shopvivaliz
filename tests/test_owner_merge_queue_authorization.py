"""Security: one human approval can be consumed by official GH merge queue only."""
from datetime import datetime, timezone
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("owner_queue_grant",ROOT/"scripts/validate-owner-pr-grant.py")
MODULE=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
SPEC2=importlib.util.spec_from_file_location("owner_queue_runner",ROOT/"scripts/ops/owner_approved_merge_queue.py")
RUNNER=importlib.util.module_from_spec(SPEC2)
SPEC2.loader.exec_module(RUNNER)
SPEC3=importlib.util.spec_from_file_location("owner_queue_rules",ROOT/"scripts/ops/activate_owner_merge_queue_ruleset.py")
RULESET=importlib.util.module_from_spec(SPEC3)
SPEC3.loader.exec_module(RULESET)

HEAD="a"*40
MERGE="b"*40
REPO="Vivaliz-site/site-shopvivaliz"

def fixture():
    pr={"merged":True,"state":"closed","number":123,
        "merged_by":{"login":"github-merge-queue[bot]"},"merged_at":"2026-11-02T20:10:00Z",
        "merge_commit_sha":MERGE,"head":{"sha":HEAD},
        "base":{"ref":"main","repo":{"full_name":REPO}},
        "auto_merge":{"enabled_by":{"login":"fredmourao-ai"},"merge_method":"squash"},
        "user":{"login":"fredmourao-ai"}}
    grant={"schema":MODULE.SCHEMA,"repository":REPO,"pr_number":123,
           "resource":"mcp:marina","principal":"marina-mcp-service",
           "target_repository":REPO,"capabilities":["file_write"],
           "expires_at_utc":"2026-12-01T00:00:00Z"}
    reviews=[{"user":{"login":"shopvivaliz-human"},"state":"APPROVED",
              "commit_id":HEAD,"submitted_at":"2026-11-02T20:00:00Z"}]
    proof={"repository":REPO,"number":123,"events":[{
          "enqueuer":{"login":"fredmourao-ai"},"createdAt":"2026-11-02T20:05:00Z",
          "pullRequest":{"number":123,"headRefOid":HEAD}}]}
    args=dict(repository=REPO,pr_number=123,owner_login="shopvivaliz-human",
              merge_sha=MERGE,head_sha=HEAD,resource="mcp:marina",
              principal="marina-mcp-service",capability="file_write",
              target_repository=REPO,reviews=reviews,queue_proof=proof,
              now=datetime(2026,11,2,tzinfo=timezone.utc))
    return pr,grant,args

class OwnerMergeQueueAuthorizationTests(unittest.TestCase):
    def test_valid_exact_owner_approval_and_github_queue_proof(self):
        pr,grant,args=fixture()
        self.assertEqual(MODULE.decide(pr,grant,**args),(True,"owner_authorization_verified"))
    def test_never_trust_bot_merge_without_verified_native_queue(self):
        pr,grant,args=fixture()
        for mutation in (
            {"queue_proof":None},
            {"queue_proof":{}},
            {"queue_proof":{"repository":REPO,"number":123,"events":[]}},
            {"queue_proof":{"repository":"attacker/repo","number":123,"events":args["queue_proof"]["events"]}},
        ):
            with self.subTest(mutation=mutation):
                self.assertFalse(MODULE.decide(pr,grant,**{**args,**mutation})[0])
    def test_stale_and_revoked_approval_never_authorize_queue(self):
        pr,grant,args=fixture()
        for reviews in (
            [],
            [{**args["reviews"][0],"commit_id":"c"*40}],
            [{**args["reviews"][0],"state":"DISMISSED"}],
            [{**args["reviews"][0],"user":{"login":"fredmourao-ai"}}],
        ):
            self.assertFalse(MODULE.decide(pr,grant,**{**args,"reviews":reviews})[0])
    def test_queue_before_owner_approval_or_after_merge_denied(self):
        pr,grant,args=fixture()
        for time in ("2026-11-02T19:59:00Z","2026-11-02T20:11:00Z"):
            proof={"repository":REPO,"number":123,"events":[{
              **args["queue_proof"]["events"][0],"createdAt":time}]}
            self.assertFalse(MODULE.decide(pr,grant,**{**args,"queue_proof":proof})[0])
    def test_wrong_queue_actor_commit_or_pr_denied(self):
        pr,grant,args=fixture()
        for modification in (
            {"enqueuer":{"login":"attacker"}},
            {"pullRequest":{"number":999,"headRefOid":HEAD}},
            {"pullRequest":{"number":123,"headRefOid":"c"*40}},
        ):
            event={**args["queue_proof"]["events"][0],**modification}
            proof={**args["queue_proof"],"events":[event]}
            self.assertFalse(MODULE.decide(pr,grant,**{**args,"queue_proof":proof})[0])
    def test_technical_direct_merge_is_never_equivalent_to_queue(self):
        pr,grant,args=fixture()
        pr["merged_by"]={"login":"fredmourao-ai"}
        self.assertFalse(MODULE.decide(pr,grant,**args)[0])
    def test_auto_queue_requires_human_review_exact_head_and_official_green_checks(self):
        pr={"state":"open","draft":False,"user":{"login":"fredmourao-ai"},
            "base":{"ref":"main"},"head":{"sha":HEAD,"repo":{"full_name":REPO}},
            "labels":[{"name":"no-auto-merge"}]}
        files=[{"filename":"security/owner-approvals/123.json"}]
        review=[{"user":{"login":"shopvivaliz-human"},"state":"APPROVED","commit_id":HEAD,
                 "submitted_at":"2026-11-02T20:00:00Z"}]
        checks=[{"name":n,"status":"completed","conclusion":"success",
                 "app":{"id":15368},"id":i+1} for i,n in enumerate(sorted(RUNNER.REQUIRED))]
        rules=[{"type":t,"parameters":{"required_status_checks":[{"context":n,"integration_id":15368}
                          for n in RUNNER.REQUIRED]}} if t=="required_status_checks" else {"type":t}
                          for t in ("pull_request","non_fast_forward","required_status_checks","merge_queue")]
        self.assertEqual(RUNNER.eligible(pr,files,review,checks,rules,specialized_check_completed=True)[0],True)
        self.assertFalse(RUNNER.eligible(pr,files,[],checks,rules,specialized_check_completed=True)[0])
        self.assertFalse(RUNNER.eligible(pr,files,review,checks,rules[:-1],specialized_check_completed=True)[0])
        self.assertFalse(RUNNER.eligible(pr,files,review,checks[:-1],rules,specialized_check_completed=True)[0])
        pr["head"]["sha"]="c"*40
        self.assertFalse(RUNNER.eligible(pr,files,review,checks,rules,specialized_check_completed=True)[0])
        pr["head"]["sha"]=HEAD
        pr["user"]["login"]="shopvivaliz-human"
        self.assertFalse(RUNNER.eligible(pr,files,review,checks,rules,specialized_check_completed=True)[0])
    def test_common_p1_pr_is_automatically_queued_without_human_review(self):
        pr={"state":"open","draft":False,"user":{"login":"fredmourao-ai"},
            "base":{"ref":"main"},"head":{"sha":HEAD,"repo":{"full_name":REPO}},
            "labels":[]}
        files=[{"filename":"docs/ops/release-notes.md"}]
        checks=[{"name":n,"status":"completed","conclusion":"success",
                 "app":{"id":15368},"id":i+1} for i,n in enumerate(sorted(RUNNER.REQUIRED))]
        rules=[{"type":t,"parameters":{"required_status_checks":[{"context":n,"integration_id":15368}
                          for n in RUNNER.REQUIRED]}} if t=="required_status_checks" else {"type":t}
                          for t in ("pull_request","non_fast_forward","required_status_checks","merge_queue")]
        self.assertEqual(RUNNER.eligible(pr,files,[],checks,rules,
                             specialized_check_completed=True),
                         (True,"technical_green_ready_for_native_queue"))
        self.assertFalse(RUNNER.eligible(pr,files,[],checks,rules)[0])
        pr["labels"]=[{"name":"no-auto-merge"}]
        self.assertFalse(RUNNER.eligible(pr,files,[],checks,rules,
                                        specialized_check_completed=True)[0])
        pr["labels"]=[]
        self.assertFalse(RUNNER.eligible(pr,files,[],checks[:-1],rules,
                                        specialized_check_completed=True)[0])

    def test_specialized_impact_checks_are_never_skipped(self):
        files=[{"filename":"scripts/pr_conflict_vm_heal.sh"}]
        latest={"workflow_runs":[{"name":"PR Policy Enforcement",
                "created_at":"2026-11-02T21:00:00Z","id":123,
                "status":"completed","conclusion":"success"}]}
        with patch.object(RUNNER,"gh_json",return_value=latest):
            self.assertTrue(RUNNER.specialized_workflows_green(files,HEAD))
        with patch.object(RUNNER,"gh_json",return_value={"workflow_runs":[]}):
            self.assertFalse(RUNNER.specialized_workflows_green(files,HEAD))
        latest["workflow_runs"][0]["conclusion"]="failure"
        with patch.object(RUNNER,"gh_json",return_value=latest):
            self.assertFalse(RUNNER.specialized_workflows_green(files,HEAD))
        with patch.object(RUNNER,"gh_json",return_value={"workflow_runs":[]}):
            self.assertTrue(RUNNER.specialized_workflows_green(
                [{"filename":"docs/ops/p1-note.md"}],HEAD))

    def test_ruleset_activation_preserves_required_checks_and_mandates_queue(self):
        before={"id":24523478,"name":"Audit V5 - required PR and verified checks",
                "enforcement":"active","target":"branch","source_type":"Repository",
                "conditions":{"ref_name":{"include":["~DEFAULT_BRANCH"],"exclude":[]}},
                "bypass_actors":[],
                "rules":[{"type":"deletion"},{"type":"non_fast_forward"},
                         {"type":"pull_request","parameters":{"dismiss_stale_reviews_on_push":True,
                           "required_approving_review_count":0,"required_review_thread_resolution":True}},
                         {"type":"required_status_checks","parameters":{
                           "strict_required_status_checks_policy":True,
                           "required_status_checks":[{"context":n,"integration_id":i}
                           for n,i in RULESET.REQUIRED.items()]}}]}
        next_rules=RULESET.mutation_plan(before)
        self.assertEqual(before["rules"][-1]["parameters"]["strict_required_status_checks_policy"],True)
        self.assertTrue(next_rules["rules"][-2]["parameters"]["strict_required_status_checks_policy"])
        self.assertEqual(next_rules["rules"][-1]["type"],"merge_queue")
        self.assertEqual(next_rules["rules"][-1]["parameters"]["grouping_strategy"],"ALLGREEN")
        self.assertEqual(next_rules["bypass_actors"],[])
        with self.assertRaises(ValueError):
            RULESET.mutation_plan({**before,"bypass_actors":[{"actor_id":1}]})
        with self.assertRaises(ValueError):
            RULESET.mutation_plan({**before,"rules":before["rules"][:-1]})

    def test_desktop_lock_restoration_requires_dual_approval_in_merge_queue(self):
        pr = {"state": "open", "draft": False, "user": {"login": "some-agent-or-bot"},
              "base": {"ref": "main"}, "head": {"sha": HEAD, "repo": {"full_name": REPO}},
              "labels": []}
        files = [{"filename": "remote-control-mcp/server.py",
                  "patch": "@@ -10,3 +10,4 @@\n MUTATING = {\n+    \"desktop_click\": True,\n }"}]
        checks = [{"name": n, "status": "completed", "conclusion": "success",
                   "app": {"id": 15368}, "id": i + 1} for i, n in enumerate(sorted(RUNNER.REQUIRED))]
        rules = [{"type": t, "parameters": {"required_status_checks": [{"context": n, "integration_id": 15368}
                  for n in RUNNER.REQUIRED]}} if t == "required_status_checks" else {"type": t}
                  for t in ("pull_request", "non_fast_forward", "required_status_checks", "merge_queue")]
        human_rev = {"user": {"login": "shopvivaliz-human"}, "state": "APPROVED",
                     "commit_id": HEAD, "submitted_at": "2026-11-08T18:00:00Z"}
        operator_rev = {"user": {"login": "fredmourao-ai"}, "state": "APPROVED",
                        "commit_id": HEAD, "submitted_at": "2026-11-08T18:05:00Z"}

        # No reviews -> denied
        self.assertEqual(
            RUNNER.eligible(pr, files, [], checks, rules, specialized_check_completed=True),
            (False, "desktop_lock_requires_dual_approval_on_exact_head"),
        )
        # Only human -> denied
        self.assertEqual(
            RUNNER.eligible(pr, files, [human_rev], checks, rules, specialized_check_completed=True),
            (False, "desktop_lock_requires_dual_approval_on_exact_head"),
        )
        # Only operator -> denied
        self.assertEqual(
            RUNNER.eligible(pr, files, [operator_rev], checks, rules, specialized_check_completed=True),
            (False, "desktop_lock_requires_dual_approval_on_exact_head"),
        )
        # One stale commit -> denied
        stale_human = {**human_rev, "commit_id": "c" * 40}
        self.assertEqual(
            RUNNER.eligible(pr, files, [stale_human, operator_rev], checks, rules, specialized_check_completed=True),
            (False, "desktop_lock_requires_dual_approval_on_exact_head"),
        )
        # Both approved on exact head -> allowed
        self.assertEqual(
            RUNNER.eligible(pr, files, [human_rev, operator_rev], checks, rules, specialized_check_completed=True),
            (True, "desktop_lock_dual_approved_exact_head_ready_for_native_queue"),
        )

if __name__=="__main__":
    unittest.main()
