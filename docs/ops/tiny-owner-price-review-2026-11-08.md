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
- Propostas **não são tokens de aprovação**. Nem trocar status JSON, nem
  acessar a página, nem aprovar a PR de código autoriza aplicar preço por bot.
- Nesta etapa a aplicação de um valor aprovado continua **manual no ERP
  Tiny/Olist autenticado**, por decisão expressa do proprietário para cada
  proposta. A sincronização normal ERP → site acompanha a alteração oficial.
  A ausência de interface automática de aplicação é intencional.

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
   e ausência de `PUT` comercial antes de integrar/deployar.
5. Não ativar por CI automático sem as revisões humanas da política vigente.

## Limites e próximos passos

Este ajuste cobre **somente** a direção editor admin → Tiny e escrita local
pelo editor de produto. Outras rotas de pricing (listas de preços Olist 991,
workers, marketplaces, repricers e integrações) já têm fluxos próprios de
autorização e devem ser auditadas separadamente antes de declarar conformidade
global. Não trata uma autorização única de PR como autorização ilimitada
para futuros preços. Sem validação do fornecedor e readback financeiro,
não automatizar a aplicação pós-aprovação.

Prova pré-integração: teste local
`php tests/tiny-price-owner-review-test.php` (propostas para custos zero
e positivos, idempotência, armazenamento privado, veto a preço automático),
`php tests/tiny-product-cost-payload-guard-test.php` e `php -l`.
O ambiente ativo só fica apto após aprovações de GitHub, CI e E2E no admin.
