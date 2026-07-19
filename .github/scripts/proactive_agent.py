"""
Agente Proativo Autonomo Vivaliz
Orquestrador: Anthropic -> OpenAI -> Gemini
Roda a cada 4h via GitHub Actions
"""
import json, os, subprocess, sys, shlex
from pathlib import Path
from datetime import datetime

ROOT = Path(".")
FOCUS = os.getenv("FOCUS", "").strip()

BLOCKED_PATHS = [
    ".github/workflows/deploy.yml",
    "logs/", ".env", "config/constants.php",
    "storage/commerce_signals.json",
]


def call_llm(prompt: str) -> str:
    """Orquestrador: tenta Anthropic -> OpenAI -> Gemini ate funcionar."""

    # 1. Anthropic Claude Haiku
    key = os.getenv("ANTHROPIC_API_KEY", "")
    if key:
        try:
            import anthropic
            client = anthropic.Anthropic(api_key=key)
            msg = client.messages.create(
                model="claude-haiku-4-5-20251001",
                max_tokens=4096,
                messages=[{"role": "user", "content": prompt}]
            )
            print("Provider: Anthropic Claude Haiku")
            return msg.content[0].text.strip()
        except Exception as e:
            print(f"Anthropic falhou: {e}")

    # 2. OpenAI GPT-4o-mini
    key = os.getenv("OPENAI_API_KEY", "")
    if key:
        try:
            from openai import OpenAI
            client = OpenAI(api_key=key)
            resp = client.chat.completions.create(
                model="gpt-4o-mini",
                max_tokens=4096,
                messages=[{"role": "user", "content": prompt}]
            )
            print("Provider: OpenAI GPT-4o-mini")
            return resp.choices[0].message.content.strip()
        except Exception as e:
            print(f"OpenAI falhou: {e}")

    # 3. Google Gemini Flash
    key = os.getenv("GEMINI_API_KEY", "")
    if key:
        try:
            import google.generativeai as genai
            genai.configure(api_key=key)
            model = genai.GenerativeModel("gemini-1.5-flash")
            resp = model.generate_content(prompt)
            print("Provider: Google Gemini Flash")
            return resp.text.strip()
        except Exception as e:
            print(f"Gemini falhou: {e}")

    print("AVISO: Todos os providers AI falharam ou chaves ausentes — sem acao nesta execucao.")
    return ""


def read_file(path: str, max_chars: int = 2000) -> str:
    try:
        c = Path(path).read_text(encoding="utf-8", errors="replace")
        return c[:max_chars]
    except Exception:
        return ""


def shell(cmd: str) -> str:
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip()


def build_context() -> str:
    catalog_data = json.loads(Path("api/catalog/fallback-products.json").read_text(encoding="utf-8"))
    total    = len(catalog_data)
    no_desc  = sum(1 for p in catalog_data if not str(p.get("description", "")).strip())
    no_price = 0
    for p in catalog_data:
        try:
            if not float(p.get("price", 0) or 0):
                no_price += 1
        except (ValueError, TypeError):
            no_price += 1
    cats = {}
    for p in catalog_data:
        c = p.get("category", "")
        if c:
            cats[c] = cats.get(c, 0) + 1

    signals = {}
    sp = Path("storage/commerce_signals.json")
    if sp.exists():
        signals = json.loads(sp.read_text())

    pp = Path("logs/pedidos.jsonl")
    orders = sum(1 for l in pp.read_text(encoding="utf-8").splitlines() if l.strip()) if pp.exists() else 0

    health_last = ""
    hp = Path("automation/proactive/logs/health.jsonl")
    if hp.exists():
        lines = [l for l in hp.read_text().splitlines() if l.strip()]
        if lines:
            health_last = lines[-1][:200]

    return f"""
PROJETO: Vivaliz e-commerce (shopvivaliz.com.br)
DATA: {datetime.utcnow().strftime('%Y-%m-%d %H:%M UTC')}
FOCO: {FOCUS or 'varredura geral'}

CATALOGO:
- Total: {total} produtos
- Sem descricao: {no_desc} ({round(no_desc/total*100)}%)
- Sem preco: {no_price} (Tiny OAuth expirado — nao tentar corrigir)
- Categorias: {json.dumps(cats, ensure_ascii=False)}

SINAIS COMMERCE:
- Views: {json.dumps(signals.get('views', {}), ensure_ascii=False)[:300]}
- Cart adds: {json.dumps(signals.get('cart', {}), ensure_ascii=False)[:300]}

PEDIDOS RECEBIDOS: {orders}
ULTIMO HEALTH CHECK: {health_last}

COMMITS RECENTES:
{shell('git log --oneline -8')}

GIT STATUS: {shell('git status --short') or '(limpo)'}

INDEX.PHP (inicio):
{read_file('index.php', 1200)}

CATALOGO.PHP (inicio):
{read_file('catalogo.php', 800)}

CSS (fim):
{read_file('css/style.css', 600)}
"""


def build_prompt(context: str) -> str:
    return f"""Voce e o agente autonomo proativo do Vivaliz e-commerce (PHP 8, HostGator shared hosting).
Analise o estado atual do projeto e execute UMA melhoria concreta, segura e de alto valor.

CONTEXTO ATUAL:
{context}

PRIORIDADES (ordem):
1. Se no_desc > 0: gere descricoes reais (2-3 linhas) para 5 produtos no fallback-products.json
   ATENCAO: so atualize o campo "description" dos produtos — nao altere outros campos
2. Corrija bugs visiveis (404, JS errors, links quebrados)
3. Melhore UX/conversao (CTAs, textos, layout)
4. Melhore SEO (meta tags, headings, alt texts)
5. Adicione funcionalidade util pequena

REGRAS ABSOLUTAS:
- Nunca toque: .github/workflows/deploy.yml, logs/, .env, config/constants.php
- Nao apague funcionalidade existente
- Nao tente corrigir precos (Tiny OAuth expirado, sem solucao no codigo)
- Para fallback-products.json: retorne o arquivo JSON COMPLETO com TODOS os 197 produtos
- Responda APENAS JSON puro (zero markdown, zero texto antes/depois)

FORMATO DE RESPOSTA:
{{
  "action": "write_file",
  "reason": "justificativa clara do valor desta mudanca",
  "file_path": "caminho/relativo/arquivo.ext",
  "content": "conteudo COMPLETO do arquivo",
  "commit_message": "tipo: descricao curta em portugues"
}}

OU se nada for necessario:
{{"action": "no_action", "reason": "explicacao"}}
"""


def run_agent():
    print(f"=== Agente Proativo Vivaliz === {datetime.utcnow().isoformat()}Z")
    print(f"Foco: {FOCUS or 'varredura geral'}")

    context = build_context()
    prompt  = build_prompt(context)

    print("Consultando LLM...")
    raw = call_llm(prompt)
    if not raw:
        print("Nenhum provider disponivel — encerrando sem acao.")
        sys.exit(0)
    print(f"Resposta recebida: {len(raw)} chars")

    # Remove markdown se vier
    if "```" in raw:
        for part in raw.split("```"):
            s = part.strip().lstrip("json").strip()
            if s.startswith("{"):
                raw = s
                break

    try:
        result = json.loads(raw.strip())
    except json.JSONDecodeError as e:
        print(f"JSON invalido: {e}")
        print(f"Raw (primeiros 400): {raw[:400]}")
        sys.exit(0)

    action     = result.get("action", "no_action")
    reason     = result.get("reason", "")
    file_path  = result.get("file_path", "")
    content    = result.get("content", "")
    commit_msg = result.get("commit_message", "chore: melhoria proativa autonoma")

    print(f"Acao: {action}")
    print(f"Razao: {reason[:120]}")

    if action == "no_action":
        print("Sem melhorias necessarias nesta execucao.")
        return

    # Validacoes de seguranca
    norm_path = file_path.rstrip("/")
    if any(norm_path == b.rstrip("/") or norm_path.startswith(b.rstrip("/") + "/") for b in BLOCKED_PATHS):
        print(f"BLOQUEADO por seguranca: {file_path}")
        return

    if not file_path or not content or len(content) < 20:
        print(f"file_path ou content invalido ({file_path!r}, {len(content)} chars)")
        return

    if len(content.encode("utf-8")) > 512000:
        print("Conteudo >512KB bloqueado por seguranca")
        return

    # Escreve arquivo
    target = ROOT / file_path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content, encoding="utf-8")
    print(f"Arquivo escrito: {file_path} ({len(content)} chars)")

    # Commit
    subprocess.run(["git", "config", "user.name", "Claude Autonomo"])
    subprocess.run(["git", "config", "user.email", "fredmourao@gmail.com"])
    subprocess.run(["git", "add", file_path])

    diff = shell("git diff --cached --stat")
    if not diff:
        print("Sem diferenca detectada — nada a commitar")
        return

    print(f"Diff: {diff}")
    full_msg = (
        f"{commit_msg}\n\n"
        f"Agente proativo Vivaliz — orquestrador Anthropic/OpenAI/Gemini\n"
        f"Co-Authored-By: Claude Haiku 4.5 <noreply@anthropic.com>"
    )
    subprocess.run(["git", "commit", "-m", full_msg], check=True)
    subprocess.run("git pull --rebase origin main || true", shell=True)
    result2 = subprocess.run("git push origin main", shell=True)
    if result2.returncode == 0:
        print(f"Deploy disparado: {commit_msg}")
    else:
        print("Push falhou — sera tentado na proxima execucao")

    # Log
    log_path = Path("automation/proactive/logs/runs.jsonl")
    log_path.parent.mkdir(parents=True, exist_ok=True)
    entry = {
        "timestamp": datetime.utcnow().isoformat() + "Z",
        "action": action,
        "file": file_path,
        "reason": reason,
        "commit": commit_msg,
    }
    with open(log_path, "a", encoding="utf-8") as f:
        f.write(json.dumps(entry, ensure_ascii=False) + "\n")


if __name__ == "__main__":
    run_agent()
