# ShopVivaliz Admin Plugin

Plugin MCP para ChatGPT que fornece acesso em tempo real ao status do repositório ShopVivaliz.

## Recursos

- **GitHub Workflows**: Liste e monitore workflows recentes
- **Git Status**: Verifique branch atual, mudanças pendentes, últimos commits
- **Server Health**: Monitore Apache, espaço em disco, memória
- **Error Diagnostics**: Diagnose erros HTTP 500 e problemas do servidor

## Ferramentas Disponíveis

1. `shopvivaliz_github_list_runs` - Lista workflows recentes do GitHub
2. `shopvivaliz_vm_git_status` - Status do repositório Git
3. `shopvivaliz_vm_system_health` - Saúde do servidor (Apache, disco, RAM)
4. `shopvivaliz_diagnose_http500` - Diagnóstico de erros HTTP 500

## Como Usar

No ChatGPT:
1. Registre este plugin com a URL MCP
2. Pergunte sobre o status do ShopVivaliz
3. O plugin fornecerá informações em tempo real

## Exemplos de Perguntas

- "Qual é o status do repositório ShopVivaliz?"
- "Como está a saúde do servidor?"
- "Quais foram os últimos workflows?"
- "Há algum erro no site?"

## Segurança

- ✅ Apenas ferramentas read-only expostas
- ✅ Nenhuma autenticação requerida (read-only)
- ✅ Nenhuma credencial incluída
- ✅ Acesso transparente via MCP

## Técnico

- **Protocolo**: MCP (Model Context Protocol)
- **Transporte**: Server-Sent Events (SSE)
- **Formato**: JSON-RPC 2.0
- **Host**: Cloudflare Tunnel (temporary)
