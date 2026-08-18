# ShopVivaliz - Acesso dos Agentes e Regras de Segurança

## Acesso direto ao GitHub

O acesso direto do ChatGPT ao GitHub não é liberado por arquivo do repositório.
Deve ser autorizado em:

ChatGPT -> Configurações -> Conectores / Apps conectados -> GitHub

Repositório:

Vivaliz-site/site-shopvivaliz

## Agentes via GitHub Actions

Claude, Gemini e ChatGPT podem revisar arquivos pelo GitHub Actions usando GitHub Secrets.

## Observabilidade obrigatória de Actions

Alguns conectores de agentes não conseguem listar `workflow_runs` diretamente, mesmo quando conseguem ler arquivos, commits e jobs por `run_id` conhecido.

Para remover essa limitação de forma operacional e padronizada, todos os agentes devem usar o contrato canônico em [`docs/agent-actions-observability.md`](agent-actions-observability.md):

- solicitar indexação em `ops/actions-run-index-request.json`;
- ler `ops/actions-run-index.json`;
- obter `runs[].id`, `status`, `conclusion`, `head_sha` e `html_url`;
- só então usar ações de jobs/logs/artifacts que dependam de `run_id`.

Nenhum agente deve declarar que a descoberta de runs está indisponível antes de tentar esse caminho.

## Secrets esperados

- OPENAI_API_KEY
- ANTHROPIC_API_KEY
- GEMINI_API_KEY
- FTP_SERVER
- FTP_USERNAME
- FTP_PASSWORD
- FTP_PORT
- FTP_REMOTE_DIR

Para o mapa completo de canônicos, aliases e o que vive em `shared/.env` ou `runtime-secrets.php`, use [`docs/secrets-inventory.md`](docs/secrets-inventory.md).
`REMOTE_MCP_*` não é um secret de GitHub Actions nem de runtime materializado; fica só como documentação/local.

## Regras

- Nunca commitar senhas, tokens ou chaves.
- Nunca imprimir secrets em logs.
- Usar apenas referências como secrets.NOME.
- Toda alteração deve ser cumulativa, segura e reversível.
