# Autorizacao humana de seguranca por Pull Request — ShopVivaliz

## Decisao do proprietario (2026-10-31)
A validacao e liberacao **expressa** pelo proprietario em Pull Request e o mecanismo oficial de autorizacao humana para mudancas sensiveis de codigo, politica e infraestrutura ShopVivaliz, quando a organizacao e a plataforma reconhecerem tal aprovacao.

## Gatilho obrigatorio: chat nao aceito como autorizacao formal
Sempre que uma regra de seguranca, RBAC, governanca, infraestrutura ou provedor exigir **acao direta e autenticada do proprietario** e **nao aceitar autorizacao escrita no chat** como prova suficiente, o agente deve obrigatoriamente preparar um PR Draft de aprovacao com a mudanca proposta, testes, impacto e rollback. Nao repetir pedidos de autorizacao pelo chat nem encerrar apenas com o bloqueio.

A aprovacao/liberacao autenticada do PR constitui a manifestacao formal do proprietario **para o escopo revisado**, nos sistemas que aceitam o fluxo PR como autorizacao. Caso OCI, OpenAI Platform ou outro provedor exija um ato administrativo proprio (por exemplo, consentimento OAuth, alteracao RBAC, MFA ou aprovacao de owner), anexar ao PR as instrucoes e evidencias dessa etapa e solicitar que ela seja feita por seu mecanismo oficial. Aprovacao do PR nao altera por si so a politica do provedor.

O agente deve continuar executando todas as etapas que nao dependam desse ato, sem contornar negativas de privilegio. O PR permanece sem merge ate validacao expressa do proprietario e todos os gates necessarios.

## Contrato
1. Agente investiga, corrige, executa testes e abre PR para revisao do proprietario. O PR deve informar exatamente alvo, risco, diff, testes, impacto, rollback, efeitos externos e operacoes de deploy previstas.
2. Para mudanca sensivel, abrir PR em **Draft** e **nao realizar merge** ate aprovacao verificavel do proprietario. A existencia do PR, CI verde ou aprovacao de outro agente nao constituem autorizacao.
3. O aceite deve estar vinculado ao PR e ao SHA revisado, com identidade autenticada no GitHub. Nova mudanca material apos aprovacao exige revalidacao da aprovacao segundo rulesets.
4. Apos aprovacao, respeitar branch protection, gates obrigatorios, revisoes exigidas e acesso da identidade executora. O merge aprovado nao concede credenciais, sudo, OAuth, RBAC ou permissoes ausentes.
5. Lease/fencing/lock do runtime e um controle de exclusividade entre execucoes, nao uma decisao humana de autorizacao. Mesmo com PR aprovado, a operacao deve adquirir seu lease canonicamente e aguardar ocupante legitimo; nunca apagar, forjar, sobrescrever ou contornar bloqueios para aplicar uma mudanca.
6. O deploy exige trilha auditavel que associe PR aprovado, SHA merged, artefato, executor, runtime lease/fencing, validacao de estado, rollback e testes pos-implantacao. Se o workflow oficial de deploy nao tiver essa amarracao, propor implementacao em PR separado e nao alegar que ja esta enforceado.
7. Acoes nao cobertas pelo escopo exato do PR (inclusive financeiras, destrutivas ou de acesso administrativo especial) continuam sujeitas a controles/aprovacoes proprios e a recusa expressa do provedor.


## Regra universal: toda intervencao humana requerida vira PR de autorizacao
Quando QUALQUER tarefa depender de intervencao do proprietario — liberacao administrativa, configuracao privilegiada, permissoes de MCP, alteracao de seguranca, acesso a API, infraestrutura, deploy, credenciais/provisionamento ou confirmacao operacional — o agente deve, antes de devolver o impedimento:
1. Investigar a origem, definir a mudanca minima, implementar apenas artefatos reversiveis de preparacao, testes e rollback, sem acionar a permissao ausente.
2. Criar PR Draft especifico com descricao exata da acao a autorizar, escopo, riscos, rollback, evidencias, tests GREEN e checklist verificavel de aprovacao do proprietario.
3. Vincular no PR o identificador do host, do componente e o SHA, especificando se o merge dispara alguma automacao; tornar visivel qualquer efeito privilegiado, sem segredos.
4. Aguardar a validacao e liberacao explicitas do proprietario por review/merge. Um PR aprovado so autoriza aquilo que consta no diff e que o aprovador tem poderes para autorizar. Nenhum agente presume que um PR cria permissoes externas.
5. Apos merge, executar o procedimento oficial, verificar controles externos obrigatorios, registrar trilha e testar runtime/rollback. Caso o provedor exija concessao independente fora do GitHub, incluir no PR roteiro verificavel da acao oficial; o PR nao substitui a propria concessao.

### Exemplo obrigatorio: MCP independente da Marina
Para habilitar capacidades administrativas do MCP em `marina-a1-4c24g`, abrir PR dedicado detalhando endpoint, identidade de servico, RBAC de minimo privilegio, lista explicita de ferramentas (por exemplo `file_write`, `database_admin`, `deployment_manage`), autorizacao de cada operacao, escopo de hosts e paths, logs sem segredos, ttl/lease/fencing, testes negativos de acesso e rollback. O PR deve instalar/propor guardrails e mecanismo oficial de provisionamento, mas **nunca** conceder root indiscriminado, desativar `NoNewPrivileges`, trocar identidades ou burlar owner/RBAC do OpenAI Platform. A aprovacao do PR e autorizacao humana para o diff; a ativacao depende tambem das permissoes reais do provedor e da maquina.

## Evidencia minima
PR e SHA, aprovador autenticado, workflow/gates, evento de merge, identidade do executor, lease/fencing (sem segredos), checks de runtime e rollback. Sem isso, nao declarar EXECUTADO nem APTO.

## Status de adocao
Esta diretriz e proposta em PR, nao esta efetiva ate merge autorizado. Enforcements runtime/CI ainda exigem verificacao especifica.
