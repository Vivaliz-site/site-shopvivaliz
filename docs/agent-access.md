# ShopVivaliz - Acesso dos Agentes e Regras de Segurança

## Acesso direto ao GitHub

O acesso direto do ChatGPT ao GitHub não é liberado por arquivo do repositório.
Deve ser autorizado em:

ChatGPT -> Configurações -> Conectores / Apps conectados -> GitHub

Repositório:

fredmourao-ai/site-shopvivaliz

## Agentes via GitHub Actions

Claude, Gemini e ChatGPT podem revisar arquivos pelo GitHub Actions usando GitHub Secrets.

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
