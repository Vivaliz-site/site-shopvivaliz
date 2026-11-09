# Política comercial — revisão do proprietário antes de alterar preços (08/11/2026)

## Decisão expressa do proprietário

**Nenhum preço de venda, promocional ou custo deve ser alterado automaticamente
pelo editor administrativo do site sem validação e autorização do proprietário.**
Custo zero ou ausente é um sinal de revisão obrigatória, **não** autorização
para preencher um custo fictício ou uma rejeição comercial definitiva. Um
produto pode legitimamente permanecer com custo zero após decisão do dono.

## Escopo técnico da PR #3835 revisada

- O formulário `/admin/editar-produto.php` compara preço, promoção e custo
  antes/depois. Havendo alteração, registra a proposta em diretório privado
  persistente e conserva **todos** os valores comerciais anteriores no
  catálogo. Falha em registrar a proposta interrompe a atualização inteira.
- `svtpp_push_product_update` bloqueia QUALQUER `PUT /produtos/{id}`
  cujo payload tenha objeto `precos`, mesmo quando o custo é positivo.
  Campos estritamente editoriais continuam usando um payload sem `precos`.
- Custo zerado, custo positivo e mudanças promocionais seguem para análise
  do proprietário. A tela `/admin/precos-pendentes.php` é **somente leitura**
  e exige sessão admin **mais** `SHOPVIVALIZ_PRICE_OWNER_USER_ID` correspondente.
- Uma proposta não é autorização. Somente o **POST CSRF de um clique do proprietário autenticado**
  em `/admin/precos-pendentes.php` autoriza aplicar o preço exato sugerido.
- O botão `Aprovar e aplicar` invoca `svtpp_apply_owner_approved_price` que:
  verifica identidade exclusiva; faz GET oficial do produto/SKU/preço anterior;
  grava duravelmente o estado `applying` antes da única escrita;
  executa `PUT /produtos/{id}/preco` apenas com `preco` e `precoPromocional`;
  faz GET novamente e exige paridade do valor aprovado. Um resultado ambíguo
  permanece visível em `readback_required` sem novo PUT automático.
- Custo zerado pode ser aprovado como informação da proposta, **sem** preenchimento fictício.
  Este botão não modifica custo de aquisição e não se destina a aprovar
  mudanças **somente** de custo. A aplicação do preço de venda não depende de
  custo positivo quando o proprietário validou expressamente.
- O botão é um único ato de autorização; não existe automação agendada de
  precificação nem segunda confirmação. A sincronização ERP → site continua
  pela rotina de **leitura** já existente. A aplicação em lista 991 e em
  marketplaces externos exige confirmação de escopo e fluxos específicos.

## Configuração privada exigida ANTES da implantação

1. Definir `SHOPVIVALIZ_PRICE_REVIEW_DIR` para diretório persistente, gravável
   pelo processo web e **fora do document root** e fora da release imutável.
   Não usar `logs/` público, diretório do repositório, nem `/tmp` efêmero.
   Aplicar permissões de mínimo privilégio (diretório 0700, arquivos 0600).
2. Definir `SHOPVIVALIZ_PRICE_OWNER_USER_ID` com o ID real do usuário
   proprietário autenticado no admin; não inferir de login GitHub nem usar
   conta de automação. Em falta de correspondência, a tela responde 403.
3. Com configuração ausente/invalidada, as propostas não podem ser
   persistidas, portanto a edição comercial falha fechada sem escrita.
4. Validar página admin autenticada, integridade da fila privada, campos
   antigos preservados, acesso negado a outros usuários, teste de custo zero,
   que `PUT` só ocorra **após** clique do proprietário e somente uma vez,
   com GET anterior/posterior. Testar também estado de escrita ambígua.
5. Não ativar por CI automático sem as revisões humanas da política vigente.

## Limites e próximos passos

Este ajuste cobre a fila do editor admin e o preço base do produto Tiny via
endpoint específico `/produtos/{id}/preco`. **Não confirma** ainda que a API
`ml-pricing-api` gere todas as sugestões ou que o clique publique os preços
na tabela 991, Mercado Livre ou Amazon. Cada integração deve aplicar somente
itens e valores exatos aprovados, respeitar a API externa e fazer readback.
Nenhuma rotina de worker/reprecificação publica preços sem clique do proprietário.

Prova pré-integração: teste local
`php tests/tiny-price-owner-review-test.php` (propostas para custos zero
e positivos, idempotência, armazenamento privado, veto a preço automático),
`php tests/tiny-product-cost-payload-guard-test.php`,
`php tests/tiny-price-owner-click-apply-test.php` (aplicação uma vez por clique,
custo zero, autorização, idempotência, divergência de GET e resultado ambíguo),
e `php -l`.
O ambiente ativo só fica apto após aprovações de GitHub, CI e E2E no admin.
