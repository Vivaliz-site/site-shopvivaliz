# Renovação pontual de manutenção de permissões ChatGPT — 07/11/2026

## Evidência e causa
O proprietário solicitou a configuração global **Permitir todas as ações / Nunca perguntar**. A leitura oficial atual (`get_app_permissions`) retorna **Allow low-risk actions** com **Use my default** para os MCPs ShopVivaliz Central e Orquestrador. As tentativas oficiais de alteração global e por aplicativo retornaram **HTTP 404 Action not found antes da execução**, sem mutação; o readback confirmou que o modo não foi alterado.

A aprovação humana prévia, PR #3497, foi autenticada e mesclada em 02/11/2026, mas seu grant de uso único foi consumido às 23:31:15Z de 02/11, expiração do lease às 23:34:15Z; ele não pode ser reutilizado. Um novo grant exige nova aprovação formal exclusiva.

## Escopo exato desta renovação
- Renovar uma única manutenção por interface **oficial** do ChatGPT no host **Fred-Win**, principal técnico `shopvivaliz-central-mcp`, com validade curta e lease exclusivo de até 180 segundos.
- Usar somente `desktop_open`, `desktop_click`, `desktop_type` dentro das configurações oficiais de permissões de plugins, exclusivamente para a opção global `full_access` se ela estiver disponível.
- Reutilizar o emissor canônico `scripts/ops/authorize-chatgpt-plugin-permissions-lease.py` e o verificador existente; nunca emitir sem PR aprovada no HEAD exato por `shopvivaliz-human`, merge oficial, fonte limpa igual ao `main` e runtime lock sem concorrência.
- Depois do ajuste, verificar readback independente `get_app_permissions` nos MCPs e GitHub; registrar evidências de tela e auditoria; liberar lock.
- Falha 404, opção indisponível na UI, ausência de grant, lease ocupado ou recusa explícita do provedor: não contornar. Reportar bloqueio externo e preservar configuração atual.

## Fora do escopo
Sem mudança de MFA, RBAC, OpenAI/Cloudflare, segredos, shells/root, API privada, branch protection, aprovações comerciais, SAFE-T, outros hosts, conversas ou políticas de terceiros. Esta PR é apenas autorização de manutenção pontual — **não altera automaticamente nenhuma permissão no ChatGPT**.

Fonte: `docs/ops/fred-win-chatgpt-plugin-permissions-lease-2026-11-01.md` e PR #3497.
