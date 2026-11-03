"""Regression guard for directly pinned Python dependencies with active advisories.

This check is deliberately scoped to requirements.txt. It does not claim to
audit every runtime package or replace Dependabot, pip audit, or E2E checks.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
REQUIREMENTS = ROOT / "requirements.txt"
PATCH_FLOORS = {"pyjwt": (2, 15, 0), "aiohttp": (3, 14, 4)}
PIN = re.compile(r"^([A-Za-z][A-Za-z0-9_.-]*)==([0-9]+(?:\.[0-9]+){1,3})$")


def pinned_versions(raw: str) -> dict[str, tuple[int, ...]]:
    result: dict[str, tuple[int, ...]] = {}
    for line in raw.splitlines():
        item = line.strip()
        if not item or item.startswith("#"):
            continue
        found = PIN.fullmatch(item)
        if found is None:
            continue
        key = found.group(1).casefold().replace("_", "-")
        if key in result:
            raise ValueError("duplicate_requirement_name")
        result[key] = tuple(int(x) for x in found.group(2).split("."))
    return result


def verify_security_floors(raw: str) -> None:
    actual = pinned_versions(raw)
    for package, floor in PATCH_FLOORS.items():
        if package not in actual or actual[package] < floor:
            raise ValueError(f"security_floor_not_met_{package}")


class SecurityDependencyMinimumsTests(unittest.TestCase):
    def test_pins_are_above_known_fixes(self):
        verify_security_floors(REQUIREMENTS.read_text(encoding="utf-8"))

    def test_both_old_known_vulnerable_pins_are_rejected(self):
        raw = REQUIREMENTS.read_text(encoding="utf-8")
        for old in ("PyJWT==2.13.0", "aiohttp==3.14.1"):
            with self.subTest(old=old):
                if old.startswith("PyJWT"):
                    bad = raw.replace("PyJWT==2.15.0", old)
                else:
                    bad = raw.replace("aiohttp==3.14.4", old)
                self.assertNotEqual(raw, bad)
                with self.assertRaisesRegex(ValueError, "security_floor_not_met"):
                    verify_security_floors(bad)

    def test_missing_pin_does_not_pass_silently(self):
        raw = REQUIREMENTS.read_text(encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "security_floor_not_met_pyjwt"):
            verify_security_floors(raw.replace("PyJWT==2.15.0\n", ""))

    def test_duplicate_normalized_package_rejected(self):
        raw = REQUIREMENTS.read_text(encoding="utf-8") + "\npyjwt==2.15.0\n"
        with self.assertRaisesRegex(ValueError, "duplicate_requirement_name"):
            verify_security_floors(raw)


if __name__ == "__main__":
    unittest.main()
