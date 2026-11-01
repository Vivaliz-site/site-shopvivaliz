# Autorizacao humana: leitura privada Marina pelo MCP central (2026-11-01)

**Estado inicial: proposta NAO AUTORIZADA**, nao libera host, sudo, shell, deploy ou secrets. O proprietario pode revisar pelo GitHub.

## Escopo do grant

- Provedor: ShopVivaliz MCP central em `always-free-arm-1787907847-26`, principal `shopvivaliz-central-mcp`.
- Destino unico: `marina-a1-4c24g` na malha privada Tailscale por identidade SSH e host-key pinadas ja provisionadas.
- Recurso: `mcp:central:marina`.
- Capacidade exclusiva: `marina_readonly` = `hosts_list`, `host_health` e `service_status` somente para unidades fixas do codigo aprovado.
- Nega expressamente execucao generica de comandos, arquivos sensiveis, operacoes administrativas, sudo, deploy, escrita e a VM retirada.
- Grant por PR `security/owner-approvals/<numero>.json` com prazo UTC e identidade exata do host, verificado por `scripts/validate-owner-pr-grant.py`. So vale apos review de SHA e **merge pessoal por `shopvivaliz-human`**, sem auto-merge.
- Aprovar esta proposta nao altera nem substitui RBAC, MFA, permissoes SSH do provedor, leases ou consentimentos externos.
- Antes de ativar: verificar grant vigente e imutavel no `main` por canal oficial, apontamento exact do runtime, permissoes e metadados privados de SSH sem imprimir chaves. Uma flag isolada nunca basta.
- Runtime deve negar automaticamente ao expirar o grant; revogacoes exigem desativacao do operador e nova prova. Testes negativos para falta de flag, falta/expiracao do prazo, erros de identidade, comandos nao aprovados.
- Instalacao de codigo via instalador restrito oficial, backup e rollback; habilitacao somente depois de duas etapas independentes: seguranca humana e verificacao real de acesso/host autorizado. Testar MCP `tools/list`, `hosts_list`, `host_health`, `service_status`, audit ID, negacoes e readback.
- O MCP independente da Marina segue exigido e deve operar separadamente, com suas proprias credenciais OpenAI Secure MCP Tunnel.

**Sem estas condicoes, o status permanece `MCP_CENTRAL_MARINA_GRANT=NAO_APTO`.**
