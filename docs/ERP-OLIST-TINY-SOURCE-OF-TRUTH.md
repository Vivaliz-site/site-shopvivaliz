# ERP Olist/Tiny como fonte unica operacional

Regra permanente: todo dado que existe na API ERP Olist/Tiny deve vir dela. O Shop Vivaliz pode manter cache, indices de busca, paginas renderizadas e enriquecimentos de UX, mas esses artefatos sao derivados e nunca sao fonte de verdade para produto, preco, estoque, pedido, nota fiscal, cliente, status ou rastreio.

## Matriz de responsabilidade

| Dominio | Fonte autoritativa | Uso permitido no site | Fonte proibida |
|---|---|---|---|
| Produtos, variacoes e situacao | ERP Olist/Tiny API v3 | Cache publico derivado e validado | VNDA, arrays PHP estaticos, mocks, API antiga |
| Preco e promocao | ERP Olist/Tiny API v3, detalhe do produto/listas quando habilitadas | Exibir preco efetivo derivado do ERP | Planilhas manuais e scripts locais de preco |
| Estoque disponivel | ERP Olist/Tiny API v3, endpoint especifico de estoque | Cache de disponibilidade com cobertura minima | Estoque bruto local, zeros fabricados, fallback antigo |
| Imagens | Cadastro/anexos do produto no ERP Olist/Tiny API v3 | Galeria publica derivada do ERP | Importador manual antigo e array estatico |
| Pedidos/vendas | ERP Olist/Tiny API v3 apos checkout | Espelho local para UX/admin e reconciliacao | Status local como fonte final |
| Nota fiscal/documentos | ERP Olist/Tiny API v3 | Leitura de NF, chaves, links e situacao | Arquivos manuais como verdade |
| Clientes/contatos | ERP Olist/Tiny API v3 quando a API fornecer | Sync derivado para atendimento | Cadastro paralelo divergente |
| Logistica/rastreio | ERP Olist/Tiny API v3 e transportadora integrada pelo ERP | Exibicao e notificacoes | Texto manual sem reconciliacao |

## Regras de implementacao

1. Nenhum runtime publico pode ler VNDA, array PHP estatico de produtos, mock ou API antiga para catalogo, preco, estoque, pedido ou NF.
2. O arquivo `api/catalog/fallback-products.json` e apenas cache derivado do ERP; rollback deve ser por Git/release, nao por manter codigo legado duplicado.
3. Tokens de runtime devem vir do OAuth v3 e do arquivo rotativo gerenciado pelo renovador. Token estatico antigo nao e fallback valido.
4. Webhooks/eventos devem acionar refresh incremental; reconciliacao periodica existe apenas para corrigir drift.
5. Quando a API ainda nao tiver endpoint de escrita homologado para algum campo, o codigo deve falhar fechado e documentar o bloqueio, em vez de chamar fonte antiga.
6. Cada migracao que substitui um caminho antigo deve remover ou aposentar o arquivo/processo obsoleto no mesmo PR.

## Estado implementado nesta fase

- Politica automatizada em `tests/erp-api-source-policy-test.php` bloqueia fonte nao ERP nos caminhos de runtime.
- Catalogo/preco/estoque publico aceitam somente `sync_source=tiny_v3`.
- Endpoints legados em `claude/` viraram alias/redirect para os endpoints canonicos.
- Importador manual antigo de imagens foi aposentado com HTTP 410.
- Publisher do ERP nao usa mais API antiga para resolver SKU; usa busca v3 por SKU ativo.
- Escrita de imagens via caminho antigo foi removida; imagens publicas devem vir da API/cadastro ERP v3.

## Decisao 2026-08-21: token estatico legado removido do runtime

O alias antigo `TOKEN_API_OLIST` nao e mais aceito como fonte de runtime, nem como fallback de deploy, daemon ou workflow. A unica fonte de acesso valida para chamadas v3 e o par rotativo `OLIST_ACCESS_TOKEN`/`TINY_ACCESS_TOKEN`, preferencialmente carregado de `/shared/private/olist-tokens.json` pelo renovador OAuth.

Consequencias operacionais:

- `daemon-token-renewer.py` atualiza apenas `OLIST_*` e `TINY_*` e remove a linha legada se ainda existir no `.env`.
- `daemon-sync-products.py` ignora o alias antigo e falha fechado quando nao houver token OAuth v3 valido.
- Workflows e configuradores nao passam mais o segredo legado para a VM.
- `tests/erp-api-source-policy-test.php` cobre `.github/workflows`, `config`, daemons, scripts e endpoints publicos para impedir regressao.

## Escopo por dominio da migracao ERP v3

- Catalogo/preco/estoque/imagens: implementado como cache derivado de `GET /produtos`, `GET /produtos/{id}` e `GET /estoque/{id}`.
- Pedidos: criacao/atualizacao deve usar `POST /pedidos`, `GET /pedidos/{id}` e espelho local apenas como cache/admin/UX.
- NF/documentos: leitura deve usar `GET /notas/{id}` e `GET /notas/{id}/xml`; o banco local guarda somente cache do ultimo retorno.
- Rastreio/despacho: deve usar `GET /pedidos/{id}` e `PUT /pedidos/{id}/despacho` quando o contrato estiver validado.
- Clientes/contatos: criacao/busca deve usar `GET /contatos` e `POST /contatos`; cadastro local nao pode sobrescrever ERP.

## Proximas fases obrigatorias

1. Pedidos: mapear checkout -> criacao/atualizacao de pedido no ERP e espelho local somente derivado.
2. NF: sincronizar emissao, chave, XML/PDF quando disponivel na API, status e erros fiscais.
3. Logistica: sincronizar envio, rastreio, eventos e notificacoes a partir do ERP/transportadora integrada.
4. Clientes: reconciliar contatos/clientes do checkout com o cadastro ERP quando o endpoint estiver habilitado.
5. Observabilidade: painel de cobertura por dominio, ultimo webhook, ultima reconciliacao e divergencias.

## Criterios de aceite

- `php tests/erp-api-source-policy-test.php` deve passar.
- `php scripts/quality/validate-olist-catalog-integrity.php` deve passar com fonte unica `tiny_v3`.
- Nenhum endpoint publico deve retornar produto vindo de VNDA, mock, array estatico ou API antiga.
- Qualquer endpoint de escrita sem contrato v3 homologado deve falhar fechado, sem mutar o ERP por caminho antigo.
