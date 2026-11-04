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
