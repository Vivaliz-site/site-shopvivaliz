import os
import sys
import argparse
from pathlib import Path

try:
    from google import genai
except Exception:  # pragma: no cover - runtime fallback when SDK is unavailable
    genai = None

try:
    from openai import OpenAI
except Exception:  # pragma: no cover - runtime fallback when SDK is unavailable
    OpenAI = None

try:
    from anthropic import Anthropic
except Exception:  # pragma: no cover - runtime fallback when SDK is unavailable
    Anthropic = None

OPENAI_MODEL = os.getenv("OPENAI_MODEL", "gpt-5-nano")
OPENAI_REASONING_EFFORT = os.getenv("OPENAI_REASONING_EFFORT", "minimal")

ARQUIVOS_CONTEXTO_ECOMMERCE = [
    ".github/workflows/deploy.yml",
    ".codex/config.toml",
    "AGENTS.md",
    "config/shopvivaliz-version.php",
    "admin/squad-chat.php",
    "api/agent/squad-chat.php",
]

def load_env_file(path: str | os.PathLike[str] | None = None) -> dict[str, str]:
    env_path = Path(path or ".env.local")
    values: dict[str, str] = {}
    if not env_path.exists():
        return values

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key:
            values[key] = value
    for key, value in values.items():
        if value and key not in os.environ:
            os.environ[key] = value
    return values


load_env_file()

MODOS = {
    "diagnostico": {
        "descricao": "Diagnóstico de falha no deploy",
        "gemini_prompt": (
            "Analise o fluxo de deploy e testes de rede que falharam na ShopVivaliz (ecommerce PHP na HostGator):\n\n{contexto}\n\n"
            "Aponte erros típicos de infraestrutura web na HostGator: permissões FTP, bloqueios de SSL, caminhos de URL incorretos."
        ),
        "claude_prompt": (
            "Com base na análise de infraestrutura do Gemini:\n\n{gemini}\n\n"
            "Faça uma auditoria de código PHP focada nas APIs do Melhor Envio, Olist e Shopee. "
            "O que pode quebrar respostas JSON ou travar requisições curl em PHP?"
        ),
        "openai_prompt": (
            "Você recebeu duas auditorias de IA sobre um erro de deploy do ecommerce ShopVivaliz.\n\n"
            "Análise de Infra (Gemini):\n{gemini}\n\nAnálise de Código (Claude):\n{claude}\n\n"
            "Consolide em um relatório Markdown executivo com:\n"
            "1. Causa raiz do erro\n2. Ações imediatas (FTP ou código)\n3. Melhorias de segurança e estabilidade"
        ),
    },
    "ecommerce": {
        "descricao": "Revisão e melhoria de features do ecommerce",
        "gemini_prompt": (
            "Você é o Arquiteto de Sistemas do ecommerce ShopVivaliz (PHP 8.3, MySQL, HostGator FTP).\n\n"
            "Contexto do projeto:\n{contexto}\n\n"
            "Tarefa recebida: {tarefa}\n\n"
            "Analise a viabilidade técnica, riscos de infraestrutura e proponha a arquitetura da solução."
        ),
        "claude_prompt": (
            "Você é o Desenvolvedor Principal PHP do ecommerce ShopVivaliz.\n\n"
            "Análise de arquitetura do Gemini:\n{gemini}\n\n"
            "Tarefa: {tarefa}\n\n"
            "Implemente a solução em PHP com foco em segurança, integração com Olist/Shopee e boas práticas. "
            "Gere o código completo pronto para deploy."
        ),
        "openai_prompt": (
            "Você é o QA e Product Manager do ecommerce ShopVivaliz.\n\n"
            "Arquitetura (Gemini):\n{gemini}\n\nImplementação (Claude):\n{claude}\n\n"
            "Tarefa original: {tarefa}\n\n"
            "Revise a solução e entregue um relatório final em Markdown com:\n"
            "1. Validação dos requisitos de negócio\n2. Pontos de risco ou bugs encontrados\n"
            "3. Checklist de testes antes do deploy\n4. Resumo executivo da feature"
        ),
    },
}


def carregar_contexto(modo: str) -> str:
    contexto = f"# Projeto: ShopVivaliz Ecommerce — Modo: {modo}\n\n"
    for arquivo in ARQUIVOS_CONTEXTO_ECOMMERCE:
        if os.path.exists(arquivo):
            try:
                with open(arquivo, "r", encoding="utf-8") as f:
                    conteudo = f.read(3000)
                contexto += f"\n--- {arquivo} ---\n{conteudo}\n"
            except Exception:
                pass
    return contexto


def iniciar_super_agente_trio(modo: str = "diagnostico", tarefa: str = "") -> int:
    config_modo = MODOS.get(modo, MODOS["diagnostico"])
    print(f"Inicializando Trio IA — Modo: {config_modo['descricao']}")

    gemini_client = None
    openai_client = None
    anthropic_client = None

    try:
        if genai is not None and os.getenv("GEMINI_API_KEY"):
            gemini_client = genai.Client(api_key=os.getenv("GEMINI_API_KEY"))
        else:
            gemini_client = None
    except Exception as e:
        print(f"  [AVISO] Falha ao inicializar Gemini: {e}")

    try:
        if OpenAI is not None and os.getenv("OPENAI_API_KEY"):
            openai_client = OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
        else:
            openai_client = None
    except Exception as e:
        print(f"  [AVISO] Falha ao inicializar OpenAI: {e}")

    try:
        if Anthropic is not None and os.getenv("ANTHROPIC_API_KEY"):
            anthropic_client = Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY"))
        else:
            anthropic_client = None
    except Exception as e:
        print(f"  [AVISO] Falha ao inicializar Claude: {e}")

    if not any([gemini_client, openai_client, anthropic_client]):
        print("Nenhum cliente de IA disponivel. Abortando diagnostico.")
        return 2

    contexto = carregar_contexto(modo)
    teve_resultado_valido = False

    # --- FASE 1: GEMINI ---
    print("[1/3] Gemini analisando arquitetura e infraestrutura...")
    analise_gemini = "[Gemini indisponivel — API key ausente ou invalida]"
    if gemini_client:
        try:
            prompt_gemini = config_modo["gemini_prompt"].format(contexto=contexto, tarefa=tarefa)
            res_gemini = gemini_client.models.generate_content(
                model="gemini-2.5-flash",
                contents=prompt_gemini,
            )
            analise_gemini = res_gemini.text
            teve_resultado_valido = True
            print("  Gemini concluido.")
        except Exception as e:
            print(f"  [AVISO] Gemini falhou: {e}")
    else:
        print("  Gemini ignorado (cliente nao inicializado).")

    # --- FASE 2: CLAUDE ---
    print("[2/3] Claude auditando codigo e logica de negocio...")
    analise_claude = "[Claude indisponivel — API key ausente ou invalida]"
    if anthropic_client:
        try:
            prompt_claude = config_modo["claude_prompt"].format(
                gemini=analise_gemini, contexto=contexto, tarefa=tarefa
            )
            res_claude = anthropic_client.messages.create(
                model="claude-3-haiku-20240307",
                max_tokens=2000,
                messages=[{"role": "user", "content": prompt_claude}],
            )
            analise_claude = res_claude.content[0].text
            teve_resultado_valido = True
            print("  Claude concluido.")
        except Exception as e:
            print(f"  [AVISO] Claude falhou: {e}")
    else:
        print("  Claude ignorado (cliente nao inicializado).")

    # --- FASE 3: CHATGPT ---
    print("[3/3] ChatGPT consolidando relatorio final...")
    relatorio_final = f"## Relatorio parcial\n\n**Gemini:** {analise_gemini}\n\n**Claude:** {analise_claude}"
    if openai_client:
        try:
            prompt_openai = config_modo["openai_prompt"].format(
                gemini=analise_gemini, claude=analise_claude, tarefa=tarefa
            )
            res_openai = openai_client.responses.create(
                model=OPENAI_MODEL,
                reasoning={"effort": OPENAI_REASONING_EFFORT},
                input=prompt_openai,
            )
            relatorio_final = res_openai.output_text
            teve_resultado_valido = True
            print("  ChatGPT concluido.")
        except Exception as e:
            print(f"  [AVISO] ChatGPT falhou: {e}")
    else:
        print("  ChatGPT ignorado (cliente nao inicializado).")

    if not teve_resultado_valido:
        print("Nenhum provedor de IA respondeu com sucesso. Encerrando como bloqueio externo.")
        return 2

    print("\n========== RELATORIO DO TRIO IA - SHOPVIVALIZ ==========\n")
    print(relatorio_final)
    print("\n==========================================================\n")

    nome_arquivo = f"ai_collaboration_report_{modo}.md"
    with open(nome_arquivo, "w", encoding="utf-8") as f:
        f.write(f"# Relatorio Trio IA — Modo: {config_modo['descricao']}\n\n")
        if tarefa:
            f.write(f"**Tarefa:** {tarefa}\n\n---\n\n")
        f.write(relatorio_final)

    print(f"Relatorio salvo em: {nome_arquivo}")
    return 0


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Trio IA ShopVivaliz")
    parser.add_argument(
        "--modo",
        choices=list(MODOS.keys()),
        default="diagnostico",
        help="Modo de operacao do trio IA",
    )
    parser.add_argument(
        "--tarefa",
        default="",
        help="Descricao da tarefa de desenvolvimento (usado no modo ecommerce)",
    )
    args = parser.parse_args()
    raise SystemExit(iniciar_super_agente_trio(modo=args.modo, tarefa=args.tarefa))
