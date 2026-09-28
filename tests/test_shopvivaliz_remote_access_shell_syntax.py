#!/usr/bin/env python3
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"


def extract_site_run_script() -> str:
    lines = WORKFLOW.read_text(encoding="utf-8").splitlines()
    step_index = lines.index("      - name: Execute on site production host")
    run_index = next(
        i for i in range(step_index + 1, len(lines))
        if lines[i] == "        run: |"
    )
    body = []
    for line in lines[run_index + 1:]:
        if line.startswith("      - name: "):
            break
        if line.startswith("          "):
            body.append(line[10:])
        elif line == "":
            body.append("")
        else:
            raise AssertionError(f"unexpected workflow indentation: {line!r}")
    return "\n".join(body) + "\n"


class ShopVivalizRemoteAccessShellSyntaxTest(unittest.TestCase):
    def test_site_production_run_block_is_valid_bash(self) -> None:
        result = subprocess.run(
            ["bash", "-n"],
            input=extract_site_run_script(),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
