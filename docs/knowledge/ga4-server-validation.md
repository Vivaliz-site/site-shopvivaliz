# GA4: configuracao e validacao server-side

## Estado comprovado em 2026-10-04 (Brasilia)

A credencial OAuth existente funciona. A Admin API retornou HTTP 200 para o stream `properties/439468688/dataStreams/8111377243`, measurement ID `G-1H55K1TZ5D`, e confirmou uma chave Measurement Protocol existente. Nao e necessario criar outro login, token ou chave por suposicao. A consulta de metadados usou apenas campos nao secretos.

O runtime de producao ainda reportou `GA4_SECRET` ausente. A gravacao dessa chave foi explicitamente bloqueada pela plataforma nesta conversa; ela nao foi realizada e nao deve ser roteada por outro transporte/agente para contornar o bloqueio. O PR #2703, que removeu o GTM legado, e uma correcao independente ja publicada.

## Etapa pendente exata

Um operador ou mecanismo de provisionamento autorizado precisa vincular a chave JA EXISTENTE do stream acima a `GA4_SECRET` no armazenamento persistente de producao (`shopvivaliz-free-a1`). A capacidade atual de chat nao expoe uma acao write-only por referencia para esse provisionamento. Nao enviar o valor no chat, em argumentos CLI, logs, arquivos versionados ou screenshots.

O repositorio possui `scripts/update-production-env.py`, que aceita `GA4_SECRET` entre as configuracoes estaticas e recebe JSON por stdin. Sua existencia nao concede permissao para contornar o bloqueio da plataforma e nao significa que a chave foi instalada. Preservar todas as demais chaves, permissoes, ownership e o guard monotonic; nunca editar `current/` ou a release ativa.

## Validacao sem contaminar as vendas

1. Executar `php scripts/validate-tracking-config.php`. E um diagnostico somente leitura: nao inclui o remetente de compras, nao faz rede, nao escreve configuracao e nao imprime secrets. Exit 0 significa somente estrutura presente; exit 1 significa configuracao incompleta/invalida. `PURCHASE_DELIVERY=NOT_VERIFIED` permanece explicito.
2. Validar o payload no endpoint oficial `/debug/mp/collect`, com `validation_behavior=ENFORCE_RECOMMENDATIONS`. Os eventos desse endpoint nao entram nos relatorios. Resposta vazia de erros nao autentica a chave: o validador do Google nao valida `api_secret`.
3. Confirmar uma compra genuina aprovada no fluxo do site e observar o mesmo `transaction_id`, valor, itens e identidade da sessao no GA4; conferir a atribuicao/importacao no Google Ads. Nao fabricar pedidos, pagamentos ou conversoes em producao.
4. Somente depois avaliar retomada de anuncios com margem, CAC maximo e preco entregue comprovados. Tracking correto nao garante demanda ou vendas.

Nunca executar o validador antigo: ele tentava enviar `purchase` sintetico por `sendPurchaseEventGA4`, inclusive com configuracao incompleta. A regressao `tests/test_tracking_config_readonly.py` executa o CLI real em fixture sem rede e impede o retorno dessa chamada.

## Referencias oficiais

https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties.dataStreams.measurementProtocolSecrets/list
https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events
https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference

## Limite da evidencia do diagnostico

A ausencia de rede e uma propriedade do caminho de codigo revisado, nao uma metrica de captura de pacotes: a saida usa `NETWORK_POLICY=NO_REQUESTS_BY_DESIGN`. O bootstrap canonico executa `runtime-secrets.php`, que deve continuar sendo apenas um retorno de configuracao, sem efeitos colaterais. Os testes exercitam tanto `.env` quanto esse retorno de array em fixtures, com wrappers e funcoes de rede desabilitados. Nao ha carregamento do remetente de compras.
