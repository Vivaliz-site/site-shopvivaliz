# Mercado Livre — painel administrativo de auditoria de margens

## Endpoints e segurança

- \`/admin/mercadolivre-margin.php\`: interface com seleção individual de anúncios MLB, auditoria de todos, progresso, classificação de margem, exportação PDF e Excel.
- \`/admin/mercadolivre-margin-api.php\`: gateway somente HTTPS com destino fixo \`https://pricing.shopvivaliz.com.br\`. O navegador nunca recebe token ML Pricing. A autenticação de usuário continua a sessão existente de \`includes/admin-guard.php\`; \`POST\` exige CSRF.
- Não há qualquer alteração de preço/estoque/anúncio/ERP. Os custos de frete do vendedor, imposto e comissão são mostrados separadamente quando o cálculo estiver completo, sem completar números ausentes com zeros.
- Cada linha representa um item \`MLB\`; não agrupa anúncios com o mesmo SKU do ERP.
- Uma sessão só pode ler/exportar o run criado nela para o \`ML_PRICING_ACCOUNT_ID\` configurado. O backend aplica isolamento adicional por tenant e escopos.

## Ativação (não feita por esta PR)

A interface **falha fechada** com \`ML_MARGIN_NOT_CONFIGURED\` se o runtime não fornecer:
- \`ML_PRICING_ACCOUNT_ID\`: UUID de conta ML Pricing associado ao vendedor do tenant aprovado;
- \`ML_PRICING_AUDIT_API_TOKEN\`: token de cliente existente e autorizado, com \`admin:sync\` + \`pricing:read\`, disponibilizado por via privada ao runtime PHP, sem colocá-lo em URLs, JavaScript, repositórios, logs ou páginas.

A criação/concessão de API client, alteração de secrets, nova permissão para ler snapshot externo e publicação do serviço exigem os controles P2 vigentes (aprovação formal da conta humana exclusiva quando aplicável). Uma falha HTTP 401/403 no backend **não** justifica um token alternativo nem acesso direto ao banco.

## Dependências para operar com números reais

1. ML Pricing versão com paginação \`search_type=scan\` implantada (PR #67).
2. Conta vendedora ligada com OAuth autorizado e catálogo sincronizado integralmente.
3. Custos efetivos ERP Olist/Tiny V3 por SKU, correspondência exata/única e impostos atribuídos por anúncio (não assumir a alíquota Amazon).
4. Cotações de preço/taxa e custo de frete vendedor oficiais do Mercado Livre.
5. Api client/grant válido do serviço ML Pricing.

Se faltar qualquer uma das fontes, a auditoria indica \`INCOMPLETE\`, não uma margem fictícia. Itens com variações comerciais próprias são classificados como pendentes de cotação por variação até que o motor ofereça esse contrato.

## Validação

- \`php -l admin/mercadolivre-margin.php\`
- \`php -l admin/mercadolivre-margin-api.php\`
- \`php tests/ml-margin/MlMarginAdminSourceContractTest.php\`
- Regressão das telas \`/admin/mercadolivre.php\` e da auditoria Amazon existente.
- **Gate de interface real:** entrar no admin publicado com identidade autorizada, navegar pelo link, acionar \`Auditar todos\` e \`Auditar selecionados\`, observar aumento de progresso, mudar filtros, atualizar a página, confirmar persistência do run, exportar PDF e XLSX, inspecionar documentos e reconciliar cada linha com backend e fonte oficial. Não considerar APTO somente por testes/CI/HTTP 200.
- Sem conta/API client real, validar apenas estado seguro \`ML_MARGIN_NOT_CONFIGURED\` e manter E2E como PENDENTE.

## Operação

A tela consulta \`/v1/listings\` e lê \`/v1/admin/audits/margins\` somente com a conta indicada pelo servidor. Polling existe apenas enquanto há execução e o navegador estiver aberto. Nenhuma rotina persistente nova é instalada. Erros e falta de integração são exibidos na página; o botão não é marcado como bem-sucedido antes de receber \`run_id\` válido do backend.

## Status oficial e opção «Somente ativos» (04/11/2026)

A leitura integral do vendedor autorizado via API Mercado Livre retornou **575 anúncios**: **453 `active`**, **111 `paused`** e **11 `under_review`**. Por padrão o botão **Auditar todos** preserva o comportamento de todos os 575. Com a opção **Somente ativos** marcada, o painel apresenta somente `status === 'active'` comprovado pela API backend e **Auditar todos os ativos** envia `scope='SELECTED'` com a lista individual dos IDs ativos autorizados (até o limite server-side de 20.000). Jamais enviar `ALL` nesse modo, porque `ALL` inclui pausados.

A API `/v1/listings` precisa entregar `status` por MLB a partir do valor oficial de `items/bulk.body.status` (PR #71 de `ml-pricing-api`). `status=null` significa catálogo anterior não sincronizado, nunca `active` inferido. Uma lista antiga sem status produz **zero elegíveis** e deixa botão bloqueado nesse filtro; não oculta esse bloqueio preenchendo valores fictícios. Ao ativar o filtro ou atualizar o catálogo, a seleção de anúncios que já não estão ativos é removida. A coluna *Status ML* é distinta de *Situação da auditoria* e nunca mistura pausado com margem incompleta.

Uma auditoria já em execução na mesma conta é reaproveitada pelo backend por idempotência mesmo que o filtro de status tenha sido alterado; verificar o `scope` real da execução antes de interpretar o resultado. **Não** publicar resultado como auditoria somente de ativos quando o backend reutilizou uma execução anterior de todos os anúncios.

Este filtro é aditivo/read-only; não autoriza preços, estoque, anúncios, compras ou alterações no ERP. O aceite exige implantação comprovada do backend (PRs #67/#68/#69/#70/#71), vínculo MLRR e custos V3 com grant real, autenticação UI e E2E nos botões na sessão do administrador, testes de report PDF/XLSX e reconciliação dos 453 anúncios ativos. Sem esses elementos, permanecer `NAO_VALIDADO_UI`.
