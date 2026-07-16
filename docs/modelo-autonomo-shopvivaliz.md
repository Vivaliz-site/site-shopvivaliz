# Modelo Autonomo ShopVivaliz

## Regra principal

O ShopVivaliz opera com autonomia total por padrao.

O sistema, agentes, pipelines e rotinas podem executar automaticamente:

- desenvolvimento;
- correcao de bugs;
- QA;
- documentacao;
- deploy;
- rollback;
- monitoramento;
- observabilidade;
- SEO;
- conteudo;
- imagens;
- categorias;
- integracoes;
- marketplaces;
- campanhas e Ads sem alteracao de preco;
- criacao de novos agentes;
- dashboards e relatorios.

## Unica excecao

Apenas tarefas que possam alterar o preco final cobrado do cliente devem ser bloqueadas antes da execucao.

Isso inclui:

- preco de venda;
- preco promocional;
- desconto;
- cupom;
- margem;
- markup;
- regra de precificacao;
- sincronizacao de precos entre site, ERP, Olist, Tiny, Shopee, Mercado Livre ou outros marketplaces.

## Implementacao

A politica central fica em:

```text
config/autonomous-policy.json
```

O guardiao fica em:

```text
scripts/autonomous-policy-guard.py
```

O executor autonomo deve chamar o guard antes de executar cada tarefa.

Comportamento esperado:

- tarefa sem impacto em preco: executa automaticamente;
- tarefa com impacto em preco: bloqueia antes da execucao;
- credenciais e tokens nunca devem aparecer em logs.
