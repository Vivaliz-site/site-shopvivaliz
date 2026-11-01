# Aprovacao comercial nativa na PR - contrato fail-closed

Escopo: issue #3461; precos e estoques ShopVivaliz; PRs #3269/#3272.
Nao modifica precos, estoque, ERP, roles de provedores nem ativa grants.

## Fluxo
1. Bot/GitHub App com identidade diferente da identidade do proprietario abre PR, evidencias, SHA, impacto e rollback.
2. O proprietario usa a UI oficial GitHub: Files changed > Review changes > Approve, autenticado em conta exclusiva dele, inacessivel a agentes, runners e conectores.
3. O evento review submitted/dismissed reexecuta Autonomy Boundary e Mandatory Validation Gate; ambos verificam via API GitHub a revisao mais recente do proprietario, seu commit_id, head/base vigentes, permissao de escrita e revogacao.
4. Somente apos required checks GREEN e regras de branch satisfeitas pode ocorrer merge protegido, seguido de deploy, ERP/UI e auditoria.
5. Mudanca material de SHA, revogacao, falha de API, identidade compartilhada ou ausencia de config: NEGAR.

## Configuracao protegida (operacao exclusiva do proprietario)
- SHOPVIVALIZ_COMMERCIAL_OWNER_LOGIN (Actions Secret): login GitHub **exclusivo do proprietario**. NAO usar fredmourao-ai enquanto agentes tiverem credenciais dessa conta.
- SHOPVIVALIZ_COMMERCIAL_AUTOMATION_LOGINS (Actions Secret): logins de automacoes separados por virgula, incluindo fredmourao-ai enquanto essa conta estiver compartilhada.

A conta exclusiva precisa da permissao write/maintain/admin. Nao escrever secrets em PR, comment ou arquivos. Nao usar GITHUB_ACTOR ou merged_by.login isoladamente como prova de atividade humana. GitHub impede self-review; autor da PR deve ser identidade separada do revisor humano.

## Garantias
A label historica human-approved-price-stock permanece visivel SOMENTE como metadado. Labels, comentarios, auto-merge habilitado e workflow_dispatch NAO concedem autorizacao. O workflow_dispatch de Autonomy Boundary nao recebe numero de PR autenticado, portanto nao pode criar autorizacao comercial.

Casos negativos cobertos: self-review, credencial compartilhada, reviewer sem permissoes, evento revogado/dismissed, CHANGES_REQUESTED, review obsoleto, SHA/base trocados, PR fechada/draft, API indisponivel e ausencia de secrets. Fluxo positivo exige review APPROVED da identidade exclusiva no head atual.

ATE a conta exclusiva e os dois secrets estarem provisionados oficialmente, o fluxo segue AWAITING_APPROVAL. Nao executar alteracao de preco comercial, merge forçado ou grant externo. Gate V5, regressao, paridade Amazon 991 ERP Olist/Tiny e UI real continuam obrigatorios.
