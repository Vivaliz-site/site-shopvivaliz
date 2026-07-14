from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_storage_denies_direct_http_access() -> None:
    rules = (ROOT / "storage" / ".htaccess").read_text(encoding="utf-8")
    assert rules.strip() == "Require all denied"


def test_apache_policy_blocks_repository_and_runtime_paths() -> None:
    policy = (ROOT / "deploy" / "apache" / "shopvivaliz-private-paths.conf").read_text(
        encoding="utf-8"
    )
    for token in ("\\.git", "storage", "\\.env", "tasks-queue", "scripts", "tests"):
        assert token in policy
    assert "Require all denied" in policy


def test_root_htaccess_blocks_env_variants() -> None:
    rules = (ROOT / ".htaccess").read_text(encoding="utf-8")
    assert "\\.env(?:\\..*)?" in rules
