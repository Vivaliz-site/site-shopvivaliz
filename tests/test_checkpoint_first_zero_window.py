"""CHECKPOINT_FIRST_V9: reproduce the zero-window interruption directly.

An interruption of the calling ChatGPT/Claude session cannot be trapped by
code in this repository -- the very first tool call of a turn happens
outside any process this repo controls. What this repo *can* guarantee is
that once `agent_task_state.py start` is invoked, durability is immediate
(no async gap where a crash right after the call would lose the checkpoint)
and that a crash *during* the write never leaves corrupt state. This test
proves those two properties directly, and separately proves that the
CHECKPOINT_FIRST_V9 marker is a documentation contract checked by string
presence only -- not a technical gate that can force tool-call ordering by
an external agent. Nobody should re-claim "enforced" without re-deriving
this from evidence.
"""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_task_state as state  # noqa: E402


def load_validator():
    path = ROOT / "scripts" / "validate-task-continuity-enforcement.py"
    spec = importlib.util.spec_from_file_location("validate_task_continuity_enforcement_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load validator")
    module = importlib.util.module_from_spec(spec)
    return module, spec


class ZeroWindowDurabilityTests(unittest.TestCase):
    """The checkpoint write path itself has no async durability gap."""

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.temp.name)

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def test_checkpoint_is_durable_on_disk_the_instant_start_returns(self) -> None:
        """Simulates the worst case: the calling agent is killed on the
        instruction immediately after `start` returns, before any other
        material step. If durability were asynchronous or buffered, a
        fresh read from a brand-new file handle right after return could
        still see nothing. It must not.
        """
        state.start_task("task-zero-window", "reproduce zero window", "claude")

        raw_path = state.RUNTIME_DIR / "task-zero-window.json"
        # Bypass the module's own loader: open a fresh, independent file
        # handle exactly like an unrelated process (e.g. the watchdog)
        # would, right after the call returns.
        with open(raw_path, "r", encoding="utf-8") as handle:
            payload = json.load(handle)

        self.assertEqual(payload["status"], "RUNNING")
        self.assertEqual(payload["task_id"], "task-zero-window")
        self.assertTrue(payload["next_action"])

    def test_interruption_mid_write_never_leaves_corrupt_or_partial_state(self) -> None:
        """Simulates a crash between the temp file being written and the
        atomic rename landing. `os.replace` is patched to fail after the
        temp file is fully flushed+fsynced but before the rename -- the
        exact instant a real process kill could land. The real path must
        never end up holding a partial/corrupt JSON document afterward.
        """
        real_path = state.RUNTIME_DIR / "task-crash.json"
        self.assertFalse(real_path.exists())

        with mock.patch("agent_task_state.os.replace", side_effect=OSError("simulated crash mid-write")):
            with self.assertRaises(OSError):
                state.start_task("task-crash", "reproduce mid-write crash", "claude")

        # The atomic path guarantees: either the destination was never
        # touched (crash happened strictly before rename, which is what
        # we simulated), or it holds a fully-formed prior document. It
        # must never hold a half-written/corrupt file.
        self.assertFalse(real_path.exists(), "a failed rename must not leave a partial file at the real path")

        tmp_leftovers = list(state.RUNTIME_DIR.glob(".task-crash.json.*.tmp"))
        # The temp file is written+fsynced fully before the rename attempt,
        # so it is complete and parseable even though cleanup could not run.
        for tmp in tmp_leftovers:
            payload = json.loads(tmp.read_text(encoding="utf-8"))
            self.assertEqual(payload["status"], "RUNNING")

    def test_start_task_performs_no_network_or_slow_io_before_the_write(self) -> None:
        """The pre-write path must be pure local computation. Any network
        call, subprocess, or blocking I/O between task invocation and the
        durable write would widen the zero window beyond what a single
        atomic write can close.
        """
        import socket
        import subprocess

        with mock.patch.object(socket, "socket", side_effect=AssertionError("no network before checkpoint write")):
            with mock.patch.object(subprocess, "Popen", side_effect=AssertionError("no subprocess before checkpoint write")):
                result = state.start_task("task-no-network", "prove pure local write", "claude")

        self.assertEqual(result["status"], "RUNNING")


class CheckpointFirstMarkerIsDocumentationOnlyTests(unittest.TestCase):
    """CHECKPOINT_FIRST_V9 as currently implemented is a normative
    contract validated by marker-string presence, not a technical gate
    that can force an external LLM session's first tool call. This is
    an architectural limit (the repo has no hook into a ChatGPT/Claude
    turn before its first tool call), not a bug to silently "fix" by
    claiming enforcement that cannot exist. This test pins that fact so
    future changes must consciously touch it, not accidentally regress
    a false "enforced" claim back into the docs.
    """

    def test_validator_checks_marker_presence_not_call_ordering(self) -> None:
        validator_path = ROOT / "scripts" / "validate-task-continuity-enforcement.py"
        text = validator_path.read_text(encoding="utf-8")

        self.assertIn("CHECKPOINT_FIRST_V9", text)
        # The validator's enforcement primitive for this marker is string
        # containment inside AGENTS.md, nothing that inspects an actual
        # tool-call sequence, timestamp ordering, or session transcript.
        self.assertIn('for token in (GLOBAL_MARKER, CHECKPOINT_FIRST_MARKER', text)
        self.assertNotIn("tool_call_order", text)
        self.assertNotIn("transcript", text)

    def test_agents_md_documents_the_zero_window_intent(self) -> None:
        agents_text = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
        self.assertIn("CHECKPOINT_FIRST_V9", agents_text)
        self.assertIn("agent_task_state.py start", agents_text)

    def test_write_path_is_atomic_and_fsynced(self) -> None:
        """The one property CHECKPOINT_FIRST_V9 *can* honestly rely on:
        once the call is made, durability is immediate and crash-safe.
        """
        source = (ROOT / "scripts" / "agent_task_state.py").read_text(encoding="utf-8")
        self.assertIn("os.fsync(handle.fileno())", source)
        self.assertIn("os.replace(tmp, path)", source)


if __name__ == "__main__":
    unittest.main()
