import os
import sys
import argparse

from google import genai
from openai import OpenAI
from anthropic import Anthropic

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


def iniciar_super_agente_trio(modo: str = "diagnostico", tarefa: str = ""):
    config_modo = MODOS.get(modo, MODOS["diagnostico"])
    print(f"Inicializando Trio IA — Modo: {config_modo['descricao']}")

    try:
        gemini_client = genai.Client()
        openai_client = OpenAI()
        anthropic_client = Anthropic()
    except Exception as e:
        print(f"Erro ao inicializar clientes de API: {e}")
        sys.exit(1)

    contexto = carregar_contexto(modo)

    # --- FASE 1: GEMINI ---
    print("[1/3] Gemini analisando arquitetura e infraestrutura...")
    prompt_gemini = config_modo["gemini_prompt"].format(contexto=contexto, tarefa=tarefa)
    res_gemini = gemini_client.models.generate_content(
        model="gemini-2.5-flash",
        contents=prompt_gemini,
    )
    analise_gemini = res_gemini.text
    print("  Gemini concluido.")

    # --- FASE 2: CLAUDE ---
    print("[2/3] Claude auditando codigo e logica de negocio...")
    prompt_claude = config_modo["claude_prompt"].format(
        gemini=analise_gemini, contexto=contexto, tarefa=tarefa
    )
    res_claude = anthropic_client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=2000,
        messages=[{"role": "user", "content": prompt_claude}],
    )
    analise_claude = res_claude.content[0].text
    print("  Claude concluido.")

    # --- FASE 3: CHATGPT ---
    print("[3/3] ChatGPT consolidando relatorio final...")
    prompt_openai = config_modo["openai_prompt"].format(
        gemini=analise_gemini, claude=analise_claude, tarefa=tarefa
    )
    res_openai = openai_client.responses.create(
        model=OPENAI_MODEL,
        reasoning={"effort": OPENAI_REASONING_EFFORT},
        input=prompt_openai,
    )
    relatorio_final = res_openai.output_text
    print("  ChatGPT concluido.")

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
    iniciar_super_agente_trio(modo=args.modo, tarefa=args.tarefa)
