# Reemissão de autorização humana — Web Agent / Cloudflare Access (2026-11-02)

**Classificação P2 — SOLICITAÇÃO SEM EFEITO OPERACIONAL.** Reemite exclusivamente a decisão de proprietário descrita em [docs/ops/web-agent-chatgpt-private-ingress-approval-2026-11-02.md](web-agent-chatgpt-private-ingress-approval-2026-11-02.md). Não implementa, ativa nem publica ingress, DNS, OAuth, MCP externo ou fallback HTTPS.

## Por que reemitir

A PR protegida [#3536](https://github.com/Vivaliz-site/site-shopvivaliz/pull/3536), HEAD `d0a146f09bed365882fdfc7559142d49289439aa`, entrou em `main` como merge `efaa0e6047c5e9f6cd5d569bc1ca4648aaaa55c1` em 2026-11-02, porém a consulta oficial `list_pull_request_reviews` retornou **zero revisões**. Os três workflows para aquele HEAD concluíram com sucesso, mas check verde e merge **não são** review APPROVED de `shopvivaliz-human`. O arquivo `security/owner-approvals/3536.json` presente na `main` é **NÃO AUTORIZADO**; não consumir, promover, nem usar para publicação. O verificador canônico `scripts/validate-owner-pr-grant.py` deve negar a ausência de review no HEAD e o merge sem proprietário. Não transformar o merge irregular em aprovação retroativa.

A PR #3536 já está merged/closed, de modo que uma nova PR protegida é necessária para receber decisão humana auditável sobre o escopo. Esta nova PR deve adicionar `security/owner-approvals/<numero-da-nova-PR>.json` exclusivamente; a PR #3536 continua inválida.

## Escopo proposto (idêntico ao #3536, sem poderes adicionais)

- Host: `always-free-arm-1787907847-26`; principal `shopvivaliz-web-agent-service`; origem `http://127.0.0.1:5598`.
- Reutilizar **somente** o túnel Cloudflare administrativo existente no backend, depois de comprovar sua identidade pela API/painel oficial; não criar túnel novo nem alterar ingress administrativo.
- Novos hostnames específicos `mcp-web-agent.shopvivaliz.com.br` e `takeover-web-agent.shopvivaliz.com.br`, com Access isolado, deny-by-default, MFA/IdP oficial, OAuth compatível com ChatGPT e JWT verificado no origin.
- Handoff de login via interface segura com capability de curto prazo; sem senhas/OTP no ChatGPT ou logs; fallback HTTPS independente para iPhone.
- Sem shell/root, sem compartilhamento de cookies/perfis, sem acesso à rede privada nem alterações comerciais/financeiras.
- Lease/fencing, prevenção de DNS rebinding, testes negativos e E2E real obrigatórios **antes de qualquer exposição**. Respeitar a recusa explícita anterior do provedor quanto à alteração da autenticação; nenhuma PR substitui permissão da plataforma.

## Gate vinculante

1. Somente `shopvivaliz-human` emite revisão `APPROVED` no **HEAD exato desta nova PR**; depois faz merge pessoal ou habilita auto-merge nativo pessoal, após checks. Nunca por agente.
2. Verificar no runtime o grant exato via `scripts/validate-owner-pr-grant.py`, identidade de quem aprovou e integrou, escopo, TTL e lease; continuar negando o grant #3536.
3. Obter eventual permissão externa independente pelo caminho oficial. Recusa explícita significa **NÃO PUBLICAR**.
4. Publicar somente após validações JWKS/issuer/audience/email/exp, isolamento, CORS/CSP, rate limits, negativas de anônimo/JWT/capability e E2E ChatGPT web com credenciais fictícias. Validar mobile separado, sem confundir health local com app publicado.

**Estado atual:** `OWNER_GRANT_NOT_APPROVED` / `EXTERNAL_INGRESS_DISABLED`. É proibido marcar `CONCLUIDO` antes de E2E verdadeiro, auditável e autorizado.
