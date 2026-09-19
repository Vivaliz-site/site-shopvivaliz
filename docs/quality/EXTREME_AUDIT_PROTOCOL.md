# Protocolo Universal de Auditoria Extrema — Zero Blind Spots

## Missão
Atue sob três lentes obrigatórias: **Auditor** (conformidade, segurança, integridade e regras), **Consultor** (risco, negócio, UX, custo e produtividade) e **Operador** (reprodução, correção, testes e validação real). O objetivo é: **MAPEAR → QUESTIONAR → REPRODUZIR → PROVAR → CLASSIFICAR → CORRIGIR → BUSCAR EQUIVALENTES → TESTAR → DEPLOYAR → OBSERVAR → RECONCILIAR → REGREDIR → REAUDITAR → META-AUDITAR**.

## Regras fundamentais
- Não presuma que documentação, nome de função, teste verde, HTTP 200, botão visível, migration, worker configurado ou serviço `active` provam comportamento correto.
- Classifique evidência como `COMPROVADO`, `FORTE EVIDÊNCIA`, `HIPÓTESE A VALIDAR` ou `NÃO VALIDADO`.
- Tente refutar achados relevantes antes de registrá-los e tente quebrar áreas consideradas corretas.
- Este protocolo é piso mínimo, nunca teto.

## Reconstrução do sistema real
Compare **documentação × código × testes × schema/migrations × dados × configuração × CI/CD × infraestrutura × processos ativos × produção × histórico Git**. Mapeie entidades, estados, transições, APIs, telas, jobs, filas, cron/schedulers, webhooks, integrações, storage, caches, feature flags, scripts, backups, observabilidade e efeitos externos. Procure config drift, hotfixes, serviços legados e jobs duplicados fora do fluxo versionado.

## Fluxos, lineage e espaço negativo
Para cada fluxo crítico trace `entrada → validação → persistência → processamento → decisão → efeito externo → confirmação → reconciliação → encerramento`. Identifique produtor, consumidor, idempotência, timeout, retry/backoff, deduplicação, compensação, observabilidade, owner, falha e recuperação.
Procure também o que deveria existir e não existe: produtor sem consumidor, consumidor sem produtor, estado sem saída/timeout/owner, fila sem worker, evento sem listener, botão sem backend, endpoint sem caller, job não agendado, dado nunca reconciliado, falha nunca tratada e ausência de watchdog/dead-letter/rollback/cleanup/alerta. Pergunte sempre: quem detecta quando algo esperado não acontece e quem corrige?

## Invariantes e máquina de estados
Derive propriedades que jamais podem ser violadas e monte `Invariante | Garantia técnica | Teste | Como quebrar | Evidência`. Tente quebrá-las. Audite Create/Read/Update/Delete/Archive/Restore/Cancel/Reopen/Retry/Undo conforme aplicável. Mapeie `estado atual → evento → condição → próximo estado`; procure estados inalcançáveis, eternos, sem saída, saltos indevidos, reversões ausentes e registros fantasmas presos.

## Pilares técnicos obrigatórios quando aplicáveis
Audite: lógica/matemática/moeda/datas/timezone; concorrência/idempotência; autenticação/autorização/RBAC/IDOR/tenant isolation/OWASP; privacidade/LGPD; constraints/FKs/índices/transações/migrations; integração entre módulos; resiliência a timeout/429/5xx/restart/processamento parcial; UX/acessibilidade/prevenção de erro; performance/capacidade/saturação; logs/métricas/tracing/alertas/healthchecks; CI/CD/rollback/config drift/feature flags; dependências/lockfiles/imagens/actions/licenças/supply chain.

## Tempo, ordem, dados e compatibilidade
Teste T-1/T/T+1 para prazos, timezone, viradas de calendário, eventos duplicados/atrasados/fora de ordem e versões diferentes de schema/API/eventos/backend/frontend/worker. Quando autorizado, investigue dados reais: duplicidades, órfãos, estados impossíveis, nulls inesperados, divergências, timestamps incoerentes, filas acumuladas e entidades que entram no funil mas desaparecem antes do final. Código correto não prova banco íntegro.

## Integrações e efeitos externos
Valide `input → transformação → request → aceite → persistência → confirmação do efeito → reconciliação`. Não confunda request enviada, HTTP 200, aceita, processada e efeito efetivamente realizado. Verifique auth/expiração, paginação, rate limit, timeout, retry, idempotência, webhook perdido/duplicado, mudança de schema e reconciliação independente.

## Testar os próprios testes
Pergunte: **se o código estivesse errado, esta suíte perceberia?** Use, quando apropriado, property-based testing, contract testing, mutation testing, fuzzing e fault injection. Procure asserts inúteis, mocks excessivos, testes ignorados/flaky e failure paths não cobertos.

## Capacidade, time bombs, backup e produção
Determine o primeiro recurso a saturar: CPU, RAM, disco, pool, fila, quota, rate limit, storage ou dependência. Procure deterioração silenciosa e expiração futura de certificados, tokens, domínios, credenciais, secrets e licenças. Backup só é comprovado por `backup → integridade → retenção → restore → validação`; sem restore, marque `RECUPERAÇÃO NÃO COMPROVADA`. Em produção prove `commit → build → artefato → release → deploy → processo ativo`; sem prova, marque `VERSÃO EM PRODUÇÃO NÃO COMPROVADA`.

## Segurança adversarial, risco e custo
Avalie abuso de funcionalidade legítima, escalada horizontal/vertical, manipulação de IDs/tenant/owner, mass assignment, fraude interna/externa e matriz de autorização. Priorize perda financeira, cobrança/reembolso duplicado, prazo perdido, ação externa indevida, dado não reconciliado, indisponibilidade, retrabalho e desperdício comprovado de polling/API/storage/logs/recursos.

## Formato dos achados
Registre ID, severidade `P0–P4`, probabilidade, blast radius, detectabilidade, confiança/evidência, arquivo/componente, visão Auditor, Consultor e Operador, reprodução, causa raiz, correção, teste antes/depois e risco de regressão. P0 = perda/corrupção/segurança crítica/indisponibilidade grave atual; P1 = alto impacto provável; P2 = falha relevante contornável; P3 = impacto limitado/dívida/UX/observabilidade; P4 = melhoria sem defeito ativo.

Além da severidade, classifique a natureza como `DEFECT`, `IMPROVEMENT_REQUIRED` ou `IMPROVEMENT_OPTIONAL`. Uma melhoria é `REQUIRED` quando fecha risco material de segurança, integridade, recuperação, observabilidade, idempotência, prevenção de recorrência ou operação crítica; caso contrário pode ser `OPTIONAL`.

Antes de alterar, classifique a correção como `SAFE`, `REVIEW`, `MIGRATION` ou `DESTRUCTIVE`; ação destrutiva exige autorização explícita.

## Remediação obrigatória e zero pendência crítica
Auditoria extrema não é um relatório de defeitos. Todo achado `SAFE` P0–P2 deve ser corrigido durante a própria auditoria, com reprodução antes, correção, teste depois, regressão e reauditoria. Achado `REVIEW/MIGRATION/DESTRUCTIVE` precisa de plano executável, owner, pré-condições, risco e evidência concreta do bloqueio.

`APTO` exige `P0=0`, `P1=0`, nenhum P2 material em fluxo crítico, nenhuma área crítica `NÃO VALIDADO`, nenhum `AUDIT_ESCAPE` aplicável sem reauditoria e nenhum `IMPROVEMENT_REQUIRED` que seja condição de segurança/integridade/recuperação. Não use `APTO COM RESSALVAS` para esconder pendência crítica.

## Busca sistêmica por equivalentes
Para cada achado confirmado, execute e registre `Achado → Classe de falha → Busca global → Ocorrências equivalentes → Correções → Testes → Reauditoria`. Corrigir somente o exemplo que revelou o defeito é insuficiente quando a classe puder se repetir em outras rotas, entidades, tenants, workers, integrações ou estados históricos.

## Observabilidade comprovada
Healthcheck, log, alerta, watchdog, dead-letter e dashboard só contam como proteção quando a auditoria prova que detectam a classe de falha relevante. Quando seguro, faça fault injection controlada e valide detecção, diagnóstico, alerta/encaminhamento e recuperação. Se não puder injetar a falha, registre dívida de evidência e use a prova operacional equivalente mais forte disponível.

## Pós-deploy: observar e reconciliar
Quando houver publicação, valide `commit → build → artefato → release → deploy → processo ativo → operação real → observação → efeito durável → reconciliação`. Para workers, filas, schedulers, webhooks e integrações críticas, prove ao menos uma execução produção-equivalente no release certificado por ciclo natural ou disparo controlado seguro. Erro assíncrono posterior invalida o veredito incompatível.

## Rollback, restore e retomada
Para mudança crítica, prove compatibilidade de recuperação entre aplicação, schema, eventos, filas, caches e dados. Quando aplicável, ensaie `deploy → mutação → rollback/restore → validação → retomada` em ambiente seguro apropriado. Não faça ação destrutiva em produção apenas para satisfazer o protocolo.

## Legado, duplicidade e concorrência operacional
Inventarie serviços, processos, timers, cron, schedulers, workflows, runners, scripts, consumers, bridges e automações que possam cumprir responsabilidade equivalente. Procure legado ainda ativo, jobs duplicados, polling redundante, concorrência, consumers órfãos, hotfixes fora do fluxo versionado e caminhos alternativos que ainda produzam efeitos.

## Reauditoria contraditória e cobertura
Após correções, execute regressão e nova rodada tentando provar que as conclusões estão erradas. Registre matriz `Auditada | Problemas | Corrigidos | Pendentes | Evidência` para backend, frontend, banco, APIs, jobs, queues, cron, webhooks, integrações, segurança, permissões, testes, CI/CD, infraestrutura, logs, monitoramento, backup/restore, UX, performance, dependências e documentação. Área não auditada deve aparecer com motivo.

## Gate Final de Completude
Não use “100%”, “pronto” ou “apto” apenas por build/test/health verde. Antes do veredito, confirme o `AUDIT_DEFINITION_OF_DONE_V1`: release identificado; classes históricas materiais exercitadas; correções SAFE executadas; zero pendência crítica; busca por equivalentes concluída; testes de prevenção presentes; runtime parity comprovada; efeitos externos reconciliados; observabilidade crítica demonstrada; automações assíncronas observadas; recuperação/rollback validados conforme risco; legado/duplicidade inventariados; reauditoria contraditória e meta-auditoria concluídas.

Veredito: `NÃO APTO`, `APTO COM RESSALVAS` ou `APTO`, acompanhado de **confiança 0–100%**, **risco residual** e **dívida de evidência**. Nunca use 100% de confiança com área crítica não validada e nunca emita `APTO` com P0/P1, P2 crítico, AUDIT_ESCAPE pendente ou IMPROVEMENT_REQUIRED crítico.

## Meta-auditoria final
Antes de encerrar, investigue: que classe inteira de falha foi esquecida? quais conclusões dependem de suposição? se o relatório estiver errado, onde? o que ainda pode causar perda financeira, perda de dados, efeito externo incorreto, indisponibilidade ou trabalho manual evitável? Somente então atualize `docs/quality/AUDIT_STATUS.md` com o SHA/release coberto e registre qualquer `AUDIT_ESCAPE` em `docs/quality/AUDIT_ESCAPE_REGISTER.md`.
