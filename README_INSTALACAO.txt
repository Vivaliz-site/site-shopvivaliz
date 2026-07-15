Salvar estes arquivos no repositorio site-shopvivaliz mantendo exatamente os caminhos abaixo:

1. config/autonomous-policy.json
2. scripts/autonomous-policy-guard.py
3. docs/modelo-autonomo-shopvivaliz.md

Depois editar scripts/autonomous-executor.py e inserir a validacao abaixo antes de executar ai_collaboration.py:

# Guardiao da politica autonoma
policy_guard = subprocess.run(
    ["python", "scripts/autonomous-policy-guard.py", task_title, task_desc],
    capture_output=True,
    text=True,
)
print(policy_guard.stdout.strip())
if policy_guard.returncode != 0:
    task["status"] = "blocked_pricing_review"
    task["blocked_at"] = datetime.utcnow().isoformat() + "Z"
    task["block_reason"] = policy_guard.stdout.strip()
    with open(queue_file, "w", encoding="utf-8") as f:
        json.dump(queue_data, f, indent=2, ensure_ascii=False)
    return policy_guard.returncode

Local exato para inserir:
Em scripts/autonomous-executor.py, depois destas linhas:

task_title = task["title"]
task_desc = task["description"]

E antes do bloco:
# Executar Trio IA com a tarefa

Depois:
git add config/autonomous-policy.json scripts/autonomous-policy-guard.py docs/modelo-autonomo-shopvivaliz.md scripts/autonomous-executor.py
git commit -m "feat: implantar politica autonoma exceto precos"
git push

Como o deploy esta automatico, o push deve disparar o envio.
