import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "autonomous-executor.py"


def load_module():
    spec = importlib.util.spec_from_file_location("autonomous_executor", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def test_ai_result_without_clients_is_reported_as_blocked_external_access():
    module = load_module()

    status, reason = module.classify_ai_result(
        2,
        "Nenhum cliente de IA disponivel. Abortando diagnostico.",
    )

    assert status == "blocked_external_access_required"
    assert "cliente de ia" in reason.lower()
