import importlib.util
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "autonomous-executor.py"
AI_MODULE_PATH = ROOT / "ai_collaboration.py"
RETIRED_MODULE_PATH = ROOT / "scripts" / "ai" / "retired_executor.py"


def load_retired_module():
    spec = importlib.util.spec_from_file_location("retired_executor", RETIRED_MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def load_ai_module():
    spec = importlib.util.spec_from_file_location("ai_collaboration", AI_MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def test_retired_executor_is_fail_closed(tmp_path, capsys):
    module = load_retired_module()
    module.REPORT_DIR = tmp_path

    result = module.run_blocked(
        "scripts/ai/autonomous_executor.py",
        "executor aposentado sem evidência revisada",
        ["adaptador explícito", "verificação de leitura"],
    )

    assert result == 2
    payload = json.loads(capsys.readouterr().err)
    assert payload["status"] == "blocked"
    assert payload["external_operation_performed"] is False
    assert payload["required_replacement"] == ["adaptador explícito", "verificação de leitura"]
    assert (tmp_path / "autonomous-executor.json").exists()


def test_autonomous_wrapper_points_to_canonical_fail_closed_entrypoint():
    wrapper = MODULE_PATH.read_text(encoding="utf-8")
    canonical = (ROOT / "scripts" / "ai" / "autonomous_executor.py").read_text(encoding="utf-8")
    retired = RETIRED_MODULE_PATH.read_text(encoding="utf-8")

    assert "runpy.run_path" in wrapper
    assert '"autonomous_executor.py"' in wrapper
    assert "run_blocked" in canonical
    assert "external_operation_performed" in retired


def test_retired_executor_never_claims_external_operation(tmp_path, capsys):
    module = load_retired_module()
    module.REPORT_DIR = tmp_path

    assert module.run_blocked("scripts/ai/autonomous_executor.py", "blocked", []) == 2
    payload = json.loads(capsys.readouterr().err)
    assert payload["external_operation_performed"] is False


def test_ai_collaboration_returns_blocked_when_all_providers_fail(monkeypatch):
    module = load_ai_module()

    class RaisingClient:
        def __init__(self, *args, **kwargs):
            raise RuntimeError("quota exceeded")

    monkeypatch.setenv("GEMINI_API_KEY", "test-gemini")
    monkeypatch.setenv("OPENAI_API_KEY", "test-openai")
    monkeypatch.setenv("ANTHROPIC_API_KEY", "test-anthropic")
    monkeypatch.setattr(module, "genai", type("FakeGenai", (), {"Client": RaisingClient}))
    monkeypatch.setattr(module, "OpenAI", RaisingClient)
    monkeypatch.setattr(module, "Anthropic", RaisingClient)

    result = module.iniciar_super_agente_trio(modo="diagnostico", tarefa="teste")

    assert result == 2
