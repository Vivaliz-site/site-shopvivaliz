from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIGURE = ROOT / "scripts" / "configure-production-runtime.py"
MATERIALIZER = ROOT / "scripts" / "materialize-runtime-secrets.php"
WORKFLOW = ROOT / ".github" / "workflows" / "configure-production-runtime.yml"


def test() -> None:
    configure = CONFIGURE.read_text(encoding="utf-8")
    materializer = MATERIALIZER.read_text(encoding="utf-8")
    workflow = WORKFLOW.read_text(encoding="utf-8")
    for key in (
        "MERCADOPAGO_ACCESS_TOKEN",
        "MERCADOPAGO_PUBLIC_KEY",
        "MERCADOPAGO_WEBHOOK_SECRET",
    ):
        assert key in configure, f"configure runtime missing {key}"
        assert key in materializer, f"materializer missing {key}"
        assert f"secrets.{key}" in workflow, f"workflow missing GitHub secret {key}"
        assert f"{key}_VALUE" in workflow, f"workflow missing payload value {key}"


if __name__ == "__main__":
    test()
    print("mercadopago runtime propagation contract: PASS")
