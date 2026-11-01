# Auditoria Extrema V5 — autorizacao formal de manutencao MySQL Pricing na Marina

> **ESTADO: PROPOSTA / NAO IMPLANTADO EM PRODUCAO.** Documento de escopo de aprovacao e verificacao; nao contem comando de ativacao automatica nem concessao de privilegios.

## 1. Origem e alvo

- Issue operacional: [#3038](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3038). Host unico: `marina-a1-4c24g` (OCI Marina), servico `ml-pricing-worker.service`, container `marina-pricing-validation`, banco `ml_pricing_prod`. A VM antiga descomissionada e proibida como destino.
- Identidade autorizadora: **exclusivamente o proprietario humano**, validacao do PR Draft, checks obrigatorios GREEN e merge feito pessoalmente por identidade human-only protegida. Nenhum agente, workflow ou bot pode efetuar o merge.
- Problema P0: MySQL do Docker publica TCP `13312:3306` com host IP vazio, efetivamente `0.0.0.0:13312` e `[::]:13312` (wildcard). Desejado: **somente** `127.0.0.1:13312:3306`, sem publicidade em interfaces OCI/Tailscale/IPv6, mantendo conectividade do processo local.

## 2. Provas reproduzidas em 2026-10-31 (UTC)

- Release do site e do controlador atingiram `6774e22dea090f909bc04695c024fc989547e860` durante a auditoria; o main segue sujeito a avancos independentes e exige nova leitura antes de qualquer deploy. PR #3445 e #3446 integrados com gates GREEN em seus SHAs.
- DB real: 27 tabelas InnoDB, autenticacao valida da aplicacao existente (nenhuma credencial foi revelada). `ml-pricing-worker=active`. SQL administrativo com segredo atualmente montado retornou `ERROR 1045` para root; **nao adivinhar ou alterar senhas** nem inferir que autenticar como app concede root.
- Backup novo protegido na propria Marina: `pricing-full-20261031T024634Z-f3ebd56660/ml_pricing_prod-complete.sql.gz`, SHA256 `027b55112bea8ef0e82e2d269804640f0c935d2afb5c22b12dc279e1a757f289`, 27 tabelas, dump transacional gzip integro. O manifesto do mesmo diretorio registra `restoration_test=PASS`, em instancia MySQL 8 isolada com rede desativada e socket UNIX privado, todas as 27 tabelas restauradas, cleanup executado. Backup anterior de 2026-10-28 tambem tem restauracao PASS. **Copia externa nao comprovada**.
- Staging **desligado**, nao publicando porta: `marina-pricing-loopback-v5-staged-20261031`, Docker container ID prefix `f9fb4d66ab5b`, `restart=no`, host bind `127.0.0.1:13312`. A comparacao confirmou mesma imagem por digest, mesmo volume `ml-pricing-prod-db`, mounts, ambiente, comando e entrypoint do container atual (ID prefix `a2d3acbd011f`). Nao houve acesso simultaneo ao datadir por dois processos mysqld.
- Em 2026-10-31 02:51 UTC, apos a **recusa explicita** da tentativa de cutover pela configuracao de seguranca da plataforma OpenAI, readback nao mutante: original `running=true`, `restart=unless-stopped`, bind wildcard; staging `running=false`, `restart=no`, bind loopback; worker ativo; site HTTP200. **Nao houve cutover.**

## 3. Autorizacao solicitada ao proprietario (escopo minimo)

Solicita-se autorizar **apenas** uma manutencao transacional no host Marina, realizada por executor e canal que sejam aceitos pelo provedor e que tenham permissao atual comprovada, sem contornar refusas. A operacao permitida, **depois do aceite legitimo**, limita-se a: reservar ownership/lock de manutencao sem tomar lease vigente; confirmar backup-restauracao e volume original; suspender o worker local de forma controlada; parar graciosamente o container original, preserva-lo desligado como rollback; colocar a configuracao de staging revisada como principal no mesmo volume, imagem e ambiente e com porta somente em `127.0.0.1`; iniciar o banco e retomar o worker; executar testes de persistencia, conectividade, isolamento IPv4/IPv6, rollback e publicacao. Nao criar segundo writer nem manter dois containers mysql vivos no mesmo volume.

**Fora do escopo:** mudar precos, tabelas, credenciais, conteudo de dados, firewalls genericos, NSG/OCI, DNS, tokens, politicas de seguranca, VM backend, origem descomissionada, compras reais, permissoes de MCP ou concessao de root indiscriminada. Apos sucesso, guardar rollback desligado e sem auto-start ate aceite final da auditoria; retencao/limpeza posterior devem ser supervisionadas e comprovadas.

## 4. Bloqueio do provedor — nao executar por canal alternativo

A solicitacao de cutover foi explicitamente recusada antes de despachar task: `Esta ferramenta foi bloqueada pelas configuracoes de seguranca da OpenAI`. Isto **NAO** e um erro transitorio de comando e **NAO** autoriza dividir a operacao, codifica-la, tentar outro MCP/SSH/RDC para obter o mesmo privilegio, alterar filtros ou contornar o controle. A preparacao e verificacoes somente leitura sao permitidas e estao registradas.

Conforme `docs/knowledge/security-approval-via-pr.md`, o merge pessoal e autenticado do PR e prova da decisao do proprietario para o diff **apenas quando o autorizador aceita esse fluxo**. O merge nao modifica por si a seguranca do OpenAI Platform/OCI nem obriga um provedor externo a executar uma acao recusada. Se o provedor exigir consentimento/RBAC/operacao administrativa propria, registrar e obter essa autorizacao pelo mecanismo oficial do proprio provedor; **na ausencia, manter a operacao pendente**.

## 5. Gates, plano de rollback e criterios de aceite

- [ ] Owner humano identificavel exclusivamente (nao agente nem conta partilhada) revisou este exato SHA, aprovou e fez pessoalmente o merge, depois de todos os gates obrigatorios GREEN. Sem auto-merge. Se mudar o diff, nova aprovacao.
- [ ] O canal de manutencao e efetivamente permitido pelo provedor; lease/fencing de ownership exclusivos e validados, sem sobrepor owner ativo. Guardar somente identificadores sanitizados.
- [ ] Verificar SHA256 do backup, manifesto `restoration_test=PASS`, 27 tabelas, integridade do volume, imagem por digest, mounts, env e ausencia de outro MySQL utilizando esse datadir. Cópia off-host/fora da mesma VM comprovada antes de declarar resiliencia completa.
- [ ] Se o cutover falhar, reativar a configuracao original previamente preservada, verificar SQL e worker; nenhuma destruicao de volume/rollback. A tentativa deve registrar falha e estado final, nao apenas diagnostico.
- [ ] No sucesso: Docker inspect mostra `HostIp=127.0.0.1, HostPort=13312` e **nao** `0.0.0.0`/`::`; `ss -lnt` sem listener wildcard 13312; teste remoto contra 13312 **negado** e local SQL/27 tabelas **GREEN**.
- [ ] Worker Pricing e site publico saudaveis; verificar paridade catalogo/ERP pertinente sem alteracao de preco, dados ou compra real. Reinicio seguro de sistema e persistencia de bind/restart policy somente quando janela e gate autorizados.
- [ ] Registrar evidencias de regressao E2E/UI pertinente, estado de rollout, rollback preservado, revisor independente e certifier deterministico. **Nao declarar APTO apenas porque banco conectou ou o PR foi merged.**

## 6. Trilhas de auditoria

- Issue [#3038](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3038#issuecomment-6538040107): inventario, backups e recusa do cutover.
- Execucao do backup: audit `46f03844-ec22-449d-bf05-eca2c11efec3`.
- Restore isolado 27 tabelas: audit `4a85e4cb-1784-406c-b1d9-86dd6076ab99`.
- Staging imutavel e bind loopback: audit `a15d9106-cedd-40bb-824c-82d642d940d3`.
- Readback apos recusa: audit `f9834861-9f08-4040-9f58-562093848e28`.

**Limite declarado:** este documento e uma solicitacao de aprovacao, nao a implantacao do corte nem um mecanismo para substituir a autorizacao do provedor.