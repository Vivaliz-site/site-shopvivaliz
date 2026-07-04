# AGENTS.md — Diretrizes Operacionais dos Agentes ShopVivaliz

> Última atualização: 2026-07-04  
> Responsável: fredmourao-ai  
> Aplicável a: todos os agentes autônomos (Claude, Gemini, ChatGPT, executores CI)

---

## 1. Fluxo obrigatório de trabalho

Nenhum agente deve fazer push direto para `main`. O fluxo correto é:

```
1. Criar branch descritiva:  git checkout -b feat/descricao-curta
2. Implementar e commitar:   git commit -m "feat: descrição clara"
3. Push da branch:           git push origin feat/descricao-curta
4. Abrir Pull Request
5. Aguardar merge (automático ou humano)
6. Deploy ocorre após merge em main
```

## 2. Nomeclatura de branches e commits

- **Branches:** `feat/`, `fix/`, `chore/`, `docs/` seguido de slug em minúsculas
- **Commits:** mensagens em português começando com `feat:`, `fix:`, `chore:`, `docs:`
- **Sufixo `[skip ci]`:** usar somente em commits que atualizam apenas relatórios ou logs

## 3. Política autônoma — único bloqueio: preços

O executor autônomo usa o guardião de política já integrado (`scripts/autonomous-policy-guard.py`).

**Pode executar autonomamente (sem aprovação humana):**
- Desenvolvimento, QA, documentação
- Deploy, rollback, integrações
- Sincronizações de catálogo, imagens, anúncios
- Campanhas e otimizações de anúncios (desde que sem alterar preço final)
- Monitoramento, observabilidade e auto-recuperação

**Requer aprovação humana (bloqueado pelo guardião):**
- Preço de venda ou preço promocional
- Descontos ou cupons que afetam o preço final
- Margem, markup ou regras de precificação
- Sincronização de preço entre site, marketplaces, Olist, Tiny, ML, Shopee
- Qualquer valor cobrado do cliente

## 4. Segurança de secrets

- **Nunca** commitar `.env`, tokens, API keys, senhas ou cookies
- **Nunca** imprimir secrets em logs; mascarar com `***` quando necessário
- Credenciais somente via GitHub Secrets ou `.env` no servidor (fora do repositório)
- Validar `.gitignore` antes de qualquer `git add`

## 5. .gitignore — arquivos nunca versionados

```
.env
.env.*
node_modules/
vendor/
logs/
storage/runtime/
*.log
*secret*
*token*
*password*
*senha*
login_config.json
```

## 6. Logs obrigatórios por tarefa

Cada execução de agente deve registrar:

| Campo | Descrição |
|-------|-----------|
| `task_id` | Identificador único da tarefa |
| `branch` | Branch criada para a tarefa |
| `pull_request` | URL do PR aberto |
| `deploy_run` | URL do workflow de deploy |
| `status` | `success` / `blocked` / `failed` |
| `timestamp` | ISO 8601 UTC |

Destino padrão dos logs: `automation/eha/reports/`

## 7. Executor e guardião

O executor autônomo (`scripts/autonomous-executor.py`) **deve sempre** consultar o guardião antes de executar qualquer tarefa:

```python
from autonomous_policy_guard import guard
if not guard.allow(task):
    raise PolicyBlockError(f"Tarefa bloqueada: {guard.reason}")
```

## 8. Padrão de modelo OpenAI (custo mínimo)

```env
OPENAI_MODEL=gpt-4o-mini
OPENAI_REASONING_EFFORT=minimal
```

Usar modelo mais caro apenas quando qualidade superior for explicitamente solicitada ou justificada no relatório.

## 9. Checklist antes de abrir PR

- [ ] Nenhum marcador de conflito Git (`<<<<<<<`, `=======`, `>>>>>>>`) em arquivos de produção
- [ ] Nenhum secret em arquivos versionados
- [ ] PHP sem erros de sintaxe (`php -l arquivo.php`)
- [ ] `.gitignore` cobre `.env` e `node_modules/`
- [ ] Commit message em português com prefixo correto
- [ ] Branch diferente de `main`

## 10. Contato para bloqueios

Escalar para o responsável humano quando:
- Credencial ausente ou expirada (sem valor no secret)
- Permissão de servidor necessária
- Decisão de preço ou desconto pendente

**Responsável:** fredmourao@gmail.com  
**Repositório:** https://github.com/fredmourao-ai/site-shopvivaliz
