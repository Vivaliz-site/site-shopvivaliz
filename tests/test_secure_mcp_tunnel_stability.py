#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class SecureMcpTunnelStabilityTests(unittest.TestCase):
    def test_recovery_workflow_targets_only_backend_runner(self):
        text = (ROOT / ".github" / "workflows" / "secure-mcp-runtime-recovery.yml").read_text(encoding="utf-8")
        self.assertIn("workflow_call:", text)
        self.assertNotIn("issue_comment:", text)
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", text)
        self.assertIn("action=diagnose-and-repair", text)
        router = (ROOT / "scripts" / "issue-comment-router.py").read_text(encoding="utf-8")
        dispatcher = (ROOT / ".github" / "workflows" / "issue-comment-dispatcher.yml").read_text(encoding="utf-8")
        self.assertIn('"/secure-mcp-runtime-recover": "secure_mcp_runtime_recovery"', router)
        self.assertIn("needs.classify.outputs.route == 'secure_mcp_runtime_recovery'", dispatcher)
        self.assertIn("uses: ./.github/workflows/secure-mcp-runtime-recovery.yml", dispatcher)

    def test_recovery_requires_live_runtime_and_lifecycle_evidence(self):
        text = (ROOT / ".github" / "workflows" / "secure-mcp-runtime-recovery.yml").read_text(encoding="utf-8")
        for marker in (
            "SECURE_MCP_RUNTIME_CLIENT=",
            "SECURE_MCP_RUNTIME_STATUS=",
            "SECURE_MCP_RUNTIME_HEALTHY=",
            "SECURE_MCP_RUNTIME_READY=",
            "SECURE_MCP_RUNTIME_STALE=",
            "SECURE_MCP_RUNTIME_DOCTOR=",
            "SECURE_MCP_RUNTIME_INITIALIZATION_ERRORS=",
            "SECURE_MCP_RUNTIME_REPAIR=",
            "SECURE_MCP_RUNTIME_FINAL=PASS",
        ):
            self.assertIn(marker, text)
        self.assertIn("MCP_STDIO_SEND_INITIALIZED_NOTIFICATION=true", text)
        self.assertIn("MCP_CONNECTION_MAX_TTL=30m", text)
        self.assertIn("Restart=always", text)
        self.assertNotIn("set +e", text)

    def test_recovery_never_logs_tunnel_credentials(self):
        text = (ROOT / ".github" / "workflows" / "secure-mcp-runtime-recovery.yml").read_text(encoding="utf-8")
        forbidden = (
            "cat /var/lib/shopvivaliz-remote-control/mcp-token",
            "printenv CONTROL_PLANE_API_KEY",
            "echo $CONTROL_PLANE_API_KEY",
            "echo $OPENAI_ADMIN_KEY",
        )
        for needle in forbidden:
            self.assertNotIn(needle, text)


if __name__ == "__main__":
    unittest.main()
