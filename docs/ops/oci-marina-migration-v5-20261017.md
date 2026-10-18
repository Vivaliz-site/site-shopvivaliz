# OCI Marina / ShopVivaliz — Auditoria Extrema V5 e gate de preservação

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


## Retomada operacional de 2026-10-18 02:46–02:49 UTC — writers e PHP (NO_CUT)

**Veredito: NO_CUT / NÃO APTO.** Este registro atualiza, sem substituir, a evidência histórica acima e o checkpoint canônico `/home/ubuntu/migration-marina-a1/STATUS.md` na controladora; o destino recebeu adendo em `/home/ubuntu/migration-checks/STATUS.md` (SHA-256 `e1a35a7f2866d0917999b03d593fd7abafca0d61c3c0ecd25b398aadd537c89b`). Não autoriza cutover, cópia bloqueada, uso de credenciais, exclusão, resize ou restart de workers.

### Writer preflight e correção efetiva

| Serviço | Origem antes (02:46Z) | Marina (02:46Z) | Origem depois (02:48:43Z) |
| --- | --- | --- | --- |
| `shopvivaliz-queue-worker.service` | inactive/enabled | active/enabled, PID 1355344 | inactive/disabled |
| `ml-pricing-worker.service` | inactive/enabled | active/enabled, PID 1633911 | inactive/disabled |
| `mlrr-fetch-worker.service` | inactive/enabled | active/enabled, PID 1362967 | inactive/disabled |
| `amazon-returns-safet.service` | inactive/enabled | active/enabled, PID 1403163 | inactive/disabled |

- **RED:** origem podia reiniciar automaticamente os quatro consumidores em um reboot, com os correspondentes já ativos na Marina. Origem ainda tem timers produtores de Pricing, MLRR, Amazon Returns e loja; não há prova de ausência de escritores indiretos ou de fencing transacional.
- **Correção executada:** `systemctl disable` somente nessas quatro unidades **com preflight de estado inactive para todas**. Nenhum stop, restart, máscara, alteração de banco, volume ou DNS. Pós-teste de todas: `inactive/disabled`. Reversível apenas em rollback coordenado **depois** de parar/fencear destino. **Não executar** `enable --now` automaticamente na origem.
- **Evidência:** task MCP `65767cfa-6c4a-47b6-9837-769d17e44a04`, audit ID `3b9f86dc-9ee0-4ffc-8b62-bc36b7236968`; tarefas de estado `f2757d07-1b26-45c3-a396-123b6fb9fe78` (origem) e `c521dc64-f803-44f5-9364-f8f6fb618bf4` (Marina).
- **Regressão parcial:** nenhuma duplicação dos quatro processos **na amostragem**. Necessário verificar consumidores derivados, timers, cron, filas, idempotência, integrações externas, conectividade a bancos e estado pós-reboot sob janela oficialmente autorizada.

### Observações frescas restantes

| Componente | Estado | Evidência e ação pendente |
| --- | --- | --- |
| PHP Marina | **PENDENTE / alteração privilegiada bloqueada** | `php -v`: PHP 8.3.6 com avisos de `xdebug.so`, `amqp.so`, `ast.so`, `dba.so` ausentes (lista não exaustiva); a origem tem PHP 8.3.33 e Xdebug ativo. Preflight APT histórico: 7 novos pacotes, 0 remoções. Instalação `sudo apt-get install` foi expressamente negada no RDC; **não** repetir por relays, SSH ou outro agente. Somente manutenção elevada autorizada oficialmente, com backup de configuração, phpdismod apenas de módulos comprovadamente obsoletos, FPM test, UI e regressão. |
| ML Pricing worker | **PENDENTE** | Marina `NRestarts=5` às 02:49Z, `Result=success` e PID ativo. Histórico da origem `NRestarts=291`. Investigar razão dos reinícios e verificar filas, checkpoints e persistência sem imprimir conteúdo sensível de logs ou disparar ações de marketplace. |
| MLRR e bases | **BLOQUEADO / INCONCLUSIVO** | MLRR tem divergência histórica conhecida de 1 registro; baseline fonte 19 tabelas, 423 `provider_events`, 26 `returns`. Dados completos bidirecionais, writes desde backup, hashes por lotes, SQL autenticado no destino e final delta **não** estão comprovados. Proteção de backup Supabase e probe de credencial MySQL no destino já tiveram recusa explícita: não contornar. Nenhum INSERT, backup restaurado novamente ou transferência nesta retomada. |
| Portas dos bancos Marina | **PENDENTE** | `ss -lntH` às 02:49Z: `:13312` e `:55433` ligados a `0.0.0.0` e `[::]`. Isso prova bind all-interfaces, **não** acessibilidade pública. Validar SG/NSG/firewall e limitar origem de conexões oficialmente antes do cutover. |
| Cloudflare / TLS | **PENDENTE** | Reutilizar **mesmo túnel** de Returns na transferência sequencial aprovada. Não iniciar dois conectores. Certificado real de Pricing copiado não prova renovação independente; PR de hardening de credenciais ainda sem release validada. Nenhuma alteração de DNS/certificado/túnel neste trabalho. |
| E2E UI | **PENDENTE** | Histórico 19/21 cenários locais, dois móveis testados em viewport não correspondente. Sem prova de navegação UI real no destino publicada, reload/sessão/checkout até pré-pagamento, SAFE-T provider-safe e estado transacional: não declarar 21/21. |
| GitHub CI / PR #2993 | **PENDENTE DE REVISÃO** | Head observado `bf1eb0d1384a7d95298a54aaac1a1f6bc12e9748`: `Repository Governance` run `39083109697` success e `Mandatory Validation Gate` run `39083109742` success, mas PR aberto/não mesclado; revisão atual de segurança e publicação dos testes anteriormente recusada ainda abertas. Não mergear nem despachar workflow mutante. |
| Oracle shape e billing | **PENDENTE** | Portal oficial Always Free (atualização 2026-06-12) cita 1500 OCPU-h/9000 GB-h por mês; outras páginas Oracle ainda citam franquia histórica 3000/18000. Confirmar entitlement, custos e quotas nas duas tenancies **antes** de resize ou gasto, preservando a Marina 4/24 existente. |
| Acesso remoto | **PENDENTE** | SSH com host key fixada via controladora confirmou `marina-a1-4c24g`, porém catálogo do MCP conectado ainda anuncia apenas quatro hosts; quinto host sem prova E2E no conector. Não contornar recusas de RDC/IAM. |

**Nenhuma decisão GO, nem ensaio final de rollback, nem cutover.** A origem `shopvivaliz-free-a1` permanece preservada. A próxima fase permitida é auditoria não mutante de produtores/timers e obtenção da via de manutenção oficialmente elevada, seguida de reconciliação bidirecional e E2E real em staging isolado. Regra de rollback: reconciliar writes próprios da Marina antes de reativar qualquer consumidor na origem.

### Diagnóstico adicional do ciclo do ML Pricing — 2026-10-18 02:53 UTC

- Hash SHA-256 da unit systemd **idêntico** na origem e na Marina: `06bdcc8789d1da25e4015664b08b871b254226524e793360ffcb04442dad504a`.
- `ExecStart` confirmado no destino: `messenger:consume async --limit=100 --time-limit=300 --memory-limit=256M --no-interaction`; `Restart=always` e `RestartUSec=5s`.
- Entre as amostras de 02:44:55 e 02:50:00 UTC, `NRestarts` passou de 5 para 6 com `Result=success` e `ExecMainStatus=0`. **Esses reinícios são compatíveis com reciclagem programada de consumidor de cinco minutos, não evidência de crash.** Não há correção de processo indicada apenas pelo contador.
- Ainda é necessário verificar progresso da fila, jobs/retries, conectividade real com o banco e os logs sem revelar segredos. Esta interpretação técnica não promove o serviço a `APTO` E2E.
- Evidência MCP: `33643f85-13d7-4355-a569-b1c67c3ea1fb` (destino) e `cb0dd302-b5a4-41a4-b881-f222259eb087` (origem).
