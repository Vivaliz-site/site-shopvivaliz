from __future__ import annotations

import json
import os
from dataclasses import dataclass
from typing import Any

import httpx
from agents import Agent, Runner, function_tool


@dataclass(frozen=True)
class CheckResult:
    name: str
    ok: bool
    status_code: int | None
    detail: str


@function_tool
async def check_url(url: str, expected_text: str = "", timeout_seconds: int = 20) -> str:
    """Executa uma verificação HTTP segura e retorna evidência estruturada."""
    try:
        async with httpx.AsyncClient(follow_redirects=True, timeout=timeout_seconds) as client:
            response = await client.get(url, headers={"User-Agent": "ShopVivaliz-Agent/1.0"})
        body = response.text[:5000]
        text_ok = expected_text == "" or expected_text.lower() in body.lower()
        result = CheckResult(
            name=url,
            ok=response.status_code < 400 and text_ok,
            status_code=response.status_code,
            detail=(
                "ok"
                if response.status_code < 400 and text_ok
                else f"http={response.status_code}; expected_text_found={text_ok}"
            ),
        )
    except Exception as exc:  # noqa: BLE001
        result = CheckResult(name=url, ok=False, status_code=None, detail=type(exc).__name__)
    return json.dumps(result.__dict__, ensure_ascii=False)


@function_tool
async def storefront_smoke_test(base_url: str = "https://shopvivaliz.com.br") -> str:
    """Valida os principais caminhos públicos sem realizar compra ou alteração de dados."""
    checks = [
        (f"{base_url}/", "Vivaliz"),
        (f"{base_url}/css/shopvivaliz-core-consolidated.css", ":root"),
        (f"{base_url}/catalogo", "produto"),
        (f"{base_url}/carrinho", "carrinho"),
        (f"{base_url}/api/olist/webhook-receiver.php?health=1", '"ok":true'),
    ]
    output: list[dict[str, Any]] = []
    for url, expected in checks:
        raw = await check_url.on_invoke_tool(None, json.dumps({"url": url, "expected_text": expected}))
        output.append(json.loads(raw))
    return json.dumps(
        {
            "ok": all(item["ok"] for item in output),
            "checks": output,
        },
        ensure_ascii=False,
    )


DIAGNOSTICS_INSTRUCTIONS = """
Você é o agente de diagnóstico do ShopVivaliz.
Primeiro identifique a falha com evidência objetiva. Use os health checks e URLs públicas.
Nunca afirme que algo funciona apenas porque o código existe no repositório.
Classifique cada conclusão como COMPROVADO, FALHOU ou INCONCLUSIVO.
Não altere dados, não faça compras e não exponha segredos.
""".strip()

QA_INSTRUCTIONS = """
Você é o agente de QA do ShopVivaliz.
Valide homepage, CSS, catálogo, produto, carrinho, checkout disponível e integrações públicas.
Bloqueie conclusão quando faltar evidência. Produza uma lista curta de testes e resultados.
Não invente métricas, avaliações ou sucesso de deploy.
""".strip()

ORCHESTRATOR_INSTRUCTIONS = """
Você é o orquestrador operacional do ShopVivaliz.
Use /docs/knowledge como base conceitual e siga a política de evidências do projeto.
Delegue diagnóstico ao agente de diagnóstico e validação ao agente de QA.
Só declare conclusão quando todos os checks críticos estiverem comprovados.
Quando houver bloqueio externo, informe exatamente qual ação manual é necessária.
Nunca revele tokens, senhas, chaves ou valores de secrets.
""".strip()


diagnostics_agent = Agent(
    name="ShopVivaliz Diagnóstico",
    instructions=DIAGNOSTICS_INSTRUCTIONS,
    tools=[check_url, storefront_smoke_test],
)

qa_agent = Agent(
    name="ShopVivaliz QA",
    instructions=QA_INSTRUCTIONS,
    tools=[check_url, storefront_smoke_test],
)

orchestrator_agent = Agent(
    name="ShopVivaliz Orchestrator",
    instructions=ORCHESTRATOR_INSTRUCTIONS,
    tools=[check_url, storefront_smoke_test],
    handoffs=[diagnostics_agent, qa_agent],
)


async def run_agent(message: str) -> str:
    if not os.getenv("OPENAI_API_KEY"):
        raise RuntimeError("OPENAI_API_KEY não configurada")
    result = await Runner.run(orchestrator_agent, message)
    return str(result.final_output)
