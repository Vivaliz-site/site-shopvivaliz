# Auditoria de credenciais e runtime Shopee — 2026-08-14

## Escopo

Auditoria executada após agentes autônomos registrarem repetidamente que `SHOPEE_*`, `TINY_*` e `OLIST_*` não estavam disponíveis, apesar de o runtime de produção manter credenciais operacionais.

Nenhum valor de secret é documentado aqui; somente presença/ausência e arquitetura de consumo.

## Evidência consolidada

A evidência redigida mais recente do workflow `Credential Presence Audit` (run `31451958622`, 2026-08-11) mostrou:

### GitHub Actions / environment

- Shopee:
  - `SHOPEE_PARTNER_ID`: presente
  - `SHOPEE_PARTNER_KEY`: presente
  - `SHOPEE_SHOP_ID`: presente
  - `SHOPEE_ACCESS_TOKEN`: ausente
  - `SHOPEE_REFRESH_TOKEN`: ausente
- Olist:
  - `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET`, `OLIST_ACCESS_TOKEN`, `OLIST_REFRESH_TOKEN`: presentes
- Tiny:
  - `TINY_CLIENT_ID`, `TINY_CLIENT_SECRET`, `TINY_ACCESS_TOKEN`, `TINY_REFRESH_TOKEN`: presentes
- `TOKEN_API_OLIST`: presente

### VM de produção

Em `/home/ubuntu/shopvivaliz-deploy/shared/.env`, no `.env` da release ativa e pelo loader `config/constants.php`:

- `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_SHOP_ID`, `SHOPEE_ACCESS_TOKEN`, `SHOPEE_REFRESH_TOKEN`: presentes
- `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET`, `OLIST_ACCESS_TOKEN`, `OLIST_REFRESH_TOKEN`: presentes
- `TINY_CLIENT_ID`, `TINY_CLIENT_SECRET`, `TINY_ACCESS_TOKEN`, `TINY_REFRESH_TOKEN`: presentes
- `TOKEN_API_OLIST`: presente

`shared/runtime-secrets.php` também contém as chaves canônicas Shopee/Olist/Tiny.

## Causas raiz encontradas

1. **Diagnóstico no contexto errado.** Os ciclos autônomos estavam tratando `env` vazio no sandbox do agente como se significasse ausência de secrets no projeto inteiro. Isso é falso: sandbox, GitHub Actions e VM são contextos diferentes.

2. **Dependência histórica superada.** A documentação repetia que a rotina Shopee dependia de `fetch-shopee-listings.yml` e `optimize-shopee-listings.yml`, removidos em 2026-07-26. O executor atual `scripts/shopee_production_seo_apply.py` usa diretamente `scripts/utils/shopee_client.py` e não depende de Tiny/Olist para ler/alterar o catálogo Shopee.

3. **Workflow de produção consumia o local errado.** `shopee-production-seo.yml` validava e usava `SHOPEE_ACCESS_TOKEN`/`SHOPEE_REFRESH_TOKEN` somente via GitHub Actions. Esses tokens rotativos estavam ausentes no Actions, embora presentes na VM. Assim, o workflow falhava antes de chegar ao executor real.

4. **Faltava health check operacional recorrente.** Havia auditoria de presença, mas não uma prova periódica de leitura real da API Shopee a partir do runtime canônico.

## Correções implementadas

- `scripts/shopee_runtime_exec.py`: carrega somente `SHOPEE_*` do `shared/.env`, sem imprimir valores.
- `scripts/shopee_runtime_preflight.py`: prova presença das credenciais e leitura real de catálogo/detalhe, sem mutar listings.
- `.github/workflows/shopee-runtime-health.yml`: roda a cada 6 horas e após deploy de produção bem-sucedido, usando o runtime real da VM.
- `.github/workflows/shopee-production-seo.yml`: passa a executar o apply na VM, onde os tokens rotativos canônicos vivem; mantém confirmação humana explícita, backup, read-back e evidência.
- `docs/POLITICA-PR-AGENTES.md`: proíbe inferir ausência de secret a partir do sandbox e define a fonte de verdade Shopee.
- testes adicionados ao `Shopee Optimizer Safety` para o loader/preflight.

## Outros grupos observados na mesma auditoria

A auditoria de presença não permite afirmar que todas as integrações do projeto estão operacionais. Ela mostrou lacunas fora de Shopee/Olist/Tiny que devem ser tratadas por seus próprios fluxos, sem misturar com esta correção:

- Mercado Livre: credenciais de client estavam parcialmente presentes, mas tokens de acesso/refresh não foram comprovados no runtime auditado.
- Amazon: credenciais LWA estavam presentes no GitHub Actions, mas não materializadas no runtime da VM auditado.
- TikTok: app key/secret estavam parcialmente presentes no GitHub Actions, mas tokens/shop context não foram comprovados no runtime auditado.

Esses estados são **presença de configuração**, não prova de funcionamento de API.

## Lacuna que permanece

O repositório ainda não possui uma integração comprovada com métricas de performance/analytics da Shopee (CTR, conversão, vendas por SKU) usada pelo otimizador. Portanto, otimização de conteúdo pode operar sobre catálogo real, mas decisões baseadas em CTR/conversão não devem ser inventadas. Esse ponto deve permanecer separado da disponibilidade de credenciais e do funcionamento da API de catálogo.

## Regra de interpretação a partir desta auditoria

- `env` vazio no sandbox => somente `sandbox_sem_secret`.
- Secret presente em Actions => `actions_secret_presente`, não prova de validade.
- Secret presente na VM => `runtime_secret_presente`, não prova isolada de validade.
- `Shopee Runtime Health` com leitura real bem-sucedida => `runtime_shopee_comprovado`.
- Falha de API com secret presente => credencial/conectividade inválida deve ser diagnosticada pelo erro real, nunca convertida automaticamente em “secret ausente”.
