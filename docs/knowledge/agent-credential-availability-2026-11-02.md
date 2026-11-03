# ShopVivaliz — disponibilidade de credenciais de agentes (sem segredos)

Atualizado em 2026-11-03 (verificacoes complementares). **Este inventario documenta somente nomes, locais de armazenamento e controles. Nao e um cofre.** Nenhum token, senha, seed TOTP, cookie ou chave privada pertence ao Git, a issues, a PRs, a logs ou ao chat.

## Inventario verificado

| Nome logico | Armazenamento | Estado verificado | Consumidor autorizado |
| --- | --- | --- | --- |
| `OPENAI_ADMIN_KEY` | Windows: `%USERPROFILE%\.shopvivaliz\secrets\OPENAI_ADMIN_KEY.dpapi` (DPAPI vinculado ao usuario); GitHub Actions Secret `OPENAI_ADMIN_KEY` em `Vivaliz-site/site-shopvivaliz`; Marina: `/home/ubuntu/.shopvivaliz/secrets/OPENAI_ADMIN_KEY` | Script PowerShell retornou `PROVISIONAMENTO_CONCLUIDO`; arquivo Marina `ubuntu:ubuntu 0600` confirmado no host | Somente operadores e workloads com necessidade demonstrada de usar a API administrativa da **OpenAI Platform**; nao habilita controles de tenant ChatGPT nem instala plugins |
| `SHOPVIVALIZ_GITHUB_TOKEN` | Windows: `%USERPROFILE%\.shopvivaliz\secrets\SHOPVIVALIZ_GITHUB_TOKEN.dpapi`; GitHub Actions Secret `SHOPVIVALIZ_GITHUB_TOKEN` no mesmo repositorio; Marina: `/home/ubuntu/.shopvivaliz/secrets/SHOPVIVALIZ_GITHUB_TOKEN` | Script PowerShell confirmou gravacao; arquivo Marina `ubuntu:ubuntu 0600`; API GitHub autenticou e confirmou leitura do grant e da PR #3560 | Automacoes GitHub que tenham sido explicitamente autorizadas, sujeitas aos escopos reais do PAT e ao ambiente de execucao |
| `SHOPVIVALIZ_GITHUB_ADMIN_TOKEN` | Windows: `%USERPROFILE%\.shopvivaliz\secrets\SHOPVIVALIZ_GITHUB_ADMIN_TOKEN.dpapi` (DPAPI); GitHub Actions Secret `SHOPVIVALIZ_GITHUB_ADMIN_TOKEN` em `Vivaliz-site/site-shopvivaliz`; Marina: `/home/ubuntu/.shopvivaliz/secrets/SHOPVIVALIZ_GITHUB_ADMIN_TOKEN` (`ubuntu:ubuntu 0600`) | **CONFIRMADO POR API GITHUB em 03/11/2026:** metadados de arquivo DPAPI, Actions Secret e arquivo Marina verificados; `GET /user` autenticado HTTP 200; leitura do grant #3560 HTTP 200, 11 repositorios Vivaliz-site listados. Fonte: issue #3152, comentario [checkpoint de credenciais](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3152#issuecomment-6593725652). **Nao prova permissao de escrita em todos os repositorios.** | Agentes tecnicos GitHub dentro dos repositorios e grants efetivamente autorizados; nunca usar no broker root read-only, nem para simular aprovacao de `shopvivaliz-human`. |
| `github-read-token` do broker root | Destino previsto: `/etc/shopvivaliz/marina-root-broker/github-read-token`, `root:root 0600`, exigido por `root_broker.py` | **NAO PROVISIONADO** na verificacao do host em 2026-11-02; broker systemd ainda nao instalado | Exclusivamente o broker root local para verificacao do owner grant, apos credencial propria de menor privilegio e preflight |

Os diretorios `/etc/shopvivaliz/agent-secrets` (`root:root 0700`) e `/home/ubuntu/.shopvivaliz/secrets` (`ubuntu:ubuntu 0700`) estao preparados; isso **nao** significa que haja secrets duplicados no primeiro diretorio. Os GitHub Actions Secrets nao se tornam automaticamente disponiveis para servicos Linux ou agentes ChatGPT.

## Restricao importante do PAT ja salvo

O valor existente em `SHOPVIVALIZ_GITHUB_TOKEN` foi identificado no host como token GitHub **fine-grained**, com formato de linha unica. A API autenticada retornou **11 entradas** em `/user/repos?per_page=100`; portanto, a verificacao **nao comprovou** a restricao exigida pelo broker a apenas `Vivaliz-site/site-shopvivaliz`. Ter acesso de leitura ao grant #3560 e a PR #3560 **nao comprova** o conjunto completo de escopos de um token.

**Nao copiar esse PAT automaticamente para `github-read-token` do broker root.** Primeiro confirmar efetivamente que a credencial destinada ao broker possui acesso apenas a `Vivaliz-site/site-shopvivaliz` com **Contents: Read** e **Pull requests: Read**, sem escrita e com expiracao/revogacao. Caso o PAT salvo tenha finalidade mais ampla, preservar sua funcao e provisionar outra credencial especificamente restrita, pelo mecanismo autorizado.

## Operacoes multirrepositorio

A finalidade pretendida de `SHOPVIVALIZ_GITHUB_ADMIN_TOKEN` e executar tarefas tecnicas nos **repositorios ShopVivaliz aos quais a identidade do token tenha acesso efetivo**. O alcance org-wide e permissoes administrativas nao decorrem do nome do secret nem do formato do PAT: consultar a configuracao oficial do token e testar uma operacao de leitura por repositorio antes do uso. Validar cada operacao pela identidade, necessidade, escopo, branch protections, regras de merge, revisoes humanas obrigatorias e grants vigentes.

O PAT `SHOPVIVALIZ_GITHUB_TOKEN` existente pode ser utilizado para tarefas em diversos repositorios conforme autorizacoes reais; o endpoint `/user/repos` apresentou 11 repositorios, mas isso **nao atesta capacidade administrativa em cada um**. Manter **separado** o token administrativo multi-repositorio do `github-read-token` dedicado a verificar o owner grant no broker root (somente Contents Read e Pull Requests Read no repositorio autorizador). Nunca distribuir tokens amplos automaticamente para workflows/servicos de todos os repositorios nem para o servidor HTTP MCP.

## Acesso para os agentes

1. Na retomada, consultar este arquivo e a documentacao canonica de `host-access.md` e `agent-rules.md`; fazer **preflight somente de metadados** antes de pedir uma credencial de novo.
2. Confirmar host, identidade do consumidor, necessidade, escopo efetivo, validade, ownership, tipo de arquivo e permissoes. Windows DPAPI so pode ser aberto pela conta Windows vinculada; o MCP independente Marina executa como `shopvivaliz-marina-mcp`, **nao** como `ubuntu` nem `root`, e nao recebe acesso implicito a `/home/ubuntu/.shopvivaliz/secrets`.
3. Usar gerenciadores de secrets ou mecanismo de injeção autorizado ao **processo especifico**. Nao exibir valores nem copiar tokens entre perfis, contas, VMs ou servicos sem autorizacao e justificativa. Nao colocar tokens em argumentos CLI, linhas de comando, `.env` versionado, logs ou conteudo de respostas.
4. O MCP Marina deve permanecer isolado (loopback, usuario de servico, `NoNewPrivileges=yes`). Acoes administrativas passam por broker root AF_UNIX separado, com grant exato, autenticação, lease/fencing, auditoria e testes. **Nunca** executar o servidor HTTP MCP como root ou ativar shell/sudo universal.
5. A permissao humana #3560 autoriza **apenas** `olist_browser_restart` na unit `amazon-returns-olist-erp-browser.service`; nao concede administracao geral. Revalidar o grant e a politica do provedor imediatamente antes de qualquer acao.
6. Ao rotacionar ou revogar credenciais, atualizar **metadados** deste inventario e confirmar consumidores sem revelar o segredo. Nao supor que arquivos locais existam em sessoes futuras sem verificacao.

## Referencias

- `docs/ops/marina-root-broker-activation-2026-11-02.md`
- `marina-direct-mcp/README.md`
- `security/owner-approvals/3560.json`
- `docs/knowledge/security-approval-via-pr.md`
- `docs/knowledge/agent-rules.md`

Status atual: **credenciais gerais armazenadas e comprovadas**; **broker root sem credencial dedicada validada/instalada**; **nao foi certificado uso administrativo dos plugins ChatGPT**.
