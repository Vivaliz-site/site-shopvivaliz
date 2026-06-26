import os
import sys
from google import genai
from openai import OpenAI

def iniciar_agente_duplo():
    print("🤖 Inicializando colaboração: Gemini + ChatGPT...")
    
    # 1. Conecta com as APIs usando as chaves seguras do ambiente do GitHub
    try:
        gemini_client = genai.Client()
        openai_client = OpenAI()
    except Exception as e:
        print(f"❌ Erro ao carregar as credenciais das IAs: {e}")
        sys.exit(1)

    # 2. Captura o contexto do seu projeto para as IAs analisarem
    # Vamos ler o arquivo de deploy e o manual para contextualizar a IA
    contexto_projeto = ""
    arquivos_alvo = [".github/workflows/deploy.yml", "ADMIN_MANUAL.md"]
    
    for arquivo in arquivos_alvo:
        if os.path.exists(arquivo):
            with open(arquivo, "r", encoding="utf-8") as f:
                contexto_projeto += f"\n--- Conteúdo do arquivo {arquivo} ---\n" + f.read()

    print("🧠 Fase 1: Gemini analisando a arquitetura e logs de teste...")
    
    # Prompt focado na estrutura técnica da Shop Vivaliz na HostGator
    prompt_gemini = (
        f"Você é um engenheiro de infraestrutura especialista em HostGator e integrações ERP/e-commerce.\n"
        f"Analise a estrutura atual do nosso fluxo de deploy e arquitetura:\n\n{contexto_projeto}\n\n"
        f"Identifique possíveis pontos de falha que causam o erro 'Run failed: Deploy Automático' "
        f"nos testes de API do Melhor Envio, Olist ou verificação de CNPJ."
    )
    
    response_gemini = gemini_client.models.generate_content(
        model='gemini-2.5-flash',
        contents=prompt_gemini,
    )
    analise_tecnica_gemini = response_gemini.text

    print("✍️ Fase 2: ChatGPT refinando a análise e gerando guia de correção...")

    # O ChatGPT entra para transformar o diagnóstico bruto em soluções práticas e organizadas
    prompt_openai = (
        f"O Gemini realizou o seguinte diagnóstico técnico do repositório:\n\n{analise_tecnica_gemini}\n\n"
        f"Com base nessas informações, crie um relatório final em formato Markdown (.md).\n"
        f"O relatório deve conter:\n"
        f"1. 🔍 Causa provável da falha no servidor de desenvolvimento.\n"
        f"2. 🛠️ Código de correção sugerido (PHP ou configuração).\n"
        f"3. 🚀 Próximos passos para garantir o deploy seguro."
    )

    response_openai = openai_client.chat.completions.create(
        model="gpt-4o-mini",
        messages=[{"role": "user", "content": prompt_openai}]
    )
    relatorio_final = response_openai.choices.message.content

    # 3. Salva o resultado combinado das duas IAs no repositório
    nome_relatorio = "ai_collaboration_report.md"
    with open(nome_relatorio, "w", encoding="utf-8") as f:
        f.write(relatorio_final)
        
    print(f"✅ Sucesso! O relatório conjunto foi gerado em: {nome_relatorio}")

if __name__ == "__main__":
    iniciar_agente_duplo()
