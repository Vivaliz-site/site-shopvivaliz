# Solicitação de autorização formal — ingresso MCP Marina no Tunnel Cloudflare existente (2026-11-02)

**ESTADO: PEDIDO DE PERMISSÃO. NÃO ATIVA NENHUMA ROTA.** Vinculado à issue #3152.

## Contexto verificado
- VM produção `marina-a1-4c24g`: `shopvivaliz-cloudflared-admin.service=active/enabled`, `shopvivaliz-marina-mcp.service=active/enabled`.
- MCP local `127.0.0.1:5580/mcp` (HTTP anônimo 401, usuário de serviço non-root e `NoNewPrivileges=yes`). Release ativa ainda `a8ccd5b4904918af065efecc1150c60b25a095a0`; o código OAuth/JWT da PR técnica #3520 já foi integrado ao main, **não ativado na VM**.
- DNS `mcp-marina.shopvivaliz.com.br` ausente. O hostname administrativo `admin-marina.shopvivaliz.com.br` aponta por CNAME ao UUID `6e55e29e-7c62-4a00-ac66-8e3f9df1145f.cfargotunnel.com`, **mas a conta proprietária desse UUID não foi confirmada**.
- O GET oficial ao UUID no account investigado retornou **401**; o token existente é válido para zona, mas esse resultado não comprova permissão para gerenciar o túnel. **Não tentar obter o mesmo resultado por bypass, copiar secrets de browser ou usar outro túnel.**

## Escopo exato solicitado ao proprietário `shopvivaliz-human`
Aprovar **a autorização interna de segurança** para configurar, mediante identidade oficial com privilégios Cloudflare realmente válidos, SOMENTE um novo app Access MCP e hostname separado no MESMO Cloudflare Tunnel atualmente conectado na Marina. Isto não substitui a concessão RBAC necessária na Cloudflare.

- Recurso: `mcp:marina`
- Principal: `marina-mcp-service`
- Capacidade: `access_manage`
- Operação exata: `configure_cloudflare_mcp_ingress`
- Tunnel observado: `6e55e29e-7c62-4a00-ac66-8e3f9df1145f` **sob validação do account proprietário**; não inventar owner nem mover túnel
- Hostname isolado: `mcp-marina.shopvivaliz.com.br`; origin `http://127.0.0.1:5580`, path `/mcp` preservado
- Segurança: Cloudflare Access app próprio **deny-by-default**, Managed OAuth (consentimento oficial) e Service Auth específico para rotinas automáticas 24x7. Verificar JWT de origem (RS256/JWKS, issuer/audience/exp e principal), rejeitar token/cabeçalho malicioso, usar credenciais de serviço em secret store 0600; rotacionar/revogar pela API oficial.
- Sem concessão de root, shell remoto, alterações de worker/DB/site, DNS comercial, admin hostname, túnel extra, bypass de MFA, token admin Cloudflare na origem, nem exportação de cookies.

## Execução em fases e gates
1. Obter inventário confiável de conta proprietária/UUID pelo painel/API oficial e comprovar roles **Cloudflare Tunnel Write**, **Access: Apps and Policies Write**, **Access: Service Tokens Write** e **DNS Write** nas operações pertinentes; recusa 401 significa PAUSAR a operação negada e corrigir RBAC oficialmente.
2. Implantar a PR técnica #3520 como nova release imutável com config Access completa protegida. Testar servidor local com JWT válido/inválido e continuidade do bearer local; nenhuma liberação de escrita remota.
3. Criar app Access MCP com política restritiva e MFA para usuários, Service Auth somente token de máquina, negar todos os outros. Habilitar Managed OAuth e testar negações com app ainda sem DNS/ingress.
4. Adicionar APENAS ingresso MCP ao **mesmo** túnel e CNAME MCP no DNS; preservar `admin-marina` e todas as demais rotas. Testar HTTP 401/403 sem autenticação, OAuth, identidade de serviço e `initialize`/`tools/list` via ChatGPT real.
5. Executar regressão, reconexão sem laptop/RDC, expiração/renovação de credenciais, restart dos dois serviços, revogação, logs redigidos, e rollback **somente** da rota MCP.
6. Registrar SHA, approvals e evidências de E2E. Operações root são autorização independente por PR específica e broker protegido, não resultado deste grant.

## Limites de aprovação
Este documento e a PR são um pedido; NÃO dispensam a revisão `APPROVED` exclusiva de `shopvivaliz-human` no HEAD exato, integração pelo fluxo protegido, RBAC real Cloudflare ou políticas externas. Esta PR nunca deve ser mesclada ou habilitada para auto-merge por agente. A PR #3514 cobre somente escrita privada LOCAL já autorizada; NÃO solicitar reaprovacao dela.

Fonte canônica: `docs/ops/marina-mcp-existing-cloudflare-tunnel-2026-11-02.md`. Segurança: `docs/knowledge/security-approval-via-pr.md`.
