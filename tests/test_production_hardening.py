from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_storage_denies_direct_http_access() -> None:
    rules = (ROOT / "storage" / ".htaccess").read_text(encoding="utf-8")
    assert rules.strip() == "Require all denied"


def test_apache_policy_blocks_repository_and_runtime_paths() -> None:
    policy = (ROOT / "deploy" / "apache" / "shopvivaliz-private-paths.conf").read_text(
        encoding="utf-8"
    )
    for token in (
        "\\.git",
        "storage",
        "\\.env",
        "tasks-queue",
        "scripts",
        "tests",
        "gen-token",
        "test-normalize",
        "sync-cache-endpoint",
        "test-results",
        "olist",
        "migrations",
        "release-notes",
    ):
        assert token in policy
    assert "Require all denied" in policy
    assert "Options -Indexes" in policy
    assert "Content-Security-Policy" in policy


def test_root_htaccess_blocks_env_variants() -> None:
    rules = (ROOT / ".htaccess").read_text(encoding="utf-8")
    assert "\\.env(?:\\..*)?" in rules
    assert "Options -Indexes" in rules
    assert "Content-Security-Policy" in rules


def test_root_htaccess_blocks_legacy_web_diagnostics() -> None:
    rules = (ROOT / ".htaccess").read_text(encoding="utf-8")
    for token in (
        "gen-token",
        "setup-webhooks",
        "test-normalize",
        "sync-cache-endpoint",
        "test-results",
        "olist",
    ):
        assert token in rules
    assert "(?:[-_.][^/]*)?" in rules
    assert "(?:debug|test|teste|check|gen-token)[^/]*" not in rules


def test_pretty_checkout_routes_do_not_redirect_after_internal_rewrite() -> None:
    lines = (ROOT / ".htaccess").read_text(encoding="utf-8").splitlines()
    for route in ("carrinho", "checkout"):
        rule = f"    RewriteRule ^{route}\\.php$ /{route} [R=301,L]"
        rule_index = lines.index(rule)
        condition = lines[rule_index - 1]
        assert condition.startswith("    RewriteCond %{THE_REQUEST} ")
        assert f"{route}\\.php" in condition


def test_catalog_response_does_not_publish_runtime_debug_state() -> None:
    endpoint = (ROOT / "api" / "catalog" / "products.php").read_text(encoding="utf-8")
    assert "'debug'" not in endpoint
    assert 'error_log("[products.php]' not in endpoint
