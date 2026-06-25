# ShopVivaliz - Agentes do Projeto

Este repositorio usa agentes especializados para acelerar lancamento, QA, automacoes Olist/Tiny, seguranca e releases cumulativos.

## Regras gerais

- Sempre gerar atualizacoes cumulativas.
- Nunca commitar credenciais reais, tokens, dumps de producao ou arquivos de `storage/private`.
- Nunca commitar `login_config.json`, cookies, perfis Chrome, HARs com cookies, relatorios contendo dados sensiveis ou dumps de sessoes autenticadas.
- Toda alteracao deve considerar PHP 8.3, MySQL 5.7, atualizador web e ambiente dev em `https://dev.shopvivaliz.com.br`.
- SQLs, migrations e reparos de vinculo devem rodar no atualizador sem links manuais.
- Todo release deve atualizar `SV_VERSION`, migrations, self-test e relatorio.
- Para automacoes Olist/Tiny, sempre executar validacoes locais antes de gerar ZIP ou publicar alteracoes.

## Agentes principais

### Release Manager
Gera ZIP cumulativo, controla versao, release notes, migrations e validacoes finais.

### QA / Self-test
Executa lint, integridade ZIP, endpoints, webhooks, botao Comprar, CEP, checkout, frete e versao aplicada.

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

### Artifact Builder
Gera ZIPs cumulativos da automacao, README, changelog, `.gitignore`, BATs de execucao e relatorios de validacao. Nunca inclui credenciais, caches, screenshots sensiveis, perfis Chrome, cookies ou arquivos de sessao.

### Recovery Manager
Valida retomada apos interrupcao. Deve conferir relatorios, status por SKU, arquivos ja processados e permitir continuar sem repetir itens OK, mantendo log de erros e pendencias.

### Olist Image Position Auditor
Audita a regra de imagens no ERP: manter imagens 1 a 5, inserir Hero Shot na posicao correta, limitar resultado final a ate 6 imagens quando houver 5 ou mais imagens, e remover imagens excedentes das posicoes 7, 8, 9 em diante.

## Fluxo padrao quando solicitado "utilize os agentes"

1. `Seguranca / Segredos` valida que nenhuma credencial real sera commitada.
2. `Config Validator` verifica arquivos, caminhos e dependencias.
3. `Login Olist` valida somente configuracao local de login.
4. `QA / Self-test` executa testes estaticos e de integridade.
5. `Olist UI Regression` valida seletores e fluxo visual quando aplicavel.
6. `Selenium Test Runner` executa modo teste/amostra quando houver ambiente local disponivel.
7. `Olist Image Position Auditor` valida regras de posicao de imagens.
8. `Artifact Builder` gera ZIP cumulativo e documentacao.
9. `Release Manager` registra versao, relatorio e instrucoes finais.

## Comando inicial para Codex

Leia este AGENTS.md e prepare o ShopVivaliz para atualizacoes cumulativas, QA automatico, releases, OAuth Olist/Tiny, frete/checkout, imagens de produtos, self-test, automacoes Selenium/Olist e seguranca de credenciais.
