# Autorizacao humana de seguranca por Pull Request — ShopVivaliz

## Decisao do proprietario (2026-10-31)
A validacao e liberacao **expressa** pelo proprietario em Pull Request e o mecanismo oficial de autorizacao humana para mudancas sensiveis de codigo, politica e infraestrutura ShopVivaliz, quando a organizacao e a plataforma reconhecerem tal aprovacao.

## Contrato
1. Agente investiga, corrige, executa testes e abre PR para revisao do proprietario. O PR deve informar exatamente alvo, risco, diff, testes, impacto, rollback, efeitos externos e operacoes de deploy previstas.
2. Para mudanca sensivel, abrir PR em **Draft** e **nao realizar merge** ate aprovacao verificavel do proprietario. A existencia do PR, CI verde ou aprovacao de outro agente nao constituem autorizacao.
3. O aceite deve estar vinculado ao PR e ao SHA revisado, com identidade autenticada no GitHub. Nova mudanca material apos aprovacao exige revalidacao da aprovacao segundo rulesets.
4. Apos aprovacao, respeitar branch protection, gates obrigatorios, revisoes exigidas e acesso da identidade executora. O merge aprovado nao concede credenciais, sudo, OAuth, RBAC ou permissoes ausentes.
5. Lease/fencing/lock do runtime e um controle de exclusividade entre execucoes, nao uma decisao humana de autorizacao. Mesmo com PR aprovado, a operacao deve adquirir seu lease canonicamente e aguardar ocupante legitimo; nunca apagar, forjar, sobrescrever ou contornar bloqueios para aplicar uma mudanca.
6. O deploy exige trilha auditavel que associe PR aprovado, SHA merged, artefato, executor, runtime lease/fencing, validacao de estado, rollback e testes pos-implantacao. Se o workflow oficial de deploy nao tiver essa amarracao, propor implementacao em PR separado e nao alegar que ja esta enforceado.
7. Acoes nao cobertas pelo escopo exato do PR (inclusive financeiras, destrutivas ou de acesso administrativo especial) continuam sujeitas a controles/aprovacoes proprios e a recusa expressa do provedor.

## Evidencia minima
PR e SHA, aprovador autenticado, workflow/gates, evento de merge, identidade do executor, lease/fencing (sem segredos), checks de runtime e rollback. Sem isso, nao declarar EXECUTADO nem APTO.

## Status de adocao
Esta diretriz e proposta em PR, nao esta efetiva ate merge autorizado. Enforcements runtime/CI ainda exigem verificacao especifica.
