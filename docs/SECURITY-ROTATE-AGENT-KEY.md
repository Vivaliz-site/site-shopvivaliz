# Rotação obrigatória da chave dos agentes

Uma chave de agente foi encontrada no histórico do repositório e deve ser considerada comprometida.

## Procedimento

1. Gere uma nova chave aleatória forte fora do repositório.
2. Armazene-a no ambiente de produção e somente nos GitHub Actions Secrets necessários.
3. Use o nome canônico `SHOPVIVALIZ_AGENT_KEY`.
4. Envie a chave apenas no header `X-ShopVivaliz-Agent-Key`.
5. Revogue a chave anterior em todos os ambientes.
6. Confirme que endpoints antigos de sincronização retornam HTTP 410.

Nunca registre valores reais em commits, issues, logs ou documentação.
