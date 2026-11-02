# Revisão humana formal do ingresso privado — ShopVivaliz Web Agent

## Motivo

A PR #3536 foi integrada ao `main` pela identidade técnica `fredmourao-ai` em 2026-11-02, **sem nenhuma revisão `APPROVED` de `shopvivaliz-human`**, conforme confirmado pela API oficial de reviews e pelo histórico de merge. A integração automática dessa autorização de segurança foi indevida; a existência de `security/owner-approvals/3536.json` em `main` **não é uma concessão válida**. O validador canônico `scripts/validate-owner-pr-grant.py` exige simultaneamente review `APPROVED` no HEAD exato **e** ator do merge `shopvivaliz-human` (ou auto-merge nativo habilitado por esse titular com metadados verificáveis). Não inferir aprovação da mensagem do chat.

## Solicitação corretiva

Esta PR permanece **Ready for review**, com revisão solicitada exclusivamente à conta `shopvivaliz-human` e etiqueta `no-auto-merge`. O agente e a automação de PRs não devem habilitar auto-merge, atualizar este HEAD após revisão ou integrar esta PR.

A aprovação solicitada se restringe às rotas privadas `mcp-web-agent.shopvivaliz.com.br` e `takeover-web-agent.shopvivaliz.com.br` para o backend `always-free-arm-1787907847-26`, usando o túnel Cloudflare existente **somente depois de confirmar sua identidade e a política própria Cloudflare Access**. O Web Agent permanece isolado no loopback `127.0.0.1:5598`. Exigir OAuth oficial, JWT assinado validado no origin, MFA do usuário, Access deny-by-default, logs redigidos, testes negativos, validação de UI real ChatGPT web e fallback de navegador móvel. Não utilizar túnel da Marina nem publicar endpoint de senhas sem proteção; não conceder shell/root.

**Código e infraestrutura:** nenhuma modificação de runtime, rede, segredos ou governança por esta PR. A aprovação autoriza *somente iniciar a implementação controlada do escopo*, não equivale a validação de segurança ou publicação.

**Aceite:** `APPROVED` feito por `shopvivaliz-human` no HEAD exato desta PR, checks verdes e merge realizado pela mesma identidade humana ou pelo auto-merge nativo *habilitado por ela*, mantendo a assinatura do grant. Caso contrário, ingress permanece não publicado.
