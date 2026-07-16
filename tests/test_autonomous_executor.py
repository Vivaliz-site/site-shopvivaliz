import importlib.util
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "autonomous-executor.py"
AI_MODULE_PATH = ROOT / "ai_collaboration.py"


def load_module():
    spec = importlib.util.spec_from_file_location("autonomous_executor", MODULE_PATH)
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


def test_ai_result_without_clients_is_reported_as_blocked_external_access():
    module = load_module()

    status, reason = module.classify_ai_result(
        2,
        "Nenhum cliente de IA disponivel. Abortando diagnostico.",
    )

    assert status == "blocked_external_access_required"
    assert "cliente de ia" in reason.lower()


def test_select_roo_helper_matches_primary_agent_domain():
    module = load_module()

    helper = module.select_roo_helper(
        {"title": "QA / Self-test", "description": "Validar fluxo de checkout e logs"}
    )

    assert helper["id"] == "qa-self-test"
    assert "QA" in helper["name"]


def test_roo_fallback_report_contains_safe_next_steps_for_each_helper():
    module = load_module()

    report = module.render_roo_fallback_report(
        {"title": "Olist / Tiny", "description": "Sincronizar estoque e imagens"},
        module.select_roo_helper({"title": "Olist / Tiny", "description": "Sincronizar estoque e imagens"}),
    )

    assert "Roo Auxiliar" in report
    assert "próximos passos seguros" in report.lower()
    assert "olist" in report.lower()


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
