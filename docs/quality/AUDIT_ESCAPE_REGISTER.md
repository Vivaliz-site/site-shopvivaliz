# Registro Global de AUDIT_ESCAPE

Este registro é a memória institucional de defeitos encontrados após uma auditoria que deveriam ter sido detectados pelo escopo declarado.

## Regras
- Não registrar secrets, credenciais, dados pessoais ou conteúdo sensível.
- Cada entrada deve descrever a **classe de falha**, não apenas o caso isolado.
- A entrada só pode ser marcada como encerrada após causa funcional, causa do falso-negativo, correção/prevenção, busca por equivalentes e reauditoria.
- Toda auditoria extrema futura deve revisar as classes abaixo e exercitar as que forem aplicáveis ao projeto/release auditado.
- Quando uma classe for sistêmica, propague a prevenção para os demais repositórios aplicáveis.

## Template

| ID | Data | Projeto/Release | Classe de falha | Causa funcional | Causa do falso-negativo | Superfícies equivalentes | Prevenção adicionada | Projetos aplicáveis | Evidência de reauditoria | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| ESC-YYYY-NNN | YYYY-MM-DD | repo / SHA | classe | causa | por que a auditoria não detectou | rotas/estados/jobs equivalentes | teste/gate/observabilidade/regra | repos | links/artefatos/SHA | OPEN/CLOSED |

## Entradas

| ID | Data | Projeto/Release | Classe de falha | Causa funcional | Causa do falso-negativo | Superfícies equivalentes | Prevenção adicionada | Projetos aplicáveis | Evidência de reauditoria | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| ESC-2026-001 | 2026-09-21 | site-shopvivaliz / SHA 35fa1320 (auditoria 2026-09-19) | AUDIT_SILENT_FAILURE_V1: endpoint retorna sucesso aparente mas executa caminho errado; escopo de auditoria excluiu componente crítico | (1) `admin/squad-chat.html` apontava para `/api/agent/squad-chat.php` (endpoint Liz público) em vez de `/claude/api/agent/squad-chat.php` (squad real), silenciosamente nunca chamando o squad multi-agente; (2) `claude/api/agent/squad-chat.php` tinha `dirname(__DIR__, 2)` em vez de `dirname(__DIR__, 3)` — `.env` nunca carregado, variáveis AI nunca lidas; (3) `SQUAD_GEMINI_MODEL` sem fallback `AI_SQUAD_GEMINI_MODEL` e modelo padrão `gemini-1.5-flash` descontinuado; (4) GH_REPO default apontava para repo antigo `fredmourao-ai/site-shopvivaliz` | A auditoria de 2026-09-19 exercitou o smoke do storefront (checkout, catálogo, saúde) mas não exercitou `/claude/api/agent/squad-chat.php?health=1` nem testou o painel admin squad com POST real; o escopo declarado não incluiu endpoints bloqueados pelo `.htaccess` nem componentes admin não listados nas operações críticas do e-commerce | (a) `api/agent/squad-chat.php` (Liz pública): mesma divergência de variável já corrigida em PR #1687; (b) qualquer endpoint em `claude/` acessível somente por exceção do `.htaccess` | Fixes SAFE aplicados em PR #1689 (merge SHA `63ae6faf`, deploy `20260921-174416-f386a922`): `dirname(__DIR__, 3)`, `AI_SQUAD_GEMINI_MODEL` fallback, modelo `gemini-2.5-flash`, GH_REPO default corrigido, exceção `.htaccess` para squad-chat, `API_ENDPOINT` corrigido no frontend. Regra adicionada ao AUDIT_OVERLAY: auditar explicitamente endpoints em diretórios bloqueados e verificar URL real usada pelo frontend admin | site-shopvivaliz | Smoke pós-deploy 2026-09-21 ~17:48 UTC: `GET ?health=1` → HTTP 200, `env_loaded:true`, providers configurados (anthropic/openai/gemini), 8 agentes ativos. `API_ENDPOINT` em `admin/squad-chat.html` confirmado `/claude/api/agent/squad-chat.php`. PENDENTE do operador: configurar `SQUAD_TOKEN` no `.env` da VM para habilitar POSTs | CLOSED — código corrigido e validado; ação de configuração restante é operacional (SQUAD_TOKEN no .env) |
