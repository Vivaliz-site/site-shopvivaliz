# Estudo pos-restauracao - 2026-07-22

Base avaliada: storefront restaurada em `f9ce4738` e commits posteriores ate `f40d4bbb`.

## Classificacao

| Commit | Classificacao | Decisao |
| --- | --- | --- |
| `711c7864` Backup/pre rollback | Misto: contem melhorias funcionais, mas tambem alteracoes amplas de CSS, banners, rotas, scripts de deploy e automacao | Reaplicar somente pecas funcionais isoladas e testaveis |
| `821bdfe7` Deploy/GitHub App token e rollbacks | Correcao operacional de deploy | Nao reaplicado nesta etapa; exige teste de automacao separado |
| `f9ce4738` Restore storefront estavel 2026-07-21 05:22 BRT | Restauracao visual/base estavel | Manter como referencia visual |
| `e3fdffe0` Restore complete storefront styles | Correcao visual apos rollback | Manter ja presente na base atual |
| `fd8ad27d` Forca redeploy emergencial | Gatilho operacional | Nao reaplicar; nao e melhoria de codigo da loja |
| `aba7873e` Politica de teste antes de producao | Regra operacional | Manter |
| `5db68c2a` Restore public routing and assets | Correcao de rotas/assets publicos | Manter |
| `f40d4bbb` Physical route entrypoints | Correcao de rotas fisicas | Manter |

## Melhorias reaplicadas agora

- `api/catalog/fallback-products.json`: restaura fallback local do catalogo para a loja e para a Liz.
- `api/agent/liz-smart-reply.php`: restaura memoria persistente da Liz, adaptada para poder ser incluida pelo endpoint atual.
- `api/agent/squad-chat.php`: usa a Liz com memoria quando disponivel, mas cai para a resposta anterior se SQLite/cURL/Gemini falhar.
- `public/assets/liz-assistant/liz-assistant.js`: envia `user_id` persistente no navegador sem alterar layout do widget.
- `includes/catalog-runtime.php`: filtra o catalogo canonico para nao expor produtos sem SKU, sem imagem real ou com preco menor que R$ 1.
- `produto.php`: aceita links antigos/novos de produto pelo SKU normalizado no fim do slug.

## Correcoes criticas verificadas

- Preco/estoque nao sao sobrescritos pela tabela local `products`; `includes/product-price-enrich.php` continua no-op para evitar preco multiplicado/desatualizado.
- `api/cart/validate.php` valida preco e estoque no servidor antes do checkout.
- Checkout mantem apenas Mercado Pago, com botao de remover cupom e calculo automatico de frete por CEP.
- Tiny usa `mercadopago.payment_method_id/payment_type_id` para distinguir Pix, boleto e cartao.
- Navegador local confirmou catalogo com 177 produtos validos; o item `Parafuso5x16` com preco R$ 0,01 e imagem vazia foi removido da exposicao.

## Nao reaplicado nesta etapa

- CSS, banners e temas novos do commit `711c7864`, porque mudam visual e foram associados ao layout quebrado.
- Scripts de auto-sync/deploy e workflows, porque afetam producao/automacao e precisam de teste proprio.
- Arquivos de pagamento, diagnostico e configuracao sensivel, porque sao risco operacional.
