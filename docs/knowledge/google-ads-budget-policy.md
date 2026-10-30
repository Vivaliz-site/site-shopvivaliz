# Política vigente de orçamento do Google Ads

## Autorização do proprietário em 29/10/2026

A operação autorizada para a conta Google Ads `528-309-1103` é **uma campanha Vedante ativa, com orçamento diário médio de R$ 5,00**, mantendo as outras três campanhas pausadas.

É proibido aumentar o orçamento enquanto não houver vendas reais com pagamento aprovado. Mesmo após uma venda aprovada, qualquer aumento exige **nova autorização explícita do proprietário**. Uma conversão registrada no Google Ads ou no GA4, isoladamente, não substitui a confirmação do pedido e do pagamento. Pedidos de teste, pendentes, recusados ou cancelados não satisfazem essa condição.

O valor de R$ 5,00 é o orçamento diário **médio** configurado no Google Ads; este documento não o apresenta como limite rígido do gasto de cada dia. Não criar campanha adicional, reativar outra campanha, adotar orçamento compartilhado que amplie esse total ou executar um otimizador capaz de aumentar despesas sem autorização específica.

A autorização posterior para otimizar o projeto para vendas preserva este limite e exclui alterações de preços e a geração de novos custos. Não modificar preços, descontos, frete ou regras comerciais com base nesta política.

## Configuração observada na interface em 29/10/2026

O responsável pela execução verificou a conta na interface autenticada do Google Ads e recarregou a tela após a alteração autorizada:

| Item | Estado observado |
| --- | --- |
| Campanha Vedante | Ativada; qualificada |
| Orçamento da campanha Vedante | R$ 5,00 por dia, como média diária |
| Demais campanhas | Três campanhas pausadas |
| Total de orçamento das campanhas ativadas | R$ 5,00 por dia |
| Rede | Pesquisa; expansões para parceiros de pesquisa e Display desativadas |
| Opção de localização | Presença |
| Estratégia | Maximizar cliques, limite de CPC de R$ 0,80 |
| Aplicação automática de recomendações | 0 de 7 e 0 de 14 selecionadas |
| Regras automatizadas do Google Ads | Nenhuma regra exibida |
| Scripts do Google Ads | Nenhum script exibido |

As telas de recomendações, regras e scripts foram consultadas sem alterações. Esses resultados descrevem a interface do Google Ads naquele momento; não comprovam a inexistência de rotinas externas no servidor, no CI ou em outros serviços.

## Rotinas externas e materiais antigos

O responsável pela execução realizou uma inspeção somente leitura no Marina em 29/10/2026: a listagem de timers e de unidades ativas, filtrada por nomes relacionados a Google Ads, campanhas e marketing, não retornou correspondências; tampouco foram encontradas correspondências no crontab do usuário e nos nomes consultados em `/etc/cron.d`. Essa é uma inspeção limitada por host, usuário e filtros. Ela não certifica a ausência global de automações externas, rotinas com nomes genéricos, tarefas de outros usuários, agendamentos no backend ou workflows de CI.

O responsável também validou o hostname da backend e repetiu a inspeção via MCP: timers/unidades filtrados sem correspondências; crontab de `ubuntu` consultado com exit 0 e sem correspondências; crontab de root ausente, exit 1; nomes relacionados a Ads em `/etc/cron.d` sem correspondências. A cobertura permanece limitada aos filtros e superfícies consultados.

Uma chamada anterior do revisor ao Marina não retornou resultado e não fornece evidência adicional. Não declarar cron, serviços, timers ou automações externas desativados com base apenas na ausência de regras e scripts dentro do Google Ads.

`scripts/google_ads_campaign_live_ready.json` descreve uma configuração histórica da campanha Vasos com orçamento de R$ 10,00 por dia. Ela não representa a autorização vigente para a campanha Vedante. A guarda histórica de R$ 12,00 sem conversões existe no `main()` do status V1; ela não é executada pelo fluxo V3 → V2 usado no workflow periódico. A existência de campos de pausa no JSON não comprova que um executor ativo os aplique.

### Caminhos mutáveis identificados no repositório

| Caminho | Comportamento na base revisada |
| --- | --- |
| `.github/workflows/google-ads-antique-decore-status.yml` | Define agendamento nos minutos 17 e 47 de cada hora e gatilho por alterações de arquivos específicos no `main`; executa `google_ads_antique_decore_status_v3.py`. |
| `scripts/google_ads_antique_decore_status_v3.py` → `google_ads_antique_decore_status_v2.py` | O V2 chama `legacy.ensure_budget` para forçar a campanha Vasos a R$ 10,00 e pode reativar campanha, grupos e anúncios. Valida a configuração de compra, mas não exige vendas reais aprovadas nem executa a guarda de gasto sem conversão do V1. |
| `.github/workflows/google-ads-core-recovery.yml` → `scripts/google_ads_operator_core_recovery.py` | Por gatilho em arquivos específicos no `main` ou execução manual, pode reativar a campanha Vasos `24144257692` com o orçamento histórico de R$ 10,00. |
| `scripts/google_ads_antique_decore_automation.py` | Embora o comentário inicial diga que o padrão é somente leitura, o `main()` chama o monitor V1 mutável. Não usar como diagnóstico somente leitura. |
| `.github/workflows/sales-round-01-google-ads-delivery.yml` → `scripts/google_ads_sales_round1_delivery.py` | Por gatilho em arquivos específicos no `main` ou execução manual, altera CPC e ativa palavras-chave de Vasos antes da verificação posterior; aceita a campanha pausada, desde que o orçamento histórico seja R$ 10,00. Não muda o orçamento nem ativa a campanha por si só. |

Esses comportamentos foram constatados pela leitura do código anterior à contenção. Eles são incompatíveis com a autorização atual e não devem ser executados ou reativados.

### Contenção aplicada no GitHub Actions em 29/10/2026

O responsável desativou manualmente os três workflows na interface autenticada do GitHub. Após recarregar cada página, confirmou a mensagem de desativação manual e o botão `Enable workflow`:

- `Google Ads Antique Decore Status`: desativado. A última execução agendada exibida foi a [run 40842981788](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/40842981788), em 29/10/2026 às 08:33 UTC.
- `Google Ads Core Recovery`: desativado. A última execução exibida foi a [run 34150482482](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/34150482482), em 07/09/2026.
- `Sales Round 01 Google Ads Delivery`: desativado. A última execução exibida foi a [run 34150482436](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/34150482436), em 07/09/2026.

A existência dessas execuções não prova que tenham conseguido alterar a conta; os resultados de mutação de cada run não foram reconciliados nesta revisão.

### Contenção dos executores nesta revisão

Os entrypoints V1, V2, V3, adapter, recuperação e Sales Round 01 passam a recusar execução com exit 2 e `NO_CHANGES_MADE` antes de carregar cliente ou consultar a API. Os helpers de alteração de orçamento/status e de renomeação de conversão também recebem a guarda, impedindo seu uso direto para contornar o bloqueio dos entrypoints. Não há variável de ambiente ou flag para reutilizar uma autorização antiga.

Os workflows de status, recuperação e Sales Round 01 perdem os gatilhos automáticos; o único gatilho retido é manual e executa o entrypoint bloqueado, sem segredos de Ads, instalação do SDK ou ambiente `Production`. A CI estática executa regressões com rede e clientes simulados. Os testes verificam a recusa sem construir cliente, abrir rede ou emitir operações de mutação, inclusive com a flag antiga de renomeação.

A revisão adicional do snapshot `d70d204027d8b673d43764357693dd396010b9eb` identificou que `sales-round-01-google-ads-delivery.yml` alterava CPC e ativava palavras-chave de Vasos antes de chamar o adapter de verificação. O script não exigia a campanha ativada, portanto a pausa de Vasos não impedia essas alterações. A recusa foi colocada no próprio `google_ads_sales_round1_delivery.py`, antes da API, e não somente na verificação posterior. Essa rotina não alterava o orçamento nem ativava a campanha por si só.

Essa proteção de código exige que a revisão seja integrada e publicada nos executores aplicáveis. Até essa validação, a desativação manual confirmada no GitHub é a contenção imediata comprovada. Não reativar os workflows como parte da integração.

Materiais antigos que mencionem R$ 10,00 ou R$ 15,00 por dia, retomada automática, ampliação de orçamento ou criação de campanhas não autorizam sua execução. O ponto de entrada legado `scripts/google_ads_campaign_automation.py` foi encontrado como desativado na revisão consultada; isso não substitui a inspeção de outros executores ou dos agendamentos reais.

Não executar criadores, seeders de missões, instaladores, scripts de ativação ou otimizadores antigos para tentar restabelecer a operação. Não reiniciar ou habilitar rotinas de Ads como consequência desta documentação.

## Verificação e tratamento de divergências

Antes de qualquer intervenção futura, conferir na conta correta o orçamento da campanha Vedante, o total das campanhas ativadas, o estado das outras três campanhas e a existência de automações capazes de alterar esses valores. Consultas e relatórios devem preservar os dados sensíveis.

Uma rotina de monitoramento não recebe, por este documento, autorização para alterar campanhas, aumentar orçamento ou gerar custos. Caso encontre configuração divergente, ela deve preservar a evidência e encaminhar a correção ao fluxo autorizado existente, sem criar um executor concorrente.

Qualquer proposta de aumento deve identificar as vendas reais aprovadas que a motivam, a margem de contribuição atual comprovada, o custo de aquisição medido e o novo valor proposto. A margem histórica de R$ 5,50 por venda não deve ser tratada como margem atual verificada.

Esta política prevalece sobre valores e autorizações históricas incompatíveis. Mudanças posteriores exigem registro da nova instrução explícita do proprietário e validação da configuração efetivamente salva.

## Referências da revisão

As verificações de código foram realizadas na base `63627aee3ac052c349eb84562c4774c93df37783`: [entrada legada desativada](https://github.com/Vivaliz-site/site-shopvivaliz/blob/63627aee3ac052c349eb84562c4774c93df37783/scripts/google_ads_campaign_automation.py), [configuração histórica de Vasos](https://github.com/Vivaliz-site/site-shopvivaliz/blob/63627aee3ac052c349eb84562c4774c93df37783/scripts/google_ads_campaign_live_ready.json), [workflow periódico](https://github.com/Vivaliz-site/site-shopvivaliz/blob/63627aee3ac052c349eb84562c4774c93df37783/.github/workflows/google-ads-antique-decore-status.yml), [monitor V2 mutável](https://github.com/Vivaliz-site/site-shopvivaliz/blob/63627aee3ac052c349eb84562c4774c93df37783/scripts/google_ads_antique_decore_status_v2.py) e [recuperação de Vasos](https://github.com/Vivaliz-site/site-shopvivaliz/blob/63627aee3ac052c349eb84562c4774c93df37783/scripts/google_ads_operator_core_recovery.py). As observações da conta e do Marina foram fornecidas pelo responsável por sua execução durante a auditoria de 29/10/2026; não foram deduzidas dos arquivos históricos.
