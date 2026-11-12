# Amazon — conciliação de custos e margens (12/11/2026)

Checkpoint factual de uma auditoria somente leitura. Nenhum preço, custo
contábil, estoque ou vínculo de produto foi escrito na Amazon ou no Olist.
Este documento não cria políticas, bloqueios ou critérios novos.

## Etapa 1 — inventário e implementação: executada e verificada

- Auditoria integral #20: 528 Seller SKUs, zero avisos.
- PR #3919: custo e identidade do vínculo Olist atual separados da margem
  histórica conciliada, merge no commit
  759fdee0f0ea2b8f28c334f8bc92016d4f75cb3a.
- Main, release ativa da VM Marina e versão pública verificados com
  retorno VERIFIED_EXACT pelo fechamento do deploy.
- Auditoria integral #21: 528 Seller SKUs, zero avisos, finalizada em
  12/11/2026 às 15:52:59 UTC.
- E2E no navegador real com SSO administrativo oficial: painel carregou
  a auditoria #21, relação de kit e custo atual de R$ 15,05 para
  KIT5ROD35CFREIOFBA, margem atual estimada 27,02% com ressalvas,
  enquanto a margem histórica permanece não apurada; para
  CN-U9ID-NZPT apresentou 9,00% arredondados, com classificação
  de risco conforme cálculo monetário exato.

## Etapa 2 — evidência de custos, não confundir cadastro com certificação

Dos 39 anúncios sem custo certificado por unidade vendida no histórico:

- 20 têm vínculo atual autenticado, custo registrado e custo de conjunto
  verificados contra o Olist V3, mas identidade de pedidos históricos
  divergente. Desses, 17 resultaram em estimativa atual e 3 ficaram
  pendentes por limites de pacote/logística.
- 14 são kits com custo positivo cadastrado no ERP, mas quantidade ou
  composição/BOM ainda não conciliadas.
- 3 não têm vínculo atual identificável e estavam sem oferta comprável.
- 1 não apresentou custo de aquisição positivo e estava sem oferta comprável.
- 1 apresentou vínculos com mais de um produto ERP; a identidade
  permanece ambígua (YC-3KPY-RYSV).

Exemplos de kit: KIT2C2SFBA50, KITRCOMESEMFBA, 4T-0TUH-4IS2
e Rod50parafdba registram R$ 33,08 contra R$ 14,94 na BOM;
3L-9Q6V-0D19 e pote-250, R$ 56,95 contra R$ 57,00;
WV-AHVH-R05V e pote-500, R$ 80,00 contra R$ 80,40.
PCT-250, pct-250, PCT-500, pct-500, 6I-4XIH-6NJY e
9H-VOT6-INSR possuem custo positivo, mas a quantidade da
unidade comercial anunciada não foi confirmada.

ROD6FBA: custo do conjunto atual R$ 24,18; margem atual
estimada 18,24%. KIT5ROD35CFREIOFBA: custo do conjunto
atual R$ 15,05; margem atual estimada 27,02%.
Cofre 1C7Q-LKVG-BOTU: custo de R$ 8,30 confirmado no ERP.

## Etapa 3 — piso de margem comercial: não apto integralmente

Da auditoria #21: 412 anúncios BUYABLE dos 528 cadastrados.
O painel exibiu 327 ofertas com margem atual estimada maior
ou igual a 10%; 59 inferiores a 10% (16 delas estritamente
abaixo de 9%); 26 ofertas com margem atual pendente; 181
projeções com ressalvas de dimensões ou modalidade logística.

Estes valores são projeções, não lucro financeiro realizado.
Histórico: 95 linhas confirmadas, 7 parciais e 3 com conciliação
financeira pendente. A ferramenta trabalha exclusivamente em
leitura; ajustes comerciais não foram efetuados.

## Etapas seguintes, encadeadas conforme evidência

1. Corrigir a exibição dos custos cadastrados de kits na tela
   e adicionar coluna de referência no Excel; preservar o campo
   de custo certificado, que permanece vazio quando inconclusivo.
   Validar testes regressivos e interface após deploy.
2. Reconciliar o anúncio YC-3KPY-RYSV em cada produto Olist
   relacionado e com as vendas históricas; preservar divergência
   quando não existir prova inequívoca.
3. Conferir quantidade e componentes dos 14 kits pela aba
   Anúncios, ficha do produto, notas e composição; custos
   divergentes não serão substituídos por inferência.
4. Preparar análise comercial individual das 59 ofertas abaixo
   de 10% e das 26 pendentes, verificando comissão, peso,
   frete, quantidade, impostos e reflexos no ERP 991/site.
5. Depois de correções comerciais legítimas e verificáveis,
   executar novo snapshot, comparar histórico e projeções,
   validar interface, exportações PDF/Excel e SHA publicado,
   e então determinar o estado de aptidão com base nos dados.

Referências: banco Amazon Margin, relatório persistido
amazon_margin_audit_runs.id=21 na VM marina-a1-4c24g,
PR #3919 e deploy principal.
