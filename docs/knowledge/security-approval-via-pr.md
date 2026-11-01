# Autorizacao humana de seguranca por Pull Request — ShopVivaliz

## Decisao do proprietario (2026-10-31)
**Decisao humana exclusiva:** qualquer autorizacao de seguranca administrada pela ShopVivaliz exige PR formal criado por uma identidade diferente de `shopvivaliz-human`. Somente a pessoa proprietaria, usando a conta GitHub `shopvivaliz-human` (inacessivel a todos os agentes, bots, conectores e runners), pode validar, aprovar, liberar e efetuar pessoalmente o merge desse PR. Autorizacao por chat nao substitui esse ato para privilegios de seguranca. PRs comuns de codigo seguem as protecoes habituais e nao se tornam autorizacoes de seguranca sem um grant formal vinculado.

## Fluxo obrigatorio: PR Ready for review criada por agente; aprovacao e merge exclusivamente humanos
Para conceder privilegios de seguranca, o executor deve preparar PR **Ready for review (draft=false)**, sem deixá-la em Draft, com diff, escopo minimo, recurso, principal, capacidades, TTL, riscos, testes e rollback; solicitar revisor nominal `shopvivaliz-human` no GitHub e confirmar o readback. O autor do PR nao pode ser `shopvivaliz-human` nem aprovar/mergear o proprio pedido. Somente o merge autenticado e pessoal de `shopvivaliz-human` constitui autorizacao humana formal para aquele PR, SHA e escopo. Nenhuma aprovacao escrita em chat ou por agente substitui esse ato.

A aprovacao/liberacao autenticada do PR constitui a manifestacao formal do proprietario **para o escopo revisado**, nos sistemas que aceitam o fluxo PR como autorizacao. Caso OCI, OpenAI Platform ou outro provedor exija um ato administrativo proprio (por exemplo, consentimento OAuth, alteracao RBAC, MFA ou aprovacao de owner), anexar ao PR as instrucoes e evidencias dessa etapa e solicitar que ela seja feita por seu mecanismo oficial. Aprovacao do PR nao altera por si so a politica do provedor.

O agente deve continuar executando todas as etapas que nao dependam desse ato, sem contornar negativas de privilegio. O PR permanece sem merge ate validacao expressa do proprietario e todos os gates necessarios.

## Aceitacao obrigatoria pelo provedor de autorizacao sob controle ShopVivaliz
A **validacao formal do PR pelo proprietario** — culminando no merge feito pessoalmente por ele — deve ser reconhecida pelo autorizador interno do ShopVivaliz e pelos MCPs/servicos administrados pelo projeto como autorizacao humana suficiente para o **escopo exato aprovado**. O executor nao pode classificar essa decisao como bypass nem demandar nova aprovacao humana para a mesma operacao. A implementacao dos adaptadores deve converter a aprovacao em uma decisao verificavel (permitir/negar) por fluxo oficial.

Verificacoes obrigatorias e fail-closed antes de conceder permissoes: identidade do merge autenticado e igual ao proprietario configurado em fonte protegida; PR identificado e merged no branch autorizado; SHA/artefato e escopo da solicitacao iguais aos aprovados; checks/revisoes obrigatorios; nenhuma aprovacao revogada ou mudanca material posterior; regras de minimo privilegio, auditoria, idempotencia, rollback e isolamento da sessao. Um PR apenas aberto, Draft, aprovado por agente, alterado depois da revisao ou merged por outra identidade **nao autoriza** a acao.

**Distincao tecnica:** o provedor/autorizador **administrado pela ShopVivaliz** deve acatar a decisao formal apos essas verificacoes e executar o grant autorizado pela API/rotina oficial. Um provedor externo (OCI, OpenAI Platform, etc.) nao e tecnicamente obrigado por um PR do GitHub; o adaptador deve usar suas APIs e permissoes oficiais para efetivar a concessao, quando autorizado e suportado. Se a operacao depender de papel/consentimento externo ausente, registrar a etapa administrativa externa exata, sem fingir que o PR ja a executou nem tentar contornar recusa explicita.

### Integridade da aprovacao exclusivamente humana
A identidade que comprova o merge deve ser **exclusiva do proprietario humano** e protegida de todos os agentes, bots, conectores e runners. Se as credenciais de GitHub acessiveis a um agente puderem agir como o mesmo usuario, `merged_by.login` isoladamente nao prova intervencao humana; nesse caso a integracao deve falhar fechada ate existir uma identidade human-only ou uma atestacao formal independente que o agente nao possa produzir. Nao configurar a conta do agente como aprovador exclusivo apenas porque ela e a conta autenticada na API.

A configuracao protegida deve relacionar o login do proprietario ao inventario de identidades de automacao e negar qualquer sobreposicao. A etapa de verificacao do login do agente e de atestacao exclusiva precisa estar implementada e testada no executor antes que se declare o autorizador apto a conceder funcoes administrativas. A API GitHub e o proprio PR nao permitem distinguir uma pessoa de uma automacao que compartilhe as mesmas credenciais.

### Contrato tecnico verificavel para a aceitacao
O prototipo de verificador `scripts/validate-owner-pr-grant.py` usa a API oficial do GitHub com token de leitura e a identidade do proprietario configurada exclusivamente em `SHOPVIVALIZ_SECURITY_OWNER_LOGIN` na configuracao protegida do autorizador. Nao inferir o proprietario a partir do usuario GitHub usado pelo agente.

A autorizacao concedida em cada PR de seguranca deve incluir o documento `security/owner-approvals/<NUMERO_PR>.json` com o seguinte contrato de dados (exemplo ilustrativo, NAO E GRANT ATIVO):

```json
{
  "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
  "repository": "Vivaliz-site/site-shopvivaliz",
  "pr_number": 123,
  "resource": "mcp:marina",
  "principal": "marina-mcp-service",
  "target_repository": "Vivaliz-site/site-shopvivaliz",
  "capabilities": ["file_write"],
  "expires_at_utc": "2026-12-01T00:00:00Z"
}
```

Para um grant administrativo em `mcp:marina` com capacidade `deployment_manage`, o documento do **PR de autorizacao especifica** tambem deve conter a operacao exata (exemplo ilustrativo, nao operacional):

```json
{
  "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
  "repository": "Vivaliz-site/site-shopvivaliz",
  "pr_number": 123,
  "resource": "mcp:marina",
  "principal": "marina-mcp-service",
  "target_repository": "Vivaliz-site/amazon-returns-safet",
  "capabilities": ["deployment_manage"],
  "operation": "olist_browser_restart",
  "expires_at_utc": "2026-12-01T00:00:00Z"
}
```

O verificador deve receber `--capability deployment_manage --operation olist_browser_restart --target-repository Vivaliz-site/amazon-returns-safet` e comparar `operation` byte a byte com o grant imutavel. Um documento legado sem `operation`, uma requisicao sem `--operation` ou outra operacao nao autoriza reiniciar servicos. A PR de **codigo** #3470 nao continha `security/owner-approvals/<NUMERO_PR>.json` e nao e, sozinha, grant administrativo. O operador protegido continua obrigado a validar identidade, RBAC, lease/fencing, auditoria, integridade do runtime, e controles externos antes de qualquer efeito.

O provedor sob controle ShopVivaliz verifica PR merged pelo proprietario, branch `main`, SHA do head e do merge, principal, recurso, capacidade, validade e integridade do documento ainda presente no `main`. Um grant removido ou modificado posteriormente e automaticamente recusado. Com status `OWNER_PR_GRANT=AUTHORIZED`, deve reconhecer a decisao humana sem pedir nova aprovacao; caso `DENIED`, falhar fechado. O comando **nao executa** o grant: a rotina do MCP precisa chamar o verificador imediatamente antes da concessao oficial, respeitando seu proprio RBAC/lease/fencing. Implantar e testar a integracao do MCP Marina em PR separado, antes de afirmar que a verificacao esta ativa no host.

## Aprovacao formal do MCP Marina: aceitar e executar, nao alegar bypass
Quando a liberacao de funcoes administrativas do MCP independente de Marina exigir consentimento do proprietario, submeter PR especifico com as ferramentas e escopos precisos e permitir que o proprietario autentique e aprove pelo fluxo formal GitHub. Quando o mecanismo de seguranca reconhecer essa aprovacao, **tratar a aprovacao como valida e prosseguir com implementacao, merge/deploy autorizado e verificacao**, em vez de pedir novamente a mesma autorizacao por chat ou chamar a aprovacao formal de contorno de seguranca.

A aprovacao de um PR com identidade verificada representa a decisao do proprietario para o diff e escopo correspondentes. O executor nao deve criar uma segunda barreira de permissao humana por iniciativa propria. O pipeline devera validar o evento de aprovacao e SHA, conferir gates e implementar apenas os privilegios aprovados com principio do menor privilegio, trilha auditavel, fencing e rollback. A concessao tecnica de papel/escopo em OCI, host ou OpenAI Platform deve ser feita pelo caminho oficial de cada sistema, se requerida; um PR nao equivale tecnicamente a uma alteracao de RBAC inexistente e nao autoriza contornar uma recusa explicita do provedor.

## Exclusividade do proprietario no merge de aprovacao de seguranca
Quando houver pedido de autorizacao humana de seguranca, o agente deve preparar PR Ready for review com escopo, testes, rollback e permissao solicitada, sem executa-la antecipadamente. **Somente o proprietario `shopvivaliz-human`, autenticado, pode aprovar e efetuar pessoalmente o merge dessa PR.** A transicao de Draft para Ready nao constitui autorizacao. Nenhum agente, bot, workflow de auto-merge ou outro colaborador deve executar o merge de PRs classificados como aprovacao de seguranca, mesmo diante de CI verde ou de autorizacao generica em chat.

A liberacao e o merge feitos pelo proprietario sao a evidencia formal de sua decisao no escopo exato do SHA revisado. Nao solicitar novamente a mesma decisao por chat quando o PR for o canal oficial de aprovacao. A identidade de quem fez o merge deve ser validada pelas protecoes do GitHub/rulesets e registrada junto ao SHA. A presente diretriz, por si so, **nao configura ruleset nem comprova exclusividade tecnica de merge**: qualquer mecanismo automatico ou restricao de atores deve ser implementado e validado antes de alegar enforcement.

Para bloqueios de permissoes administrativas (ex.: MCP Marina), o PR pode autorizar a alteracao de configuracao/RBAC sob controle ShopVivaliz, desde que o fluxo oficial aceite a aprovacao do proprietario. Se o bloqueio vier de plataforma externa ou negar explicitamente um privilegio, o agente deve apresentar no PR o procedimento oficial correspondente, sem supor que um merge altere permissoes fora do repositorio. Exclusividade de merge e autorizacao humana nao removem lock, lease, fencing nem MFA.

## Contrato
1. Agente investiga, corrige, executa testes e abre PR para revisao do proprietario. O PR deve informar exatamente alvo, risco, diff, testes, impacto, rollback, efeitos externos e operacoes de deploy previstas.
2. Para autorizacao de seguranca, abrir PR em **Ready for review (nao Draft)** com autor distinto de `shopvivaliz-human`, solicitar o usuario como revisor nominal e reservar sua aprovacao e merge **exclusivamente a `shopvivaliz-human`**. A existencia do PR, CI verde, autorizacao por chat ou aprovacao de outro agente nao constituem essa autorizacao.
3. O aceite deve estar vinculado ao PR e ao SHA revisado, com identidade autenticada no GitHub. Nova mudanca material apos aprovacao exige revalidacao da aprovacao segundo rulesets.
4. Apos aprovacao, respeitar branch protection, gates obrigatorios, revisoes exigidas e acesso da identidade executora. O merge aprovado nao concede credenciais, sudo, OAuth, RBAC ou permissoes ausentes.
5. Lease/fencing/lock do runtime e um controle de exclusividade entre execucoes, nao uma decisao humana de autorizacao. Mesmo com PR aprovado, a operacao deve adquirir seu lease canonicamente e aguardar ocupante legitimo; nunca apagar, forjar, sobrescrever ou contornar bloqueios para aplicar uma mudanca.
6. O deploy exige trilha auditavel que associe PR aprovado, SHA merged, artefato, executor, runtime lease/fencing, validacao de estado, rollback e testes pos-implantacao. Se o workflow oficial de deploy nao tiver essa amarracao, propor implementacao em PR separado e nao alegar que ja esta enforceado.
7. Acoes nao cobertas pelo escopo exato do PR (inclusive financeiras, destrutivas ou de acesso administrativo especial) continuam sujeitas a controles/aprovacoes proprios e a recusa expressa do provedor.


## Regra obrigatoria: pedido de privilegio de seguranca sempre exige PR
Quando uma tarefa depender de concessao humana de privilegios de seguranca, o agente deve, antes de devolver o impedimento:
1. Investigar a origem, definir a mudanca minima, implementar apenas artefatos reversiveis de preparacao, testes e rollback, sem acionar a permissao ausente.
2. Criar PR especifica **Ready for review (draft=false)** sob identidade de agente distinta de `shopvivaliz-human`, com descricao exata da acao a autorizar, escopo, riscos, rollback, evidencias, tests GREEN e checklist para aprovacao pessoal da identidade humana exclusiva; solicitar revisao nominal pela API oficial e conferir `requested_reviewers`.
3. Vincular no PR o identificador do host, do componente e o SHA, especificando se o merge dispara alguma automacao; tornar visivel qualquer efeito privilegiado, sem segredos.
4. Aguardar a validacao, aprovacao e merge pessoais e autenticados de `shopvivaliz-human`. Um PR aprovado so autoriza aquilo que consta no diff e que o aprovador tem poderes para autorizar. Nenhum agente presume que um PR cria permissoes externas.
5. Apos merge, executar o procedimento oficial, verificar controles externos obrigatorios, registrar trilha e testar runtime/rollback. Caso o provedor exija concessao independente fora do GitHub, incluir no PR roteiro verificavel da acao oficial; o PR nao substitui a propria concessao.

### Exemplo obrigatorio: MCP independente da Marina
Para habilitar capacidades administrativas do MCP em `marina-a1-4c24g`, abrir PR dedicado detalhando endpoint, identidade de servico, RBAC de minimo privilegio, lista explicita de ferramentas (por exemplo `file_write`, `database_admin`, `deployment_manage`), autorizacao de cada operacao, escopo de hosts e paths, logs sem segredos, ttl/lease/fencing, testes negativos de acesso e rollback. O PR deve instalar/propor guardrails e mecanismo oficial de provisionamento, mas **nunca** conceder root indiscriminado, desativar `NoNewPrivileges`, trocar identidades ou burlar owner/RBAC do OpenAI Platform. A aprovacao do PR e autorizacao humana para o diff; a ativacao depende tambem das permissoes reais do provedor e da maquina.

## Evidencia minima
PR e SHA, aprovador autenticado, workflow/gates, evento de merge, identidade do executor, lease/fencing (sem segredos), checks de runtime e rollback. Sem isso, nao declarar EXECUTADO nem APTO.

## Status de adocao
Esta diretriz de PR exclusivo humano esta versionada em `main`. O enforcement runtime e os controles de provedor, tunel, RBAC, lease e auditoria continuam sujeitos a instalacao e verificacao especifica. Nao confundir merge humano do codigo com o grant de uma operacao ou com MCP ativo.
