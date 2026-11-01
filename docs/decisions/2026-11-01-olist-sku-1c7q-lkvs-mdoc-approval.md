# Decisao comercial pendente: SKU duplicado Olist 1C7Q-LKVS-MDOC

**Estado: AGUARDANDO DECISAO EXPLICITA DE `shopvivaliz-human` NESTA PR.**
Esta proposta e somente documental; **nao** autoriza alterar precos, estoque, pedidos, reservas, anuncios, vinculacoes de canal ou dados do ERP. Abertura de PR/CI verde nao e decisao.

## Evidencia auditavel

- Olist API v3, pos-deploy da PR #3272, main `61d73ec6ee95882329bfb84a282812861e867e8a`: workflow [41322383380](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/41322383380) concluiu `failure` (fail-closed).
- Detalhes de 184 produtos ativos lidos sem erros; 181/181 precos publicados conferiram com a tabela Amazon 991. Falha: **dois IDs ativos com SKU identico e preco diferente**.
- Produto ERP **342902508**, SKU `1C7Q-LKVS-MDOC`, preco efetivo Amazon 991 **R$ 38,90**.
- Produto ERP **387064728**, mesmo SKU, preco efetivo Amazon 991 **R$ 10,00**.
- O site/feed publicam o primeiro ID; nao inferir que esse ID e o cadastro comercial canonico. Na leitura de 30/10, ID342 possuia 4 unidades fisicas inteiramente reservadas (vendavel 0); ID387 possuia 4 disponiveis. **Revalidar saldo, reservas e canais no momento da operacao.**
- Issues correlatas: [#3271](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3271) e [#3404](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3404). A rotina de webhook por ID exato foi integrada pela PR #3411.

## Decisao que SOMENTE o proprietario pode formalizar neste PR

Antes de aprovar/liberar o merge, registrar na propria revisao ou atualizar este documento com:

1. **ID canonico:** escolher explicitamente `342902508` ou `387064728`, ou declarar que ambos representam itens comerciais distintos.
2. **SKU e canal do registro nao canonico:** identificador final e destino (manter, renomear, desativar ou outra acao), preservando vinculos dos marketplaces e sem transferir pedidos nem reservas de forma automatica.
3. **Preco comercial e lista:** aprovar explicitamente, para cada produto que permanecera ativo, qual preco/lista deve prevalecer e se ha alguma mudanca de preco. Valores desta auditoria sao evidencias, NAO autorizacao para editar.
4. **Reservas, estoque e anúncios:** definir os efeitos admissiveis sobre pedidos, webhook, saldo vendavel e vinculacoes de canal antes de qualquer alteracao.

**Sem os quatro itens preenchidos, manter este PR em Draft e a auditoria em NAO_APTO.** Nenhum agente deve preencher uma decisao comercial por inferencia.

## Implementacao posterior (NAO executada nesta PR)

Apos decisao humana valida, gerar plano de alteracao idempotente, revisado e testado, por ID autoritativo na API/UI oficial do Olist. Obter lease/escopo conforme runbook, fazer backup e reconciliar pedidos/reservas/anuncios; criar PR de codigo separado se houver mudanca de integracao. Validar UI real, persistencia, estoque vendavel, 181 produtos publicos e auditoria de precos ERP v3 completa, com nova execucao fail-closed. Respeitar outros bloqueios de autenticacao e revisao.

## Risco, testes e rollback desta proposta

- **Diff:** somente este documento de proposta; zero comandos ERP ou alteracoes de producao.
- **Testes:** verificar integridade do Markdown, SHA e links; a auditoria existente falhou corretamente, sem afrouxar o gate.
- **Risco desta PR:** nenhum efeito imediato em SKU/preco/estoque; risco de aprovacao sem decisao reduzido pelo estado Draft.
- **Rollback:** remover/reverter o documento; nao ha rollback operacional porque nao ha mutacao de ERP neste diff.
- **Restricao:** nenhuma automacao pode tratar o merge deste documento, isoladamente, como concessao de permissao de acesso nem como reparacao concluida.
