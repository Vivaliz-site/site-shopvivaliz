# Gate de preflight da Auditoria Extrema V5 e PRs sem abandono

Este contrato e obrigatorio para ChatGPT, Gepeto, Codex, Claude, Gemini, subagentes, controladores e automacoes. Nao basta reconhecer o nome da regra.

## Preflight fail-closed antes de qualquer escrita
1. Ler realmente no main vigente AGENTS.md, AUDIT_POLICY.md, docs/knowledge/atendimento-chatgpt-bootstrap.md, docs/knowledge/host-access.md, docs/knowledge/README.md e docs/knowledge/agent-rules.md; depois ler os protocolos de qualidade vinculados, o overlay e as regras locais do repositorio alvo.
2. Persistir rules_loaded por arquivo com path, ref main, blob SHA, instante, identidade da sessao/agente, finalidade, e resultado read -> acknowledge -> verify. Nao reaproveitar recibos antigos se o SHA mudou.
3. Sem prova valida, RULES_NOT_LOADED: somente diagnostico read-only. Um ACK escrito pelo proprio agente sem verificacao nao vale.
4. Antes de cada mutacao obter lease/ownership/fencing quando aplicavel, conferir scope, branch e head SHA; preservar MFA, approvals e protecoes de branch.
5. Auditoria V5 formal exige manifest de evidencia, regressao, impacto, validacao real de UI quando aplicavel e veredito calculado pelo certifier; APTO nao pode ser autodeclarado.

## Inventario e reconciliacao individual de PRs
- Inventariar paginado TODOS os PRs abertos em TODOS os repositorios governados, mantendo checkpoint por repo/numero/head SHA/base SHA e ultimo evento.
- Para cada PR comparar diff e main atual, dependencias, concorrentes, equivalentes, risco, tests/check runs obrigatorios, review threads e conflitos. Classificar util, parcialmente aproveitavel, superseded, regressao ou bloqueio externo COM prova.
- Conflitos: preservar trabalho valido de ambos os lados, testar a versao reconciliada. Nunca fechar um PR com codigo aproveitavel sem portar a parte valida e comprovar sua integracao; nao fechar por idade.
- Checks faltantes/falhando: corrigir workflow, runner, escopo ou codigo; nunca fabricar status, remover check obrigatorio, fazer push direto a main ou criar commit vazio repetitivo como resolucao.
- Integrar apenas PR com TODOS os gates obrigatorios aplicaveis, protecoes, revisoes, head SHA fresco e evidencias satisfeitas. Validar main e deploy/runtime quando aplicavel; nao parar no merge.
- Cada PR nao terminal deve ter checkpoint duravel RUNNING/BLOCKED_EXTERNAL/AWAITING_APPROVAL, causa, owner, next_action especifica e condicao verificavel de retomada.
- Repetir varredura ate nao restarem PRs abandonados, inclusive apos mudanca de SHA. O controlador unico e responsavel por continuar o trabalho; vigias nao podem duplicar escritores.
- Aplicar gates proporcionais ao mapa de impacto e dependencias indiretas; evitar testes de sistemas sem impacto comprovado. A decisao de exclusao deve ter evidencia.
- A auditoria da auditoria deve demonstrar falha em casos deliberados: rules_loaded ausente/desatualizado, PR sem owner/next_action, check obrigatorio ausente, conflito, evidencias de outro SHA, tentativa de APTO sem certifier e exclusao indevida de dependencia.

## Estado e certificacao
Nenhuma alegacao de 100%, zero PRs, APTO ou CONCLUIDO sem inventario atual, evidencia por PR, checks e veredito oficial no SHA/ambiente/escopo real. Bloqueios externos devem conter evidencia de esgotamento de rotas autorizadas, sem bypass.
