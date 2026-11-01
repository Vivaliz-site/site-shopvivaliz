# Reemissão da autorização humana MCP central → Marina — merge MANUAL obrigatório

**Estado: NÃO AUTORIZADO ATÉ NOVA APROVAÇÃO E MERGE PESSOAL.**

## Falha comprovada no grant anterior

A PR de segurança [#3478](https://github.com/Vivaliz-site/site-shopvivaliz/pull/3478) foi integrada ao `main` no commit `4c5911de0547d6c6515ac87eb703568ee37c7640`, com `merged_by=shopvivaliz-human`. Contudo, o readback oficial da API GitHub retornou um objeto **`auto_merge` não nulo**, ativado por `shopvivaliz-human`. O validador canônico `scripts/validate-owner-pr-grant.py` exige `auto_merge=null` E merge pessoal, pois habilitar integração automática não constitui a validação de merge manual prevista no contrato. O ativador `scripts/ops/activate-mcp-central-marina-readonly.py` também nega explicitamente `auto_merge!=null`.

O grant `security/owner-approvals/3478.json` permanece **histórico e inaceitável para ativação** por esse mecanismo. Não editar, remover ou falsificar metadados do GitHub; não enfraquecer o validador nem habilitar flag do MCP. O runtime verificável ainda mostra Marina `read_only_pending_owner_grant`, `route_enabled=false`, sem drop-in `92-marina-readonly-grant.conf` e sem estado privado de concessão instalado.

## Proposta vinculada à nova PR

Reemitir um segundo grant imutável independente, `security/owner-approvals/<PR_NOVA>.json`, com **recurso `mcp:central:marina`, principal `shopvivaliz-central-mcp`, hostname de destino `marina-a1-4c24g`, controlador `always-free-arm-1787907847-26`, capacidade única `marina_readonly` e expiração `2027-02-01T00:00:00Z`**. Sem ampliação para shell, sudo, comandos, arquivos, instalação, banco, workers ou mutação.

- Criar a PR em **Draft** e solicitar revisão formal exclusivamente ao `shopvivaliz-human`. Outros agentes podem testar, documentar e aplicar correções técnicas, mas **não** aprovar pelo proprietário, marcar Ready arbitrariamente, habilitar auto-merge ou mesclar o grant.
- Depois da revisão `APPROVED` no **SHA final**, o proprietário deve **desabilitar auto-merge** da própria PR, marcar Ready e selecionar manualmente **Merge pull request**. O readback deve mostrar `merged_by=shopvivaliz-human` e `auto_merge=null`, sem substituir ou simular essa ação.
- Rodar o validador proprietário no SHA exato, com proveniência de arquivo adicionado pela PR, checks verdes e ausência de alterações no grant já integrado. O merge manual fornece somente a prova de intenção, não permissões SSH/RBAC da VM.
- Usar o ativador canônico em canal de manutenção autorizado para **pré-validação somente leitura**. Exigir checkout limpo do exato `main`, código instalado idêntico, chave e host-key pinadas com permissões corretas, identidade efetiva e ausência de recusas do provedor.
- Ativar somente se TODAS as condições passarem, com lease/rollback, expiração efetiva, `hosts_list`, `host_health`, `service_status` e testes negativos. Caso haja recusa da plataforma ou falta de concessão externa, manter Marina desabilitada e registrar bloqueio. Nunca usar SSH/RDC alternativo para contornar recusa explícita do MCP.
- O MCP independente da Marina continua uma obrigação separada, com credenciais/túnel OpenAI próprios. Este grant de leitura **não** autoriza operação administrativa nem paridade total de 29 métodos RDC.

## Critério objetivo de encerramento

`GRANT_OWNER_MANUAL=PASS` **somente** após review atual, merge manual efetivo, verificação imutável, runtime permitido e E2E pelo **próprio MCP central**. Até lá, `MCP_CENTRAL_MARINA=NAO_APTO`. A existência deste texto ou de aprovação em conversa não é autorização técnica para sobrepor o validador.
