# OCI Marina / ShopVivaliz — Auditoria Extrema V5 e gate de preservação

## One-click maintenance request — 2026-10-25: fail-closed only

**MAINTENANCE_ONE_CLICK_UI=CODE_ONLY | BROKER_AND_SIGNER=NOT_PROVISIONED | GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

- The admin page no longer accepts browser-supplied ticket JSON or detached signatures. A single CSRF-protected click with an official fresh-MFA session can only ask the independently provisioned signer through the server; the signer capability remains outside PHP/browser/Git/CLI and is forwarded internally to the private broker.
- Code now fails closed unless broker/signer have internal DNS, expected SPIFFE identity, mTLS CA/certificate/key and SPKI pin. Ticket v2 binds broker audience, admin subject, request ID and preflight digest before the broker request. The still-unprovisioned official broker must independently validate role/grant, lease, source state, CAS, writer fence, replay ledger and monotonic fencing. This is not a deployed authority, a signed lease, a source action, or UI E2E evidence.

## Safe readiness update — 2026-10-25: Marina-only writer and certificate repair scope

**BLOG_ORIGIN_FENCE_WORKFLOW=FAIL_CLOSED | MARINA_CERT_REPAIR_WORKFLOW=BLOCKED_RUNNER_NOT_PROVISIONED | GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

- The scheduled editorial and product-highlights email workflows now reject every runner except the short hostname `marina-a1-4c24g` before SSH material is loaded, release data is read, or campaign/email commands can run. Therefore a source runner cannot become a writer even if the legacy `shopvivaliz-a1-deploy` label remains attached. This is a versioned fail-closed guard only: it creates neither a Marina runner nor a replacement writer.
- The manual certificate credential-reference workflow also accepts only short hostname `marina-a1-4c24g`. It still does not call Certbot renewal, publish DNS, or change routing. **MARINA_CERT_REPAIR_WORKFLOW=BLOCKED_RUNNER_NOT_PROVISIONED**: no approved GitHub Actions runner with that identity/label has been attested in this run, so the workflow must not be dispatched. An authorized GitHub self-hosted-runner administrator plus the Marina OS/protected-secret operator must provision and attest it before a manual run; that authorization is distinct from any maintenance lease.
- The following remain official operator-only work: repair the failed MySQL logrotate identity and read back `logrotate.service`; restrict and prove the Pricing Docker volume/restart policy; enable only approved services for boot; perform any certificate issuance/renewal DNS-01 action; and make an authorized durable Supabase backup/restore. No task in this update starts services, touches Docker/MySQL, transfers Supabase data, or changes DNS.

## Atualização local de prontidão — 2026-10-24: método do acompanhamento de manutenção fechado

**MAINTENANCE_BROKER=NOT_PROVISIONED | OPERATION_STATUS_METHOD_GATE=PASS_LOCAL | GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

- O endpoint administrativo de consulta de operação (`/api/admin/marina-maintenance-operation.php`) agora recusa qualquer método diferente de `GET` com `405`. O teste focal passou somente após a correção; também passaram os testes locais de contrato, broker, endpoints e UI de manutenção, mais lint PHP 8.3.
- Esta evidência verifica somente o contrato local da superfície administrativa. Não provisiona broker, signatário, ticket, lease, rota privada ou acesso de operador; não aprova nem executa a retirada do timer/cron da origem.
- Nesta rodada não houve mutação OCI, DNS, release ativa, dados comerciais, e-mail ou scheduler. A origem não foi iniciada, parada ou excluída por esta tarefa. O draft PR #3176 continua sujeito ao fluxo oficial de signatário, RBAC, lease/fencing, revisão e validação real antes de qualquer promoção.

## Atualização verificada — 2026-10-20 14:59–15:23 UTC: integridade real dos dados e writer editorial ainda na origem

**DB_ROW_CONTENT_PARITY=97_OF_102 | BACKUP_BLOG_CAMPAIGN_RESTORE=PASS | BLOG_PUBLISH_SOURCE_WRITER=CONFIGURED | GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

- Paridade das **102 tabelas MySQL `shopvivaliz`**: leitura de snapshot consistente, sem writes, e hashes de cada linha normalizada em ambas as VMs mostraram **97 tabelas com conteúdo equivalente** e cinco com diferenças. Em 14:59 UTC a origem tinha **14.704** linhas; a Marina **14.703**. A cifra histórica 14.701 no P0 anterior **não deve ser usada como contagem atual**. As três diferenças numéricas: `blog_articles` (59/58), `email_campaign_runs` (53/51) e `sv_conversion_events` (363/365).
- `users`: 10 linhas em ambos; **um** registro com `password_hash` e `updated_at` divergentes, cujo timestamp é **mais recente na Marina**. A credencial não foi lida em claro, exposta, importada nem redefinida. **Não copiar a tabela ou o hash antigo sobre a Marina**; exigir autenticação/UI de conta legítima antes de encerrar.
- `coupons`: três linhas em ambos; **dois** registros com `updated_at` mais recente na Marina, mas todos os campos comerciais equivalentes. Preservar o destino e não sobrescrever timestamps sem prova de propriedade.
- `sv_conversion_events`: há **dois eventos adicionais na Marina**. São dados legítimos do destino a preservar; não substituir pela origem. Análise de eventos é somente contagem/hash, sem dados de sessão divulgados.
- `blog_articles`: falta na Marina o artigo **id 30430**, presente apenas na origem e ainda agendado. O artigo comum **id 16206** está `published` na origem e `scheduled` na Marina, com agendamento vencido desde 2026-10-19. Outras onze linhas antigas diferem **somente** em `updated_at` por aproximadamente dois segundos durante a ativação da release em ambas as VMs. Não transportar SQL integralmente nem redefinir status sem CAS, owner, agendador único e UI do CMS.
- `email_campaign_runs`: IDs **52 e 53** existem somente na origem, com status `completed` e **zero** destinatários, enviados e falhas no levantamento anterior. Preservar como histórico auditável, sem disparar/repetir e-mails; revalidar efeitos antes de eventual arquivo/espelho seletivo.

### Backups locais e ensaios de recuperação — duas pontas

- Origem: arquivo privado `/home/ubuntu/.shopvivaliz-migration-private/blog-campaign-local.bz8iC9Nr/shopvivaliz_blog_campaign.sql.gz`, SHA-256 SQL `3eb7582516fd6b06f9c47341c0e68ca7ae676e80de395cbee77d4bf295202e48`. O diretório antigo `migration-checks` da origem continua `root:root` mode 0700; foi respeitado e não alterado.
- Marina: arquivo privado `/home/ubuntu/migration-checks/blog-campaign-local.uwaaiZ1O/shopvivaliz_blog_campaign.sql.gz`, SHA-256 SQL `e74c665ceee42514975c8c6a946a7da0302d7b4d4bbfccc8113b5a870a2d9327`.
- Cada dump transacional cobre **somente `blog_articles` e `email_campaign_runs`**. Diretórios 0700, arquivos 0600 e manifestos com hash e `restore_validation=PASS`. Em ambas as VMs uma instância MySQL temporária sob `/var/tmp`, `--skip-networking`/socket local, importou as duas tabelas: **origem 59/53 e Marina 58/51**. Todos os registros restaurados foram comparados por hash de conteúdo com o banco correspondente (`RESTORE_ROW_HASH_PARITY=PASS`). O primeiro ensaio falhou porque o dump de tabelas não continha `CREATE DATABASE`/`USE`; o teste isolado foi corrigido para criar a base vazia apenas na instância temporária (RED/GREEN), sem mudanças em produção. Sem MySQL de teste remanescente nem transferência entre hosts.
- **Limitação**: esses arquivos estão **na própria VM** correspondente; não certificam durabilidade externa, Supabase, controle de volume Docker ou autorização para decommission.

### Causa do desvio editorial / responsabilidade pelos escritores

- `.github/workflows/blog-publish-scheduled.yml` agenda publicação **a cada 15 minutos** em `runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]` e executa por SSH `ubuntu@127.0.0.1` no checkout **da origem**, incluindo `apply-real-email-campaigns-migration.php`, `api/blog/publish-scheduled.php` **e `run-blog-email-campaigns.php` (e-mail real)**. Esse contrato não indica conexão independente à Marina. A pasta do runner da origem existe; **nenhum runner equivalente** foi encontrado na Marina. Há identidade de token `BLOG_PUBLISH_TOKEN` provisionada no runtime de ambas as VMs, mas **não se confirmou** identidade do secret GitHub nem executor final aprovado; os valores nunca foram exibidos.
- Consulta HTTPS **não autenticada** a `api/blog/publish-scheduled.php` respondeu **401**, resultado esperado; nenhum token foi usado e nenhuma publicação foi disparada. Na origem: 47 publicados, 12 agendados futuros e zero vencidos; Marina: 46 publicados, 11 futuros e **um agendado vencido** (`BLOG_TARGET_OVERDUE=1`). A rotina da origem pode atualizar o banco antigo enquanto o público é atendido pela Marina, mantendo a divergência.
- O timer/cron de recuperação de carrinhos na origem também permanece ativo sem testar `origin-workers-fenced`; a chamada `service_action` oficial foi recusada por `runtime_lock_required`. **Não** contornar essa recusa via RDC, SSH, `task_submit`, GitHub ou OCI. O PR #3164 permanece fechado/draft sem merge. Não confundir CI verde, backups ou token de publicação com autorização de um novo writer.

### Sequência de correção segura e obrigatória

1. Operador autenticado do mecanismo **oficial** obtém lease de manutenção/fencing token com escopo correto e capacidade autorizada para deduplicar cron/timer na **origem**. Desabilitar agendamentos do carrinho com readback do boot e confirmar ausência de reativação automática. Preservar exclusivamente o writer da Marina, sob mesmo controle de idempotência.
2. Provisionar e auditar **um** publicador editorial/campanhas na Marina: runner próprio sob ownership aprovado **ou** endpoint HTTPS autenticado e fluxo de campanhas com idempotência, segredos separados, testes de negativa e positivos, além de evidências de envio. **Não** mudar o workflow antigo antes de provar o novo; ele executa e-mails reais, não só posts. Depois bloquear o writer antigo via lease e confirmar que os dois não operam simultaneamente.
3. Reconciliar **seletivamente** id 16206 e id 30430 sob backup confirmado, identidade/slug, CAS em `updated_at` e status e UI do CMS (publicar apenas o agendamento vencido aprovado; não gerar artigos/campanhas duplicados). Nunca copiar `users`, `coupons` ou `sv_conversion_events` da origem para substituir registros novos da Marina. Históricos de e-mail exigem prova de zero-envios e não autorizam replay.
4. Prosseguir nos gates separados: Pricing MySQL 13312/IPv4+IPv6 e worker, SAFE-T boot, Supabase protegido, MCP OpenAI independente, reconciliação final, UI real e teste de reboot/rollback; manter origem e backups **até** aceites independentes e autorização de descomissionamento.

**Resultado: BACKUP_BLOG_CAMPAIGN_RESTORE=PASS, mas DB_SOURCE_TARGET_PARITY=NOT_PROVEN, ORIGIN_ABANDONED_CART_SENDER_FENCE=NOT_ENFORCED e GLOBAL_MIGRATION_STATUS=NAO_APTO / NO_DECOMMISSION.** Nenhum escritor/serviço, token, DNS, dado comercial ou política privilegiada foi modificado nessa etapa de auditoria.

---

## P0 — 2026-10-20 10:55 UTC: escritor de e-mail duplicado na origem e delta SQL ainda aberto

**ORIGIN_ABANDONED_CART_SENDER_FENCE=NOT_ENFORCED | GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

Apesar do marcador raiz `/etc/shopvivaliz/migration/origin-workers-fenced` estar presente e dos quatro workers migrados permanecerem `inactive/disabled`, a origem `shopvivaliz-free-a1` **ainda possui dois agendamentos ativos da recuperação de carrinho abandonado**: cron `ubuntu` a cada 30 minutos e `shopvivaliz-abandoned-cart-recovery.timer` systemd `active/enabled`. A Marina possui outro timer ativo. Cron e unit usam **arquivos de lock diferentes**, e o script de envio instalado na origem ainda **não valida o fence antes do PDO e do mailer**. Nas execuções recentes observadas `Enviados=0`, `Candidatos=0`, `Falhas=0`; nenhum envio duplicado foi comprovado, mas o próximo carrinho elegível poderá gerar uma escrita/envio pela origem desatualizada.

**PLATFORM_RUNTIME_LEASE=REQUIRED** — a ferramenta **oficial** ShopVivaliz Remote Control recusou explicitamente a parada do timer da origem com `runtime_lock_required` (audit `9197c5d2-9546-4900-ba74-56e24ac7a584`). Não repetir a ação por RDC, SSH, GitHub Actions, `task_submit`, OCI ou outro caminho para contornar a recusa. O operador legítimo deve adquirir o **lease de manutenção e fencing token corretamente escopados** e realizar o stop/disable do timer + remoção/deduplicação do cron, preservando o timer da Marina. A correção de código está preparada e testada no [PR #3164](https://github.com/Vivaliz-site/site-shopvivaliz/pull/3164), **DRAFT, NÃO MESCLAR/IMPLANTAR** até essas condições; o merge também poderia acionar automações mutáveis. Prova de teste: script da branch executado temporariamente na origem encontrou o fence real e saiu `ABANDONED_CART_ORIGIN_FENCED=SKIP` antes de acessar DB/mail, sem modificar os arquivos instalados. Acompanhar [issue P0 #3163](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3163).

**DB_SOURCE_TARGET_PARITY=NOT_PROVEN** — comparação PDO exclusivamente de leitura das contagens exatas nas **102 tabelas** `shopvivaliz`: origem **14.704 linhas**, Marina **14.701**. Somente `blog_articles` (origem 59; Marina 58) e `email_campaign_runs` (origem 53; Marina 51) divergem por quantidade. Há um artigo agendado apenas na origem (id 30430) e um artigo presente em ambas (id 16206) com publicação/atualização mais recentes na origem, enquanto a Marina ainda registra status agendado. Dois históricos da tabela `email_campaign_runs` exclusivos da origem (ids 52/53) estão `completed` com **zero destinatários, zero enviados e zero falhas**. Nenhuma campanha foi disparada nem linhas importadas nesta auditoria; não copiar dados ou reexecutar mensagens automaticamente. A reconciliação seletiva exige backup de ambas as pontas, CAS em identidade/versão/owner, validação de edição mais recente, idempotência comercial e UI real.

**A publicação do site continua `SITE_PUBLIC_AUDIT_STATUS=APTO` no seu escopo.** Isso **não libera o descomissionamento** da origem, mesmo com o backup/restauração lógica do Pricing validado. Os bloqueios de Docker/VM, Supabase protegido, MFA/Platform Tunnels, SAFE-T boot, proteção dos writers e rollback seguem independentes. Esta atualização é **somente documentação**, não para interromper cron/timer, transferir payloads ou abrir novas portas.


## Evidência verificada — 2026-10-20 10:20 UTC: recuperação SQL Pricing

**PRICING_LOGICAL_BACKUP_AND_SQL_RESTORE=PASS | GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

Foi criada na **própria Marina**, sem privilégio root, uma cópia lógica transacional do banco `ml_pricing_prod` em `/home/ubuntu/migration-checks/pricing-db/ml_pricing_prod_20261020T100857Z.sql.gz`. MySQL `mysqldump 8.0.46` com `--single-transaction`, incluindo rotinas, eventos e gatilhos, leu o destino já autorizado `127.0.0.1:13312`. Nenhum dado ou segredo foi enviado para outra VM, CI, chat, Git ou armazenamento remoto. O diretório privado usa permissão `0700` e backup, manifesto e provas `0600`. Backup contém **27 tabelas** e foi verificado por descompactação integral e SHA-256 do SQL `25b9d7619e40065664b52c27dca1e833a58a2981dc6533e73490eb34c35f3c3a`.

**RESTORE_VALIDATION=PASS | RESTORED_SQL_ROW_PARITY=PASS**: o backup foi importado integralmente em **instância MySQL isolada sem TCP** (`--skip-networking`), criada pelo usuário `ubuntu` num diretório privado sob `/var/tmp` permitido pela política AppArmor já existente. A restauração apresentou **27/27 tabelas com contagens de registros iguais** às respectivas tabelas do banco original, mais **16/16 versões Doctrine idênticas**. A instância e seu diretório temporário foram destruídos ao final; zero processos `mysqld` de usuário remanescentes, e o `mysql.service` de produção não foi alterado. Evidências locais: `pricing-db/*.manifest.json`, `restore-test-20261020T1018Z.txt` e `restore-row-parity-20261020T1020Z.txt`. Consulta pública das evidências sanitizadas na [issue #3038](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3038).

O **primeiro ensaio** de inicialização do MySQL temporário falhou por confinamento AppArmor ao criar o datadir sob `/home`; a correção foi executar **somente o MySQL temporário** sob `/var/tmp`, explicitamente permitido pela política existente, sem modificar AppArmor, permissões administrativas ou o MySQL de produção (RED/causa/GREEN). A cópia validada fica **no mesmo host** e ainda **não** comprova backup externo durável, restauração após reboot, persistência/ownership do volume Docker, política de reinício, firewall/NSG IPv4/IPv6, recuperação completa do Supabase ou processamento real do Pricing.

**PRICING_WORKER=INACTIVE_DISABLED** no destino na última checagem; MySQL da validação segue escutando em `0.0.0.0:13312` e `[::]:13312`, pendente de restrição por operador oficialmente autorizado. Sondas TCP *somente conectividade* da origem e controladora à VNIC privada `10.80.1.116`, portas `13312`/`5580`, retornaram indisponível nessas duas rotas, **não** prova de segurança contra outros peers. Nenhuma tentativa de contornar o bloqueio de privilégio root/Docker, nenhuma transferência protegida do Supabase, ativação de worker, rollback ou descomissionamento. **Preservar origem, fencing e backups até aceite global independente**.


## Evidência atualizada em 2026-10-20 04:31–04:32 UTC (não altera o histórico abaixo)

**PUBLIC_ROUTE_OBSERVED=marina-a1-4c24g | SITE_PUBLIC_AUDIT_STATUS=APTO**

A release web `99416b61be202b95a1f77b0469fd6395954eeb74` está ativa na Marina. Uma solicitação HTTPS pública identificada a `shopvivaliz.com.br` retornou HTTP 200; o marcador apareceu **1 vez** no log Apache `shopvivaliz-apex-www-access.log` da Marina e **0 vezes** no log equivalente da origem. O tráfego testado passou pelo proxy Cloudflare. Isso comprova aquela solicitação, não cada região/caminho nem paridade integral. A auditoria de UI/funcionalidade do storefront (PR #3160) registrou `AUDIT_VERDICT=APTO` **para o escopo do site publicado**.

**GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

O cutover público do storefront foi observado, mas a migração e a elegibilidade para desativar a VM de origem **não** estão completas. `shopvivaliz-free-a1` e seus backups permanecem preservados e seus quatro workers migrados seguem protegidos por `origin-workers-fenced`. Na Marina o `ml-pricing-worker.service` permanece `inactive/disabled`; o MySQL de validação escuta em `0.0.0.0:13312` e `[::]:13312`, sem aprovação de volume persistente, backup, restauração, controles IPv4/IPv6 ou ownership. Supabase (transferência previamente recusada), SAFE-T (serviço ativo mas sem auto-boot), MCP próprio independente (não instalado/conectado), reconciliação final, custos OCI, reboot e rollback seguem pendentes. Nenhum bloqueio de plataforma pode ser contornado; não reativar writers na origem por inferência.

Os inventários, resultados e proibições de corte escritos abaixo pertencem à auditoria histórica de 2026-10-17 e devem ser interpretados com a atualização acima. `NO_CUT` histórico não nega a prova posterior de publicação do storefront; permanece a regra **NO_DECOMMISSION** até completar os demais gates. Ver `docs/quality/AUDIT_STATUS.md` e `docs/ops/marina-independent-secure-mcp-tunnel.md`.


> Checkpoint operacional **NÃO APTO — migração ainda não concluída**. Evidências colhidas em 2026-10-17 (UTC). Este documento **não é autorização de corte, exclusão, faturamento ou promoção**; revisão/dados frescos obrigatórios.

## Regras e fonte de verdade

- Base `main`: `b45dfa8eee1bdc1b72c540f12e81b1bfbf9ecb89`.
- Leituras documentais realizadas: `docs/knowledge/atendimento-chatgpt-bootstrap.md` (blob `5ae4e46`), `docs/knowledge/host-access.md` (`4961630`), `docs/knowledge/README.md` (`21ba97d`), `docs/knowledge/agent-rules.md` (`fe1afe1`), `AGENTS.md` (`0ea10a4`), `AUDIT_POLICY.md` (`db4e334`), `docs/knowledge/browser-sessions.md` (`e4d5623`), `docs/knowledge/shopvivaliz-graphical-session-recovery.md` (`26e7a9b`) e `docs/ops/oci-dual-account-access.md` (`151727f`).
- Operação: **não terminar/deletar `shopvivaliz-free-a1`, sua boot volume, backups ou qualquer outro recurso** sem aprovação específica e gate completo. Não duplicar workers, runners ou movimentações comerciais.
- Controle de persistência: `/home/ubuntu/migration-marina-a1/STATUS.md` e `rules_loaded_20261017.txt` na controladora; cópia de checkpoint de migração em `/home/ubuntu/migration-checks/STATUS.md` na Marina. Confirmar atualização antes do corte.
- Oracle oficial, [Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm), atualizado 2026-06-12: 1.500 OCPU-horas e 9.000 GB-horas mensais para Ampere A1, aproximadamente 2 OCPUs + 12 GB contínuos por tenancy. **Não presumir 4/24 gratuito.** Validar modalidade/consumo/faturamento/quotas em cada tenancy antes de aumentar recursos.

## Inventário por OCI CLI (2026-10-17 UTC)

| Conta | Instância | Shape | OCPUs | RAM | Disco (raiz) | Papel atual |
| --- | --- | --- | ---: | ---: | ---: | --- |
| PRINCIPAL | `always-free-arm-1787907847-26` | A1.Flex | 2 | 16 GB | 96 GB | Controladora: MCP, navegadores corporativos, relay, rotinas backend |
| PRINCIPAL | `shopvivaliz-free-a1` | A1.Flex | 2 | 8 GB | 96 GB | **Produção de origem: manter** |
| ESPOSA | `marina-a1-4c24g` | A1.Flex | 4 | 24 GB | 193 GB utilizáveis | Destino pré-corte, ainda sem workers produtivos |

Todas as três instâncias estavam `RUNNING` no OCI, `sa-saopaulo-1`. O destino possui sistema ARM64 e disco livre de aproximadamente 178 GB. A controladora antiga apresentava aproximadamente **91% de disco**, swap 6/6 GB ocupado e dois serviços failed (`mei-mg-email-worker`, `shopvivaliz-login-integrity`), pendentes de causa raiz/remediação.

## Provas já executadas — não equivalem a APTO

1. Cópias preliminares de `shopvivaliz-deploy`, Amazon Returns, ML Pricing e MLRR efetuadas. Symlink imutável `current` na origem e no destino apontava para `releases/20261016-022648-9896bf9b` (SHA `9896bf9baf85766ea30708c95983da5f640c3312`); Amazon Returns `20261016T110242Z-4eb2111fba18`. Deploy **não é automaticamente igual** ao `main` atual; rastrear versões antes de aprovar.
2. SHA-256 dos backups transferidos compatíveis (MySQL nativo, SQL de Pricing, dumps físicos Supabase, MLRR e MEI). Conferência estrutural inicial MySQL nativo: 102 tabelas `shopvivaliz`, 94 `shopvivaliz_baseline_20260829`, 16 `amazon_returns_safet`, 8 `amazon_returns_safet_preverify_39_20260905`, coincidentes nas duas VMs.
3. Restaurações PostgreSQL isoladas iniciaram e responderam: Supabase 103 tabelas, MLRR 19, MEI 8; contagens coincidentes com origem. Containers de validação parados para evitar writes duplicados. Backup SQL ML Pricing contém 27 definições de tabelas da aplicação; o MySQL isolado inicia, porém **root restaurado rejeita credencial temporária** (1045): consulta às tabelas físicas e consistência de transações ainda não concluídas. Não contornar recusa de ferramenta ao modificar credenciais.
4. Após reparos de ACL, PHP-FPM e configuração de staging, rotas locais da Marina responderam HTTP 200 para loja, login de Amazon Returns e `/v1/health` do ML Pricing. HTTP 200 não prova transações, webhooks, autenticação ou fornecedores.
5. Na interface **pública da origem**, navegador real abriu loja, adicionou produto ao carrinho e navegou ao checkout **sem pedido/pagamento**, além de abrir login do Returns. Ainda **não** há teste UI E2E do destino, de carrinho/checkout completo ou de Seller Central.
6. RustDesk 1.4.9 e RDC 0.2.52 instalados no destino com identidades distintas. RDC novo aguarda autorização `AUTH_REQUIRED`; RDC 2 ainda precisa de vínculo elegível; Tailscale instalado `NeedsLogin`. SSH/controladora → Marina provado com host key verificada. Chave existente em `Downloads/ssh-key-2026-07-04.key` no laptop possui fingerprint compatível com chave pública autorizada (nenhuma chave privada foi copiada).
7. ShopVivaliz Remote Control MCP central possui cadastro servidor do novo host, mas o conector ChatGPT listava somente quatro hosts: discovery/cache/refresh requer teste. Controladora reportou `continuity_ready=false`, `degraded=true` e release ativo `3f47ea0...` divergente do `main` `b45dfa8...`; **não atualizar sem respeitar lease/ownership da controladora**.
8. Definições systemd ausentes foram staged e registradas **inativas** na Marina. Binários oficiais GitHub Actions Runner 2.337.0 ARM64 instalados em dois diretórios, **sem** reutilizar identidades `.runner`. Runners ainda não registrados, workers não habilitados. Um setup de agente de continuidade depende de `gemini.env` separado/seguro.
9. GitHub CI: runs recentes do site, Amazon Returns, pipeline e MEI tiveram sucessos pontuais. MLRR apresentou falha em último workflow observado no `main` de 2026-09-29 (run `36511773369`, erro no setup do runner `governed-mlrr-ci`); requer revalidação fresca e reparo de runner/checks. Alguns jobs Solange foram skipped; investigar elegibilidade antes de inferir defeito.

## Gates obrigatórios para mudança de tráfego

- [ ] Comprovar modalidade OCI, quotas e ausência de custos não autorizados; **não redimensionar**.
- [ ] Tratar falhas e saturação de disco/swap no backend com preservação de dados, red/green e regressão.
- [ ] Reconciliar dados recentes por banco, fila, idempotency key e evento; validar backup/restauração e integridade referencial.
- [ ] Provar contas/perfis Dev e Atendimento no CDP correto, autenticação oficial, MFA e GUI, sem importar sessões.
- [ ] Tailscale/RDC/RDC2/RustDesk/MCP funcional, verificado após recuperação, sem identidade duplicada.
- [ ] Executar `scripts/production-functional-audit.sh` no release alvo com credenciais já provisionadas de modo protegido; validação real de UI em browser do backend.
- [ ] Validar SAFE-T primeiro (FBA=Onsite/Seller Flex), MLRR, ML Pricing, OKX somente leitura financeira, e outros sistemas aplicáveis, evitando efeito duplicado.
- [ ] Regenerar certificados HTTPS restantes na Marina e validar renovações, Cloudflare tunnels, webhooks, DNS, callbacks e acesso externo.
- [ ] Validar GitHub checks, runners, releases, provas de deploy e merges conforme branch protections.
- [ ] Realizar pré-sincronização final, congelar escritores na origem com reversão, transferir ownership e revalidar tudo após cutover e reboot controlado.
- [ ] Confirmar ausência de consumidores da origem e ensaiar rollback. **Nunca deletar a origem sem autorização explícita posterior**, mesmo que gate APTO passe.

**Veredito provisório:** MIGRAÇÃO=**NÃO APTO**; UI/E2E destino, automações, MCP+RDC, sincronização final e custos=**NÃO VALIDADOS**. O trabalho está em curso; não registrar como concluído.

## Evidência complementar 2026-10-17 02:45 UTC

- **Auditoria funcional por credencial oficial:** `Production Functional Audit` run [38981865262](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/38981865262) no SHA `b45dfa8`: job “Prove production actually works”, incluindo integração de fornecedores, concluiu success, com SSH e `SHOPVIVALIZ_AGENT_KEY` por secrets protegidos. `Hourly Integration Health` run [38989975003](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/38989975003) também success no SHA `b45dfa8`. Já o shell manual do script na origem, sem a chave, falhou corretamente no gate `SHOPVIVALIZ_AGENT_KEY missing`, após passar catálogo, pedido-health, payment queue, frete real. Não copiar credencial para shell ou log como “correção”. A prova de workflow se refere **à origem**, não à Marina.
- **UI origem real:** browser MCP no domínio público abriu loja, acionou botão Adicionar ao carrinho, navegou a `/carrinho` e clicou “Finalizar pedido” chegando a `/checkout` sem gerar pedido/pagamento. Auditorias `1f9aa276-caa2-4bfe-b65c-bb191a89f6d9` e `cc1c9bd6-1b99-4322-bf9f-1c7e2189110d`. Não há prova UI equivalente do destino.
- **Credenciais do laptop:** `Downloads/ssh-key-2026-07-04.key` e .pub existem, tanto derivação da pública quanto pública salva conferidas por fingerprint `SHA256:5SJQbLl1L++dLo4moTAIxh6H9xRFydfbDA/fNGaSFII`, compatível com `authorized_keys` existente da origem e Marina. Chave privada não transferida.
- **Login de agentes:** Dev (CDP9559) acessível, 14 abas observadas, sessão UNKNOWN; tabs de login apontam `/email-verification`; Atendimento (CDP9556) 17 abas observadas, sessão UNKNOWN, tab `/log-in`. Preflight canônico seguro: atendimento `saved_entry=PRESENT`, `password_request_gate=DENY_EXISTING_CREDENTIAL`; Dev `saved_entry=NOT_VERIFIED`, `password_request_gate=DENY_ABSENCE_NOT_PROVEN`; não solicitar/transportar novas credenciais nem copiar cookies. `shopvivaliz-login-integrity` DEGRADED corretamente por 36 checks, dev auxiliar autenticado; não confundir CDP ativo com sessão autenticada.
- **MEI bloqueio seguro:** service `mei-mg-email-worker` failed após dependência Postgres em 5433 e endpoint unsubscribe timeout durante 11:38 UTC de 16/10. `/var/lib/mei-mg-email/sender_blocked.pause` **presente** conforme runbook: worker deve permanecer parado até resolver o bloqueio operacional. Nenhuma retomada/reenfileiramento foi feita.
- **Medição infraestrutura:** backend disco 91% usado (9.6 GB livres), RAM ~13/15 GiB usada, swap zram 6/6 GiB usada, sem OOM recente. `du` lento de baixa prioridade expirou em 340s (`exit=124`), não tratar como espaço liberado. Origem disco 49%, destino 8% e 178 GB livres. Não rodar prune/limpeza cega em imagens Docker, volumes, logs ou worktrees.
- **CI MLRR:** [main guard 36511773369](https://github.com/Vivaliz-site/mercadolivre-returns-recovery/actions/runs/36511773369) e CI `36511773302` (2026-09-29) falharam em Set up job no runner `governed-mlrr-ci`; logs desses jobs hoje retornam GitHub 410 e exigem nova prova de runtime e CI. Não inferir sucesso de runs de branches anteriores.
- **Atenção OCI Always Free:** docs [Oracle Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm) agora informam 1.500 OCPU-h e 9.000 GB-h/tenancy/mês (aprox. 2 OCPU+12 GB contínuos). [Página Oracle de ARM](https://docs.oracle.com/en-us/iaas/Content/Compute/References/arm.htm) e outra lista de preços ainda publicam franquia histórica 3.000/18.000. Essa discrepância **não autoriza** declarar 4/24 gratuitamente; confirmar termos e billing reais do tenant. **Nunca** efetuar resize/upgrade com custo desconhecido.

Conclusão dos testes adicionais: o estado integral segue **NÃO APTO**. Prioridades: diagnóstico não destrutivo de disco, verificação de quotas/billing, integridade transacional delta, provisionamento autenticações oficiais, testes E2E no destino, CI e persistência. Manter a VM origem RUNNING e preservar rollback.

## Complemento de integridade de backups — 2026-10-17 03:55 UTC

Evidências operacionais da retomada posterior à auditoria inicial:

- **Origem e destino:** os oito arquivos públicos/críticos `shopvivaliz-deploy/current` e `amazon-returns-deploy/current` previamente selecionados tiveram hashes SHA-256 **idênticos** nas duas VMs. Isso prova cópia dos arquivos comparados, não reconciliação do banco nem do tráfego.
- **Marina staging:** Apache respondeu HTTP 200 em `/`, `/carrinho`, `/checkout` via porta 8080, `/login.php` do Amazon Returns via porta 18080 e `/v1/health` do ML Pricing via porta 80. `/catalogo` respondeu 301 (redirect não acompanhado). Verificações apenas locais com Host header, sem UI E2E real e sem switch de DNS.
- **Arquivo de backup:** sob sudo read-only foram executados `gzip -t` em **8/8** arquivos `.gz` da Marina e `tar -tzf` em **2/2** arquivos físicos `*.base.tar.gz`; todos passaram. O MySQL na Marina respondeu `mysqld is alive`. **Arquivos comprimidos válidos não comprovam backup lógico utilizável, atualização transacional ou restauração final.**
- **Falha reproduzida no PostgreSQL Supabase da origem:** container `supabase_db_solange-rolla-consultorio` saudável, ARM64 nativo, PostgreSQL 15.8 com clientes Nix `/nix/store/.../postgresql-15.8/bin/pg_dump`; `pg_dump -Fc` **exit 139** e `pg_dump --schema-only` **exit 139**. Serviço servidor permaneceu online (pg_isready PASS, OOMKilled=false). Investigação indica crash do cliente ao fazer dump, causa raiz binária não certificada. O arquivo `.pending` criado para a tentativa foi removido via trap, sem tocar dados produtivos ou backups preexistentes.
- **Bloqueio de segurança:** uma tentativa de produzir snapshot novo via `pg_basebackup` foi explicitamente recusada pela ferramenta da plataforma. **Não contornar essa recusa** com shell/relay/transferência/conta alternativa. Backup físico anterior da origem já teve restauração isolada no destino, mas não há prova de backup físico final e sincronização transacional desde o último snapshot. Só retomar com método oficial expressamente permitido e os controles aprovados.
- **Capacidade:** backend continua 91% de disco, swap zram 6/6 GB, memory PSI `some~99%` e `full~70%`; 115 processos Chrome em cinco grupos. `shopvivaliz-disk-guard.timer` existente registra `root filesystem critical`; housekeeping tem cooldown e preservação de ownership. Não executar limpeza arbitrária, reinício de perfis ou fecho de tabs que contornem lease/fencing.
- **Integrações:** Claude Remote Control voltou a estar ativo/autenticado no observador; continuidade ChatGPT segue degraded por checkpoint antigo sem binding e release ativo divergente. No target Tailscale está `Logged out`, RDC ainda exige autorização oficial e o catálogo MCP ChatGPT possui somente quatro hosts apesar da configuração da quinta rota no backend.
- **Acesso laptop:** Desktop Commander lista Fred-Win online, mas execução retornou bloqueio de quota mensal com instrução explícita para não repetir/reconectar. Captura MCP em resolução 3840x1080 falhou por limite de resposta. Não inferir cobrança OCI ou GUI E2E como verificadas.

**Gate da migração continua NÃO APTO.** Preservar `shopvivaliz-free-a1` e todos os volumes e backups. Não redimensionar a controladora sem confirmar quotas/custos. Próximos passos são reconciliação de dados/backup por método autorizado, estabilização da backend, autenticações oficiais Tailscale/RDC, certificados, testes UI E2E na Marina, evitar runners/workers duplicados e ensaio de rollback.
