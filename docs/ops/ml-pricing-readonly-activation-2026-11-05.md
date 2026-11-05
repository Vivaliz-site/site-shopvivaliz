# Concessão específica de ativação ML Pricing somente leitura — 05/11/2026

## Escopo e motivo

O sistema de margens Mercado Livre existe em código no repositório `Vivaliz-site/ml-pricing-api`, mas o serviço ainda executa a release antiga `e5a41fbacd691b484fbedc9c0de8729fc2904929`. Em leitura real ao banco MySQL dedicado da Marina em 05/11/2026, foram confirmados **1 tenant, 0 ml_accounts, 0 ml_listings, 0 sku_cost_profiles e 0 auditorias**. A API pública `/v1/health` retorna HTTP 200, o que não comprova o fluxo financeiro.

A autorização de **código** foi aprovada nas PRs ML Pricing #68 (access-only MLRR) e #69 (Olist V3), sem liberar automaticamente ACLs, novos clientes API nem implantação. Uma tentativa anterior da plataforma de transferir um release archive e criar cliente API foi **explicitamente recusada**: esta solicitação P2 é uma proposta de concessão pelos mecanismos formais; **não** é uma permissão para contornar recusa vigente por outro conector, shell, SSH, CI ou token. Se o provedor mantiver a recusa após aprovação, registrar o evento exato e não executar.

## Principal, ambiente e revisão permitidos

- Operador: `marina-mcp-service` sob grant admin da Marina vigente (PR de segurança #3620), com autenticação Cloudflare Access e audit trail.
- Destino único: `marina-a1-4c24g`, serviço `ml-pricing-api`; backend central pode apenas enviar operações por MCP autenticado autorizado.
- Tenant existente no banco: `01a1a3ff-5ba4-77b1-8d60-455147e4aae0` (confirmado por SELECT somente leitura, 05/11/2026).
- Seller MLRR esperado: `112962856`, site `MLB`, sujeito a validação **independente** `GET /users/{seller}`, sem extrair token em log.
- Código ML Pricing aprovado para a primeira release: commit `1485596693d689f1c08f0c7dbf2948e525a6b5a6` (inclui PRs #66–#76). Commits posteriores exigem revisão do diff, checks verdes e nova concessão se amplificarem privilégio; P2 #77 de escrita local de custos não está incluída.

## Etapas condicionadas à revisão humana e ao provedor

1. Exigir review **APPROVED** de `shopvivaliz-human` no SHA final do PR de concessão e merge nativo com checks no `main`, sem usar o login humano em agentes. Validar validade e escopo do manifesto `security/owner-approvals/<PR>.json` e confirmações operacionais do MCP/provedor. Nenhuma alteração ao runtime é feita só pelo merge do PR.
2. Verificar lease/fencing do operador, baseline saudável, estado do serviço, migrations já aplicadas, dump/backup, rollback por symlink imutável, artefato construído de commit exato, composer.lock e SHA256. **Não** editar `current` ou release ativa; usar instalador canônico `ops/deploy/install-release.sh` apenas após autorização efetiva do publicador.
3. Autorizar **somente leitura** do snapshot de access token MLRR já rotacionado: `/home/ubuntu/shopvivaliz-deploy/shared/storage/private/ml-access-token.json`. Fonte única da renovação continua `mlrr-oauth-maintenance.timer`; nunca copiar refresh token, titularidade OAuth ou token para arquivos de release.
4. Autorizar **somente leitura** Olist V3 ao arquivo canônico `/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json`, sem copiar refresh token ou criar novo renovador. Respeitar quota compartilhada, 429, permissões reais (modo 0660 `ubuntu:www-data` somente nesse caminho) e isolamento de serviço.
5. Nas configurações privadas do **runtime ML Pricing**, sem valores em PR/log, provisionar somente `MLRR_ACCESS_TENANT_ID`, `MLRR_ACCESS_SNAPSHOT_FILE`, `OLIST_V3_TENANT_ID`, `OLIST_V3_TOKEN_STORE_FILE` para o tenant único. Não relaxar ACL nem mudar usuário de processo sem autorização separada. Provar permissões efetivas do PHP-FPM e do worker.
6. Usar o comando `app:ml:link-mlrr-snapshot --tenant-id=...` primeiro em **dry-run**; somente após retorno válido e a concessão ativa executar `--commit` com razão de auditoria, vinculando a conta de seller sem armazenar bearer.
7. Emitir cliente API **único**, para o tenant, somente com escopos `admin:sync` e `pricing:read`; preservar hash no banco e valor claro apenas no segredo privado de runtime do site, sob o canal **oficial aprovado**. É proibido expor cliente/token em logs, chat, argumentos de processos ou URLs. **Não incluir `costs:write`, `listings:write` ou permissões de preço/ERP**. Provador deve confirmar emissão/autorização; recusa explícita interrompe, não testar emissão por rota alternativa.
8. No PHP site, provisionar `ML_PRICING_ACCOUNT_ID` e `ML_PRICING_AUDIT_API_TOKEN` pelo mecanismo privado aprovado; nunca em JS, fonte, painel ou GitHub. O gateway mantém autenticação de admin e CSRF. Não exibir credenciais ou permitir acesso anônimo.
9. Enfileirar sincronização read-only de **todos** os MLBs (confrontar com total oficial, anteriormente 575), conferir 1 linha por anúncio/variação e status; sincronizar custos unitários ERP por SKU verificado, preservar `INCOMPLETE` para divergência, rate-limit e data. Registrar volume, erros e revisões. **Não inferir taxa ML do exemplo Amazon**; exigir perfil fiscal/financeiro aprovado.
10. Acionar pela **UI autenticada real**: `Auditar todos`, `Somente ativos` com `SELECTED`, progresso, estado após refresh, seleção, PDF/XLSX e reconciliação com API/banco/marketplace. Não declarar APTO antes de prova independente de valores, 51 SKU não reconciliados e todos os requisitos do usuário.

## Fora do escopo

Sem mudanças de preço, pedidos, anúncios, automação, estoque, ERP, faturamento ou MLRR OAuth. Sem `costs:write`, mesmo que P2 #77 seja aprovada separadamente. Sem rotação de refresh token nem autorização para bypass de 401/403, MFA, Cloudflare Access, aprovação GitHub, fencing/leases, recusas do provedor ou branch protection.

## Falha segura e rollback

- Ao falhar instalação: voltar ao symlink da última release saudável via procedimento autorizado; manter banco forward-migrated sem `down` destrutivo automático.
- Reverter **apenas** as quatro variáveis da integração/segredos runtime do serviço recém-ativadas, revogar o cliente API por operador autorizado, sem interromper MLRR/timers existentes.
- Manter checkpoint `NAO_APTO` até UI E2E e conciliação financeira reais. Uma PR aprovada, health 200, CI verde ou ausência de erro aparente não são aceite de operação.
