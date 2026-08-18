# Comparativo de Preço — ShopVivaliz vs Mercado Livre / Shopee

Relatório apenas informativo. Nenhum preço foi alterado no site — decisão de ajuste de preço é do Fred, após ver os números abaixo.

Metodologia: preços do ShopVivaliz vêm direto do cache real de produtos (`storage/products-cache-ativos.json`, sincronizado do Tiny). Preços de mercado vêm de busca ao vivo (agosto/2026) em Mercado Livre e Shopee para produtos equivalentes ou muito próximos (mesma marca quando possível). Quando a busca não trouxe um preço exato de um anúncio específico, uso a faixa de preço observada no mercado para o mesmo tipo de produto.

## Resultados por categoria

### Rodízios / Rodas para Móveis

| Produto | Preço ShopVivaliz | Faixa de mercado (ML/Shopee) | Posição |
|---|---|---|---|
| Rodízio Transparente 50mm c/ Freio Soprano | R$ 18,02 | R$ 35,70 – R$ 69,90 (marcas diversas, mesmo porte) | **Abaixo do mercado** — bem competitivo |
| Rodízio Transparente 35mm c/ Freio Soprano | R$ 19,92 | Faixa similar à acima, ~R$ 25–45 pra 35mm | **Abaixo do mercado** |

### Caixas de Ferramentas (Fercar)

| Produto | Preço ShopVivaliz | Faixa de mercado (ML/Shopee) | Posição |
|---|---|---|---|
| Caixa Baú Reto N00 30x13x10 c/ Bandeja Fercar | R$ 78,96 | Não achei o modelo N00 exato à venda pra comparar direto | Sem comparável direto |
| Caixa Sanfonada 3 Gavetas 50x20x17 N05 Fercar | R$ 234,22 | ~R$ 202,92 – R$ 234,90 (mesma linha Fercar, tamanhos próximos) | **Em linha / ligeiramente acima** — vale checar o modelo exato mais barato achado (R$202,92) pra confirmar se é o mesmo tamanho |

### Vasos Decorativos

| Produto | Preço ShopVivaliz | Faixa de mercado (ML/Shopee) | Posição |
|---|---|---|---|
| Vaso Cilíndrico Decore 34 (28L) Off White Japi | R$ 419,04 | R$ 199,89 – R$ 424,89 (outras lojas, mesma linha Japi) | **No teto da faixa** — risco real de estar caro vs. concorrência direta da própria marca |
| Assento Sanitário Oval Universal Soft Astra | R$ 51,47 | Não obtive preço exato no ML, mas é produto de alto volume e concorrência acirrada (avaliação de 1.395 compradores citada) | Recomendo checar manualmente — categoria de margem apertada |

### Utilidades Domésticas

| Produto | Preço ShopVivaliz | Faixa de mercado (ML/Shopee) | Posição |
|---|---|---|---|
| Rodo Vedante Borracha Friso Porta Alumínio 70cm | R$ 35,02 | R$ 13,74 – R$ 27,60 (maioria dos anúncios) | **Acima do mercado** — este é o achado mais forte do relatório, ver abaixo |
| Rodo Vedante Borracha Friso Porta Alumínio 90cm | R$ 29,03 | Mesma faixa da linha acima | Dentro da faixa, mas o 70cm mais caro que o 90cm é estranho (ver observação) |

## Observações que pedem atenção

**Rodo vedante de porta**: o ShopVivaliz cobra R$ 35,02 no modelo de 70cm e R$ 29,03 no de 90cm — ou seja, o produto **menor está custando mais caro que o maior**, o que não faz sentido de precificação e pode ser erro de cadastro no Tiny, não uma decisão deliberada. Isso é o achado mais concreto e acionável do comparativo. Vale conferir a ficha desses dois SKUs primeiro.

**Vaso Cilíndrico Decore 34 Japi**: R$ 419,04 está no topo da faixa observada em outras lojas (R$ 199,89 a R$ 424,89) para o mesmo modelo. Como é um item de ticket alto e a categoria "Vasos Decorativos" é a maior do catálogo (48 produtos), vale revisão de margem/preço nesse item específico — mas note que a faixa de R$ 199,89 tem desconto de Pix/boleto, então a comparação "de tabela" pode estar mais perto do topo do que parece à primeira vista.

**Rodízios**: ShopVivaliz está bem abaixo do mercado — pode ser vantagem competitiva real (bom motivo pra destacar mais esse ponto no anúncio/produto) ou sinal de margem apertada demais; não dá pra saber sem o custo de aquisição, que não tenho acesso.

## O que não deu pra comparar com precisão

Busca via texto (não tenho acesso à API de preços do ML/Shopee) traz faixas de mercado, não o preço exato de um concorrente vendendo o SKU idêntico. Pra um comparativo mais preciso, o ideal seria: (1) Fred me passar links diretos de 5-10 anúncios concorrentes que ele já conhece, ou (2) conectar a API do Mercado Livre/Shopee (não tenho isso configurado no momento).
