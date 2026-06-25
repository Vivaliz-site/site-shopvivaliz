# ShopVivaliz - Agentes do Projeto

Este repositorio usa agentes especializados para acelerar lancamento, QA e releases cumulativos.

## Regras gerais

- Sempre gerar atualizacoes cumulativas.
- Nunca commitar credenciais reais, tokens, dumps de producao ou arquivos de `storage/private`.
- Toda alteracao deve considerar PHP 8.3, MySQL 5.7, atualizador web e ambiente dev em `https://dev.shopvivaliz.com.br`.
- SQLs, migrations e reparos de vinculo devem rodar no atualizador sem links manuais.
- Todo release deve atualizar `SV_VERSION`, migrations, self-test e relatorio.

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

## Comando inicial para Codex

Leia este AGENTS.md e prepare o ShopVivaliz para atualizacoes cumulativas, QA automatico, releases, OAuth Olist/Tiny, frete/checkout, imagens de produtos, self-test e seguranca de credenciais.
