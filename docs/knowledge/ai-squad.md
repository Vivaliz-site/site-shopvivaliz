# AI Squad — pesquisa multi-provider

## Objetivo

O AI Squad é um orquestrador reutilizável para pesquisas, comparações e decisões que se beneficiam de revisão independente entre provedores.

Interface administrativa:

`/admin/ai-squad.php`

API:

`/api/agent/ai-squad.php`

A UI mostra a interação em fases:

1. pesquisa independente;
2. contraditório entre OpenAI, Claude e Gemini;
3. convergência individual;
4. síntese de consenso.

## Segurança

- A UI exige sessão administrativa.
- POST da API aceita sessão administrativa com CSRF ou chave operacional já provisionada.
- Nenhuma chave de provider é enviada ao navegador.
- A UI identifica o transporte efetivamente usado por cada perna: OpenAI pode usar `codex_chatgpt`, Claude pode usar `claude_code`, e Gemini pode usar `vertex_oauth`; fallbacks nunca são apresentados como troca silenciosa de modelo.
- O modelo solicitado pelo perfil deve permanecer exato em todos os transportes. OpenRouter, quando alcançado, pode devolver o slug prefixado do mesmo modelo (`anthropic/...` ou `google/...`), mas não pode substituir o modelo por outro.
- O bridge Codex escuta apenas em loopback e usa autenticação ChatGPT já gerenciada pelo Codex; PHP e navegador nunca recebem tokens do ChatGPT.
- A ferramenta de shell e agentes delegados ficam desabilitados no App Server usado pelo AI Squad; comandos locais rodam com sandbox somente leitura e sem rede.
- ChatGPT Web é somente fallback manual/visual. O AI Squad não raspa nem lê automaticamente a resposta da interface Web.
- A sessão visual canônica fica em `always-free-arm-1787907847-26`, usuário `fredrdp`, perfil `/home/fredrdp/.config/shopvivaliz-chromium`, CDP `127.0.0.1:9555`; reutilizar essa sessão e nunca criar um perfil duplicado em `shopvivaliz-free-a1`.
- Secrets são lidos apenas por `config/bootstrap-env.php` a partir do runtime protegido.
- O log persistente contém somente metadados do ciclo; prompt e respostas completas não são persistidos por padrão.
- Nunca registrar `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GOOGLE_API_KEY`, `SQUAD_TOKEN` ou `SHOPVIVALIZ_AGENT_KEY`.

## Cadeia OpenAI

Para cada fase, o agente OpenAI usa esta ordem, preservando exatamente o modelo do perfil:

1. `codex_chatgpt` — bridge local `127.0.0.1:17656` para Codex App Server autenticado via ChatGPT;
2. `direct` — OpenAI Responses API quando `OPENAI_API_KEY` tem créditos/cota;
3. `manual` — evento explícito `agent_manual_required`, com prompt copiável para a sessão ChatGPT já autenticada na VM/RDP.

Quando `web_search=true`, o bridge Codex usa `cached` por padrão. Esse modo foi validado com o Codex App Server/ChatGPT Business e evita o bloqueio observado com `live`; `live` só deve ser habilitado explicitamente por `AI_SQUAD_CODEX_WEB_SEARCH_MODE=live` após probe real. Uma falha do Codex coloca esse transporte em cooldown pelo restante do ciclo PHP, evitando repetir um timeout conhecido em cada fase. O fallback manual não conta como resposta válida nem como moderador.

## Cadeias Claude e Gemini

Anthropic usa, nesta ordem:

1. `claude_code` — bridge local `127.0.0.1:17657` executando Claude Code com `CLAUDE_CODE_OAUTH_TOKEN` da assinatura autorizada;
2. `direct` — Anthropic Messages API com `ANTHROPIC_API_KEY`;
3. `vertex_oauth` — Claude on Vertex AI com OAuth Google já provisionado;
4. `openrouter` — último fallback, somente quando a credencial OpenRouter estiver válida.

Gemini usa, nesta ordem:

1. `vertex_oauth` — Vertex AI com OAuth Google (`cloud-platform`), preservando o modelo do perfil;
2. `direct` — Gemini API com `GEMINI_API_KEY`/`GOOGLE_API_KEY`;
3. `openrouter` — último fallback, somente quando configurado e autenticado.

O bridge Claude é um serviço loopback finito por chamada: não executa polling de IA paga, não persiste sessões, recebe o prompt do usuário por stdin e roda em modo restrito. O health estrutural está em `http://127.0.0.1:17657/health` e nunca expõe token, e-mail ou prompt.

## Serviço Codex

Instalador versionado:

`ops/ai-squad/install-codex-bridge-user-service.sh`

Validação operacional:

```bash
systemctl --user status shopvivaliz-squad-codex-bridge.service
curl -fsS http://127.0.0.1:17656/health
```

O health do bridge publica apenas estado agregado de autenticação/cota, allowlist de modelos e o modo de pesquisa Web (`cached`/`live`); não publica conta, e-mail, token ou nome de perfil.

## Perfis

### `deep_research`

Preset para pesquisas aprofundadas e debates com evidência atual:

- OpenAI: `gpt-5.6-terra`, effort `medium`;
- Anthropic: `claude-sonnet-5`, effort `medium`;
- Gemini: `gemini-3.5-flash`, thinking `MEDIUM`;
- web search habilitado para os três; no transporte Codex, usa `cached` por padrão.

Por decisão operacional, Fable não faz parte de nenhum preset do AI Squad.

### `balanced`

- OpenAI: `gpt-5.6-terra`, effort `high`;
- Anthropic: `claude-sonnet-5`, effort `high`;
- Gemini: `gemini-3.5-flash`, thinking `MEDIUM`.

### `fast`

Perfil de menor custo/latência para tarefas simples.

## Variáveis de ambiente

As variáveis abaixo são referências de configuração. Valores nunca devem ser versionados.

Credenciais diretas, quando esse transporte for usado:

- `OPENAI_API_KEY` — opcional quando `codex_chatgpt` está disponível;
- `ANTHROPIC_API_KEY`;
- `GEMINI_API_KEY` ou `GOOGLE_API_KEY`.

Fallback opcional dos outros provedores:

- `OPENROUTER_API_KEY` — pode ser usado por Anthropic/Gemini após falha direta; não participa da cadeia OpenAI.

Credenciais/transporte OAuth adicionais:

- `CLAUDE_CODE_OAUTH_TOKEN` — token OAuth de longa duração usado pelo bridge Claude Code;
- `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, `GOOGLE_OAUTH_REFRESH_TOKEN` — OAuth para Vertex AI;
- `AI_SQUAD_GOOGLE_CLOUD_PROJECT` — override opcional do projeto Vertex; quando ausente, o runtime pode usar o número do projeto embutido no client ID OAuth.

Configuração opcional do bridge OpenAI:

- `AI_SQUAD_CODEX_ENABLED=0` desabilita o transporte Codex;
- `AI_SQUAD_CODEX_BRIDGE_URL` sobrescreve o padrão `http://127.0.0.1:17656`;
- `AI_SQUAD_CODEX_HTTP_TIMEOUT` controla o timeout PHP→bridge, limitado a 30–300 segundos;
- `AI_SQUAD_CODEX_WEB_SEARCH_MODE` controla a pesquisa hospedada do Codex; o padrão operacional é `cached`; `live` é opt-in e exige validação real antes de produção.

Configuração opcional do bridge Claude:

- `AI_SQUAD_CLAUDE_CODE_ENABLED=0` desabilita o transporte `claude_code`;
- `AI_SQUAD_CLAUDE_BRIDGE_URL` sobrescreve `http://127.0.0.1:17657`;
- `AI_SQUAD_CLAUDE_HTTP_TIMEOUT` controla o timeout PHP→bridge, limitado a 30–300 segundos.

Overrides opcionais:

- `AI_SQUAD_OPENAI_MODEL`
- `AI_SQUAD_ANTHROPIC_MODEL`
- `AI_SQUAD_GEMINI_MODEL`
- equivalentes `*_BALANCED_MODEL` e `*_FAST_MODEL`.

## Health

```bash
curl -fsS 'https://shopvivaliz.com.br/api/agent/ai-squad.php?health=1&profile=deep_research'
```

O health esperado contém:

- `ok=true`
- `endpoint=ai-squad`
- `providers` com OpenAI, Anthropic e Gemini;
- modelo e esforço de cada provider;
- para OpenAI, `transport_order`, `codex_chatgpt_authenticated`, `codex_chatgpt_available`, `codex_web_search_mode`, `direct_configured` e `manual_fallback`;
- para Anthropic, `transport_order`, `claude_code_oauth_configured`, `claude_code_authenticated`, `claude_code_available`, `direct_configured` e `vertex_oauth_configured`;
- para Gemini, `transport_order`, `vertex_oauth_configured` e `direct_configured`;
- somente estado/booleanos agregados, nunca credenciais nem identidade da conta ChatGPT.

## API externa

Para automações autorizadas, use `Authorization: Bearer <SHOPVIVALIZ_AGENT_KEY>` ou `X-Agent-Key`.

Exemplo conceitual:

```bash
curl -N -X POST 'https://shopvivaliz.com.br/api/agent/ai-squad.php' \
  -H 'Authorization: Bearer <agent-key>' \
  -H 'Content-Type: application/json' \
  --data '{"message":"pesquise o tema X","profile":"deep_research","mode":"research","stream":true}'
```

A resposta streaming usa NDJSON. Eventos relevantes:

- `cycle_started`
- `phase_started`
- `agent_started`
- `agent_message`
- `agent_manual_required` — OpenAI sem transporte automatizado disponível; inclui apenas modelo, prompt manual seguro e classes das tentativas;
- `agent_error`
- `consensus`
- `cycle_finished`

## Testes

```bash
node tests/ai-squad-codex-bridge-test.mjs
bash tests/ai-squad-codex-bridge-install-test.sh
node --check ops/ai-squad/codex-bridge.mjs
php -l includes/ai-squad-core.php
php -l api/agent/ai-squad.php
php -l admin/ai-squad.php
php tests/ai-squad-core-test.php
php tests/ai-squad-manual-fallback-contract-test.php
```

Os testes também falham se Fable aparecer em qualquer preset, se a ordem de transportes OpenAI mudar silenciosamente, se modelo diferente do solicitado for aceito ou se o fallback manual deixar de ser explícito.
