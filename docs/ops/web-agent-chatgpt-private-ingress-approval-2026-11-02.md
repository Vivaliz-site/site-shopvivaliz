# ShopVivaliz Web Agent — autorização de publicação privada no ChatGPT

Data: 2026-11-02. Classificação: **P2 — nova exposição de superfície / autenticação / identidade externa**. Esta solicitação NÃO implementa DNS, ingress, OAuth, credenciais, poderes administrativos ou publicação. Seu único efeito é encaminhar o escopo à revisão exclusiva `shopvivaliz-human`.

## Evidência e limites atuais

- PR técnica #3530 **merged** em 2026-11-02; `apps/shopvivaliz-web-agent` está na `main`.
- Backend ativo: `always-free-arm-1787907847-26`, `shopvivaliz-web-agent.service` como usuário separado, Chromium isolado, somente `127.0.0.1:5598`.
- Testes anteriores: 16/16; Chromium real acessou `https://example.org/`, simulação de login com texto fictício comprovou bloqueio do agente durante handoff. HTTP saúde 200 e MCP não autenticado 401.
- **Sem OAuth externo, sem navegador embutido no ChatGPT, sem E2E real de sessão autenticada via MCP.** O backend local NÃO é um aplicativo ChatGPT publicado.
- Código adicional de verificação Cloudflare Access JWT foi preparado apenas em worktree técnica local e testado isoladamente; **não integrado nem ativado**. A tentativa subsequente de alteração do servidor por ferramenta remota recebeu recusa de segurança da plataforma; não contornar por outra ferramenta ou canal.
- A documentação pública do ChatGPT vigente em 2026-11-02 informa que **conexões MCP personalizadas não são suportadas nos apps móveis**. Propor fallback por navegador móvel próprio; não anunciar suporte iOS dentro do ChatGPT sem prova.

## Escopo específico solicitado para eventual grant do proprietário

1. **Sem novo Cloudflare Tunnel:** após confirmar por API/painel oficial a identidade do túnel administrativo **já existente no backend PRINCIPAL**, adicionar apenas rotas distintas do Web Agent, sem tocar em ingress de administração, Marina, Returns, Pricing ou site.
2. **MCP:** hostname dedicado proposto `mcp-web-agent.shopvivaliz.com.br` -> `http://127.0.0.1:5598/mcp`. Criar aplicação Access *separada*, deny-by-default, MFA/IdP oficial, permitir somente identidades do proprietário verificadas, Managed OAuth (PKCE), validação JWT RS256 no origin: issuer, audience, assinatura JWKS, exp/iat/nbf e email allowlist.
3. **Entrada humana:** hostname dedicado proposto `takeover-web-agent.shopvivaliz.com.br` -> somente endpoints humanos com capacidade aleatória temporária, controles independentes de autorização/antibruteforce, proteção Cloudflare apropriada, CORS restrito e TLS. **Não ativar bypass aberto nem publicar captura de tela ou senha** até revisão/negative E2E da arquitetura; se iframe exigir rota separada, avaliar explicitamente o risco e aprovar a rota exata.
4. **Mobile:** fallback HTTPS aberto no Safari/Chrome com login oficial e vinculação segura à mesma sessão, sem colocar senha/OTP/capability em mensagens ao modelo ou em logs; testar em aparelho real.
5. **Isolamento:** Chrome/Playwright sem root, `NoNewPrivileges=yes`, perfil próprio, sem copiar cookies entre perfis Dev/Atendimento. Não conceder shell/root, alterações comerciais, pagamentos, dispositivos internos, rede privada ou acesso a hosts não autorizados.
6. **Rollout reversível:** configurar Access/MCP origem antes de DNS; canário fechado para uma identidade; aplicar limites de execução/escopo, rate limiting, logs redigidos, TTL, lease e rollback só dos novos ingress. Não alterar unidades ou perfil de túnel administrativos vigentes.

## Gates obrigatórios antes de ativar a publicação

- Revisão GitHub `APPROVED` emitida **pela identidade exclusiva `shopvivaliz-human`**, na PR e SHA exato, quando exigir grant formal; checks e prova de responsabilidade/lease.
- Confirmação de Cloudflare zone/account/tunnel UUID, Access app e policy; token de administração não serve como autenticação ChatGPT.
- Testes negativos: anônimo / origin forjada / JWT inválido, audiência errada, identidade diferente, expirado, tentativa de brute-force do capability e replay depois da devolução — **todos negados**.
- Testes positivos: OAuth navegador ChatGPT web → `initialize` → `tools/list` → `web_agent_open` → widget take-over → digitar dados fictícios diretamente na UI → devolver → `web_agent_status` e ação autorizada; dados sensíveis ausentes de transcript / logs.
- Validar TOTP manual pela UI sem quebra de MFA. Validar no iPhone via fallback web externo quando ChatGPT iOS não suportar MCP personalizado.
- Persistência após restart/rollback, E2E UI real, absence of regression nas duas VMs, sem serviços concorrentes.

**Estado:** `NOT_APPROVED_FOR_EXTERNAL_INGRESS`, `CHATGPT_APP_NOT_PUBLISHED`, `MOBILE_CHATGPT_MCP_UNSUPPORTED`. Este documento não concede autorização por si só.
