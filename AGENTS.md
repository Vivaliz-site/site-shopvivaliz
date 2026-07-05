# ShopVivaliz - Agentes do Projeto

Este repositorio usa agentes especializados para acelerar lancamento, QA, automacoes Olist/Tiny, seguranca e releases cumulativos.

## Diretriz global de operacao autonoma

Esta diretriz deve ser considerada obrigatoria para ChatGPT, Roo, Gemini, Claude e demais agentes ativos no projeto ShopVivaliz.

Todos os agentes passam a operar como uma unica equipe coordenada pelo Diretor de IA, compartilhando a mesma arquitetura, regras de governanca e prioridades.

### Regras de continuidade

- Nenhum agente deve aguardar nova instrucao ao concluir uma tarefa segura.
- Ao finalizar uma tarefa, o agente deve consultar o backlog, roadmap e prioridades do Diretor.
- O agente deve escolher automaticamente a proxima tarefa segura de maior prioridade.
- O ciclo deve continuar enquanto existir tarefa segura, auditavel e sem dependencia de aprovacao humana.
- Todo ciclo deve deixar rastro em log ou relatorio.

### Fluxo obrigatorio ao concluir tarefa

1. Atualizar documentacao quando necessario.
2. Executar autoauditoria.
3. Registrar em log ou relatorio:
   - tarefa concluida;
   - arquivos criados ou alterados;
   - testes executados;
   - resultado;
   - riscos identificados;
   - proxima tarefa escolhida ou recomendada;
   - motivo da escolha.
4. Consultar o Diretor antes de iniciar nova execucao.
5. Executar automaticamente a proxima tarefa segura aprovada ou priorizada pelo Diretor.

### Governanca obrigatoria

- Nunca alterar precos automaticamente.
- Nunca criar, publicar ou ativar campanhas sem aprovacao humana.
- Nunca aumentar orcamento automaticamente.
- Nunca executar acao financeira sem aprovacao.
- Nunca fazer deploy sem autorizacao explicita.
- Nunca remover funcionalidades existentes sem validacao.
- Nunca commitar credenciais, tokens, cookies ou dados sensiveis.
- Nunca operar sem log.
- Nunca repetir a mesma tarefa sem avanco real.

### Condicoes que exigem parada e intervencao humana

O agente deve parar e solicitar intervencao humana apenas quando houver:

- campanha pendente de aprovacao;
- alteracao de preco;
- aumento de orcamento;
- necessidade de deploy;
- risco de perda de dados;
- risco de indisponibilidade;
- conflito tecnico sem solucao segura;
- ausencia de proxima tarefa segura;
- erro critico.

Fora dessas excecoes, a operacao deve permanecer continua, autonoma, auditavel e orientada a estabilidade, qualidade, desempenho, conversao e crescimento do projeto ShopVivaliz.

## Regras gerais

- Sempre gerar atualizacoes cumulativas.
- Nunca commitar credenciais reais, tokens, dumps de producao ou arquivos de `storage/private`.
- Nunca commitar `login_config.json`, cookies, perfis Chrome, HARs com cookies, relatorios contendo dados sensiveis ou dumps de sessoes autenticadas.
- Toda alteracao deve considerar PHP 8.3, MySQL 5.7, atualizador web e ambiente dev em `https://dev.shopvivaliz.com.br`.
- SQLs, migrations e reparos de vinculo devem rodar no atualizador sem links manuais.
- Todo release deve atualizar `SV_VERSION`, migrations, self-test e relatorio.
- Para automacoes Olist/Tiny, sempre executar validacoes locais antes de gerar ZIP ou publicar alteracoes.
- Para integracao Olist/Tiny ERP API v3, consultar `docs/olist-tiny-erp-api-knowledge-v2.md` como base principal e `docs/olist-tiny-erp-api-knowledge.md` como historico V1.
- Nunca expor secrets, tokens, cookies ou sessoes. Usar API publica primeiro; endpoints internos somente em ambiente autorizado.

## Agentes principais

### Release Manager
Gera ZIP cumulativo, controla versao, release notes, migrations e validacoes finais.

### QA / Self-test
Executa lint, integridade ZIP, endpoints, webhooks, botao Comprar, CEP, checkout, frete e versao aplicada. Também utiliza [`scripts/log-health-checker.py`](scripts/log-health-checker.py) para auditoria de saúde dos logs, e [`scripts/log-simulator.py`](scripts/log-simulator.py) para gerar dados de log para testes. Além disso, a auditoria verifica a presença de workflows críticos como `.github/workflows/24-7-continuous-agent.yml` e `.github/workflows/parallel-trio-executor.yml`, e seus placeholders caso estejam ausentes, para indicar a necessidade de restauração dos workflows reais. Também verifica a presença do placeholder [`api/monitor/api.php`](api/monitor/api.php) e a necessidade de restaurar a funcionalidade real do monitoramento.

### Olist / Tiny
Cuida de OAuth, refresh token, paginacao, produtos, imagens, estoque, peso e dimensoes.

### Frete / Checkout
Cuida de Melhor Envio, campo CEP, carrinho, checkout, origem, destino e calculo de frete.

### Imagens / Produtos
Cuida de importacao de imagens, capa, galerias, AI image templates e SEO visual.

### Seguranca / Segredos
Valida ausencia de credenciais em Git, permissoes, arquivos privados e exposicao publica.

### Pagar.me
Cuida de chaves, webhook, checkout, PIX, boleto, cartao e conciliacao de eventos.

### Installer / Updater
Cuida do atualizador web, BAT 1 clique, pos-update automatico, logs e rollback seguro.

### CRO / Simulador de Cliente
Revisa funil, layout mobile, produto, checkout, copy, confianca e conversao.

## Agentes de automacao Olist

### Login Olist
Valida arquivos locais de login antes da execucao. Aceita modelos seguros como `login_config.example.json` e `login_config.txt`, mas nunca deve versionar credenciais reais. Quando o usuario solicitar teste ou execucao, deve verificar se o arquivo local existe, se o JSON/TXT e valido e se `enabled=true`, sem imprimir senha em logs.

### Olist UI Regression
Valida seletores e fluxo visual do ERP Olist antes de executar em massa. Deve testar pesquisa por SKU, abertura do cadastro, aba Dados Complementares, botao Gerenciar imagens, input `Filedata`, botoes de remover e botao Salvar imagens.

### Selenium Test Runner
Executa testes automatizados da automacao Olist em modo teste. Deve priorizar `self_test.py`, `data_check.py` e uma amostra pequena antes de liberar processamento completo. Em caso de falha, deve salvar screenshot e log.

### Config Validator
Confere caminhos obrigatorios, extensoes, mapeamentos, planilhas, imagens geradas e dependencias Python. Deve falhar cedo se faltar `mapeamento_olist_ambientadas.xlsx`, pasta `imagens_ambientadas`, Chrome/Selenium ou arquivos de configuracao local.
**Nota:** Foi identificado que o arquivo `mapeamento_olist_ambientadas.xlsx` e a pasta `imagens_ambientadas` estao ausentes, sendo criticos para a automacao Olist/Tiny completa.

### Artifact Builder
Gera ZIPs cumulativos da automacao, README, changelog, `.gitignore`, BATs de execucao e relatorios de validacao. Inclui um script local [`scripts/local-artifact-builder.py`](scripts/local-artifact-builder.py) para geracao manual de ZIPs. Nunca inclui credenciais, caches, screenshots sensiveis, perfis Chrome, cookies ou arquivos de sessao.

### Recovery Manager
Valida retomada apos interrupcao. Deve conferir relatorios, status por SKU, arquivos ja processados e permitir continuar sem repetir itens OK, mantendo log de erros e pendencias.

### Olist Image Position Auditor
Audita a regra de imagens no ERP: manter imagens 1 a 5, inserir Hero Shot na posicao correta, limitar resultado final a ate 6 imagens quando houver 5 ou mais imagens, e remover imagens excedentes das posicoes 7, 8, 9 em diante.


### Criação de Testes
São fornecidos exemplos de testes unitários em [`tests/`](tests/) para PHP (usando PHPUnit) e Python (usando `unittest`), para serem utilizados como base para novas funcionalidades.

## Agentes reais v9.2.84 no repositorio

A pasta `agents/v9.2.84` materializa os agentes antes apenas conceituais:

- `SafeMigrationRepairAgent`: cria tabelas/indices de controle e tolera execucoes repetidas.
- `OlistImageRepairAgent`: repara imagem primaria, contagem de imagens e status Olist.
- `AutonomousReportAgent`: gera relatorio JSON para acompanhamento pelo ChatGPT.
- `SelfTestAgent`: valida runtime basico.
- `LoopStarterAgent`: registra pedido de ciclo autonomo.
- `AutonomousWatchdogAgent`: orquestra todos os agentes.

Endpoints apos deploy:
