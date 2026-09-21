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
- Para agentes, essa sessão administrativa deve ser criada pelo login nativo `/auth/login.php` com a credencial canônica do arquivo privado `admsite.txt` no Google Drive autorizado; não usar Google OAuth como fluxo operacional do admin.
- Nunca versionar, imprimir ou copiar o conteúdo de `admsite.txt`; a documentação registra apenas a localização segura.
- POST da API aceita sessão administrativa com CSRF ou chave operacional já provisionada.
- Nenhuma chave de provider é enviada ao navegador.
- A UI identifica `direto` ou `via OpenRouter`; fallback de transporte nunca é apresentado como troca silenciosa de modelo.
- Secrets são lidos apenas por `config/bootstrap-env.php` a partir do runtime protegido.
- O log persistente contém somente metadados do ciclo; prompt e respostas completas não são persistidos por padrão.
- Nunca registrar `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GOOGLE_API_KEY`, `SQUAD_TOKEN` ou `SHOPVIVALIZ_AGENT_KEY`.

## Perfis

### `deep_research`

Preset para pesquisas aprofundadas e debates com evidência atual. O nível operacional atual de raciocínio/esforço é `medium` para os três provedores:

- OpenAI: `gpt-5.6-terra`, effort `medium`;
- Anthropic: `claude-sonnet-5`, effort `medium`;
- Gemini: `gemini-3.5-flash`, thinking `MEDIUM`;
- web search habilitado para os três.

Por decisão operacional, Fable não faz parte de nenhum preset do AI Squad.

### `balanced`

- OpenAI: `gpt-5.6-terra`, effort `high`;
- Anthropic: `claude-sonnet-5`, effort `high`;
- Gemini: `gemini-3.5-flash`, thinking `MEDIUM`.

### `fast`

Perfil de menor custo/latência para tarefas simples.

## Variáveis de ambiente

As variáveis abaixo são referências de configuração. Valores nunca devem ser versionados.

Transportes operacionais atuais:

- OpenAI: `codex_chatgpt` (login ChatGPT Business) → API direta quando configurada → fallback manual explícito;
- Anthropic: `claude_code` com OAuth da conta; não há fallback silencioso para API direta, Vertex ou OpenRouter;
- Gemini: `vertex_oauth` → API direta quando configurada → OpenRouter quando configurado.

Credenciais opcionais de fallback são mantidas apenas no runtime protegido. `OPENAI_API_KEY`, `GEMINI_API_KEY`/`GOOGLE_API_KEY` e `OPENROUTER_API_KEY` não são requisitos para considerar os bridges primários autenticados.

Overrides opcionais:

- `AI_SQUAD_OPENAI_MODEL`
- `AI_SQUAD_ANTHROPIC_MODEL`
- `AI_SQUAD_GEMINI_MODEL`
- equivalentes `*_BALANCED_MODEL` e `*_FAST_MODEL`;
- `AI_SQUAD_CODEX_WEB_SEARCH_MODE` — modo de pesquisa web do bridge Codex (`live`, `cached` ou `disabled` conforme política de runtime).

## Health

```bash
curl -fsS 'https://shopvivaliz.com.br/api/agent/ai-squad.php?health=1&profile=deep_research'
```

O health esperado contém:

- `ok=true`
- `endpoint=ai-squad`
- `providers` com OpenAI, Anthropic e Gemini;
- modelo e esforço de cada provider;
- `health=verified` somente quando o transporte primário possui prova viva de autenticação/disponibilidade;
- `health=configured_unverified` quando existe transporte configurado, mas sem prova viva equivalente;
- `health=unavailable` quando nenhum transporte está configurado;
- estados de autenticação/transportes em booleanos, nunca a credencial.

A UI não pode converter `configured=true` em indicador verde. Verde exige `health=verified`; configuração sem verificação deve aparecer como estado distinto.

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
- `agent_error`
- `consensus`
- `cycle_finished`

## Testes

```bash
php -l includes/ai-squad-core.php
php -l api/agent/ai-squad.php
php -l admin/ai-squad.php
php tests/ai-squad-core-test.php
```

O teste também falha caso o nome `fable` apareça em qualquer preset.
