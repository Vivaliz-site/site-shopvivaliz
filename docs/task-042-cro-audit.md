# Task 042 - CRO Audit Baseline

## Objetivo
- Dar continuidade a `task-042` com uma auditoria automatica das superficies que mais impactam conversao.
- Priorizar melhorias seguras em home, catalogo, produto, carrinho e checkout sem tocar em preco, frete ou meios de pagamento.

## Script
- `python scripts/cro-surface-audit.py`

## Saidas locais
- `logs/cro-surface-audit.json`
- `logs/cro-surface-audit.md`

## Escopo auditado
- `home.php`
- `catalogo.php`
- `produto.php`
- `carrinho.php`
- `checkout.php`

## Critérios
- CTA principal visivel
- elementos de confianca
- suporte a descoberta e navegacao
- suporte a compra imediata
- reforcos de SEO e contexto comercial nas paginas de produto

## Uso no ciclo autonomo
- O ciclo continuo pode usar esse baseline para decidir a proxima melhoria local de CRO quando `task-042` estiver em andamento.

## Progresso aplicado
- Checkout recebeu reforco explicito de compra sem cadastro obrigatorio.
- Checkout recebeu CTA de suporte rapido por WhatsApp ao lado da conversao principal.
- Pagina de produto recebeu bloco de confianca ao lado do CTA principal.
- Pagina de produto recebeu caminho rapido para contato comercial antes da compra.
- Catalogo recebeu faixa curta de confianca perto da grade de produtos.
- Carrinho passou a informar explicitamente que o pedido fica salvo para continuar depois.
- Home passou a expor CTA comercial explicito com mencao a WhatsApp acima da dobra.
