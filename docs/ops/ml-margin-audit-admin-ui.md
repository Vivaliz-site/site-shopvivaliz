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

## Concorrência real: auditoria de todos x somente ativos

O backend ML Pricing (PR #73) reaproveita uma execução apenas quando escopo e **conjunto exato de IDs MLB** são iguais. Quando existe uma execução da mesma conta com outro conjunto (por exemplo, ALL incluindo anúncios pausados versus seleção apenas ativos), o gateway repassa HTTP **409 / AUDIT_ALREADY_RUNNING_DIFFERENT_SELECTION**. O painel informa em português que há outra auditoria em andamento, sem criar uma segunda execução, sem dizer que selecionados e todos são equivalentes e sem expor run IDs de outras sessões. Após o encerramento verdadeiro da execução ativa, o operador pode tentar a seleção novamente. O estado do filtro não altera retrospectivamente o escopo de uma execução em andamento.

A interface não deve ocultar o erro, ignorar a concorrência, trocar para ALL nem desabilitar os controles de execução do backend. Teste de contrato verifica o mapeamento da mensagem. Esta mudança não configura credenciais de ML Pricing nem representa um E2E autenticado.

## Diagnóstico dos dados ausentes por anúncio (05/11/2026)

A leitura de cada linha da auditoria agora inclui `missing_inputs_json` do snapshot financeiro persistido pelo ML Pricing (PR #76), além do erro explícito `MARKETPLACE_PRICE_NOT_SYNCED` quando o preço vencedor oficial não existe no catálogo. A coluna de situação mostra em português **qual informação falta**: custo do produto/embalagem/despesa variável, perfil tributário ML, tarifa ou frete do vendedor; distingue ausência de status, anúncios pausados e variações ainda não auditáveis.

A interface só **apresenta** motivos oficialmente armazenados. Nenhum campo ausente é preenchido com zero, nenhuma alíquota da Amazon é copiada para ML e não há mutação comercial. Os textos são renderizados via `textContent`, sem interpolação de dados de usuário como HTML. A planilha Excel exporta as pendências por MLB. O E2E com dados reais exige backend atualizado, credencial existente autorizada e sessão administrativa; sem isso os filtros e exports não estão aceitos como prontos.

## Simulação de novo preço, sem publicar (05/11/2026)

A tela possui formulário **Simular outro preço, sem alterar o anúncio**. Exige seleção de **exatamente um MLB ativo e sem variações**, valor BRL positivo de até duas casas decimais e até R$ 1.000.000. O gateway de sessão validada **POST /admin/mercadolivre-margin-api.php?action=preview** exige CSRF, recebe somente `item_id` e `requested_price`, vincula `account_id` a partir do segredo de runtime e chama exclusivamente **POST /v1/pricing/quote** com `quantity=1`. O token não chega ao browser. A API calcula uma cotação estimada a partir de consultas **GET** de tarifas/frete e custos já persistidos, e pode registrar **snapshot financeiro local**, mas não envia nenhuma edição ao ML ou ERP. A resposta identifica `sources.price.origin=REQUESTED_PRICE`; qualquer outra origem é rejeitada na UI.

O resultado da simulação mostra receita hipotética, custo de compra ERP, embalagem, despesas variáveis, imposto, tarifa, frete do vendedor, margem e pendências. Uma simulação `INCOMPLETE` **não** autoriza publicar preço. A simulação não substitui auditoria `ALL`, não reescreve linha existente da auditoria, não altera variáveis de ambiente e não aciona `PUT` em fornecedor algum. UI com `textContent` e proteção CSRF / session / tenant; ver teste do contrato estático. Falha fechada sem ativação já aprovada da API ML Pricing.

**Publicação futura de novo preço exige um escopo P2 próprio**, além de validação independente da correspondência do MLB com o **produto ERP exato e suas outras publicações vinculadas**, status ativo, ausência de automação, ofertas/variações, controles de concorrência, idempotência, rollback e confirmação de escrita/leitura em **ambos** os sistemas. Segundo docs oficiais vigentes, Mercado Livre usa `PUT /items/{itemId}` para preço padrão (automação ativa bloqueia a edição) e Olist V3 `PUT /produtos/{idProduto}/preco`, com estrutura de preço própria. **Nenhum desses endpoints de escrita foi liberado pelo gateway de simulação.**

Fontes: https://developers.mercadolivre.com.br/pt_br/automatizacoes-de-precos e https://api-docs.erp.olist.com/api-reference/produtos/atualizar-pre%C3%A7o-do-produto

## Bloqueio preventivo para simulador de preços antigo (06/11/2026)

**Problema identificado em E2E real:** a interface publicada oferece `Simular outro preço`, mas o backend de margens aprovado para a primeira release somente leitura (`148559...`) ainda grava `requested_price` como snapshot financeiro estimado. A correção não persistente da PR #81 usa `state=SIMULATED`, `persisted=false`, `snapshot_id=null`; o código antigo retornava `state=ESTIMATED`, criando registros hipotéticos que poderiam contaminar o histórico.

O gateway autenticado no site **primeiro consulta `GET https://pricing.shopvivaliz.com.br/v1/health` pelo mesmo canal HTTPS fixo** e exige `ok=true` mais `capabilities.pricing_requested_price_preview="simulated-no-ledger-v1"` antes de transmitir qualquer `POST /v1/pricing/quote` com preço hipotético. Se o backend estiver antigo, indisponível ou apresentar outra capacidade, ele retorna **503 `PREVIEW_BACKEND_UPGRADE_REQUIRED`**, sem emitir o POST e sem registrar simulação. O health básico sem capacidade **não** habilita o simulador.

Após o POST, o gateway exige que a resposta tenha o MLB solicitado, `state=SIMULATED`, `persisted=false`, `snapshot_id=null` e origem `REQUESTED_PRICE`. Resposta fora desse contrato retorna 502 `PREVIEW_BACKEND_CONTRACT_INVALID`; o painel mostra erro em português e jamais exibe uma cotação como válida. A API não concede operações de alteração de preço/estoque/ERP; o botão é exclusivamente simulação.

**Aceite:** teste estático do gateway, lint PHP e JavaScript, validação do login real. O simulador completo só será considerado funcional quando a release do ML Pricing que contém a PR #82 for efetivamente publicada com o grant correto e for testada por navegação autenticada. O pacote read-only atual pinado no grant #3695 **não atende a esse contrato**; o site deve falhar fechado para ele.


## Contra corrida de versões: rota de simulação exclusiva (06/11/2026)

A negociação `GET /v1/health` é necessária, mas uma alteração de release entre o GET e o POST poderia redirecionar a simulação a uma API antiga. A fim de eliminar essa condição de corrida (TOCTOU), o gateway **não chama mais `POST /v1/pricing/quote` com `requested_price`**. Passa a chamar exclusivamente `POST /v1/pricing/preview`, endpoint novo da PR backend #82, inexistente na release antiga permitida no manifesto #3695. Assim, mesmo com troca de release após o health, a API antiga retorna 404 em vez de persistir a simulação no histórico de margens.

A rota nova exige conta autenticada, mesmo escopo `pricing:read`, valor positivo explícito e retorna `state=SIMULATED`, `persisted=false`, `snapshot_id=null`; o gateway valida novamente a resposta. O botão não altera anúncio nem ERP. O teste de contrato da UI rejeita explicitamente o antigo caminho de simulação. Sem backend com essa rota, nenhum cálculo de simulação será liberado.

