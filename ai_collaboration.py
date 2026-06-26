import os
import sys
from google import genai
from openai import OpenAI
from anthropic import Anthropic

def iniciar_super_agente_trio():
    print("🚀 Inicializando colaboração de Elite: Gemini + Claude + ChatGPT...")
    
    # 1. Conecta com os três clientes usando as chaves seguras do GitHub
    try:
        gemini_client = genai.Client()
        openai_client = OpenAI()
        anthropic_client = Anthropic()
    except Exception as e:
        print(f"❌ Erro ao inicializar as credenciais das chaves de API: {e}")
        sys.exit(1)

    # 2. Captura o contexto de arquivos cruciais para as IAs entenderem seu sistema
    contexto_projeto = ""
    arquivos_alvo = [".github/workflows/deploy.yml", "ADMIN_MANUAL.md"]
    for arquivo in arquivos_alvo:
        if os.path.exists(arquivo):
            with open(arquivo, "r", encoding="utf-8") as f:
                contexto_projeto += f"\n--- Arquivo {arquivo} ---\n" + f.read()

    # --- FASE 1: GEMINI (Análise de Infraestrutura e Redes) ---
    print("🧠 [1/3] Gemini processando arquitetura de deploy na HostGator...")
    prompt_gemini = (
        f"Analise o fluxo de deploy e os testes de rede que falharam na Shop Vivaliz:\n\n{contexto_projeto}\n\n"
        f"Aponte erros típicos de infraestrutura Web na HostGator (permissões via FTP, bloqueios de SSL ou caminhos de URL)."
    )
    res_gemini = gemini_client.models.generate_content(
        model='gemini-2.5-flash',
        contents=prompt_gemini,
    )
    analise_gemini = res_gemini.text

    # --- FASE 2: CLAUDE 3.5 SONNET (Análise Lógica e Auditoria PHP de Precisão) ---
    print("🦔 [2/3] Claude 3.5 Sonnet inspecionando falhas lógicas e integridade das APIs...")
    prompt_claude = (
        f"Com base na análise de infraestrutura do Gemini:\n\n{analise_gemini}\n\n"
        f"E olhando para as requisições das nossas APIs do Melhor Envio e Olist, faça uma auditoria profunda de código. "
        f"O que pode quebrar a resposta JSON ou travar o curl estruturalmente nas requisições PHP locais?"
    )
    res_claude = anthropic_client.messages.create(
        model="claude-sonnet-4-6",
        max_tokens=1500,
        messages=[{"role": "user", "content": prompt_claude}]
    )
    analise_claude = res_claude.content[0].text

    # --- FASE 3: CHATGPT (Refinamento Comercial e Plano de Ação Executivo) ---
    print("✍️ [3/3] ChatGPT consolidando dados em um relatório definitivo acionável...")
    prompt_openai = (
        f"Você recebeu duas auditorias de IA sobre o erro de deploy da Shop Vivaliz.\n\n"
        f"Análise de Infra (Gemini):\n{analise_gemini}\n\n"
        f"Análise de Código (Claude):\n{analise_claude}\n\n"
        f"Consolide tudo em um relatório final limpo em Markdown. Seja muito direto. Divida em:\n"
        f"1. 🔍 O que causou o Erro (Resumo técnico definitivo)\n"
        f"2. 🛠️ Linhas de Ação Imediata (Passo a passo exato do que arrumar no FTP ou código)\n"
        f"3. 🛡️ Correção de Segurança e Estabilidade"
    )
    res_openai = openai_client.chat.completions.create(
        model="gpt-4o-mini",
        messages=[{"role": "user", "content": prompt_openai}]
    )
    relatorio_final = res_openai.choices.message.content

    # 3. Imprime o painel na tela e salva o arquivo de log no repositório
    print("\n================== RELATÓRIO DO TRIO CONJUNTO (IA) ==================\n")
    print(relatorio_final)
    print("\n====================================================================\n")

    with open("ai_collaboration_report.md", "w", encoding="utf-8") as f:
        f.write(relatorio_final)
    print("✅ Sucesso! O arquivo 'ai_collaboration_report.md' foi gerado por todas as IAs.")

if __name__ == "__main__":
    iniciar_super_agente_trio()
