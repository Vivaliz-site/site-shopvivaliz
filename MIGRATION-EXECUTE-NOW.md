<!-- RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->
**AVISO OPERACIONAL (2026-10-29):** a VM de origem `shopvivaliz-free-a1` foi excluída conforme confirmação do proprietário. As etapas históricas abaixo que requerem **manter, acessar, monitorar, validar, interromper ou recriar** essa VM (incluindo `NO_DECOMMISSION` e critérios dos "quatro hosts") **não são instruções executáveis atuais**. A produção web está em `marina-a1-4c24g`, o backend/controller em `always-free-arm-1787907847-26`. Não substituir aliases/SSH/tenancy da origem por Marina. Preservar backups e evidência acessível, rever gates ainda pertinentes e seguir `docs/knowledge/current-topology-and-access-2026-10-29.md`. A exclusão não prova `APTO`, integração de MCP novo, paridade de dados ou passagem de E2E; `GLOBAL_MIGRATION_STATUS=NAO_APTO` enquanto houver gates pendentes.
<!-- /RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->

# ShopVivaliz / Marina - checkpoint seguro e retomada

## Atualizacao verificada — 2026-10-20 04:31–04:32 UTC

**PUBLIC_ROUTE_OBSERVED=marina-a1-4c24g | SITE_PUBLIC_AUDIT_STATUS=APTO**

O site publico `shopvivaliz.com.br` ja encaminhou uma requisicao HTTPS identificada a release `99416b61` na Marina: HTTP 200 e 1 registro no Apache da Marina, 0 na origem, para marcador exclusivo. Isso e prova pontual do roteamento via Cloudflare; a auditoria de publicacao web/AI Squad no GitHub (PR #3160) possui `AUDIT_VERDICT=APTO` **limitado ao seu escopo**, nao uma certificacao de toda a migracao.

**GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION**

A VM `shopvivaliz-free-a1` e os backups devem ser preservados. Origem com workers protegidos por fencing; na Marina, o Pricing continua `inactive/disabled` e MySQL de validacao em `0.0.0.0:13312` e `[::]:13312` sem volume/backup/firewall/restauro certificados. Supabase e seu transporte protegido, paridade final, boot SAFE-T, MCP independente, rollback/reboot e custos/quotas Oracle exigem aprovacao e prova especificas. Nao desfazer o roteamento publico nem reativar writers na origem por inferencia. Nao contornar recusas de plataforma. Ver `docs/quality/AUDIT_STATUS.md` e `docs/ops/marina-independent-secure-mcp-tunnel.md`.

## Historico — checkpoint de 18/10/2026

**ESTADO HISTORICO: NO_CUT / NAO_APTO.** Em 18/10 ainda nao havia prova do site publico servido na Marina. As instrucoes abaixo documentam problemas anteriores e NAO substituem as observacoes recentes.
Um script criado ou um agendador com exit 0 nao comprova E2E, backup ou integridade.

## Ponto de parada do Codex
O launcher legado C:\Users\FRED\execute-migration-checkpoint.ps1 continha:
- Marina apontando para 10.0.1.112, que e o IP privado da origem.
  A Marina tem IP privado 10.80.1.116.
- Desabilitacao da verificacao da identidade do host remoto.
- Tentativa de criar um sentinel no host errado e reinstalar modulos.
- Indicador P0 sempre aprovado e falso APTO baseado em caracteres de logs.
- Chamadas a 3 validadores inexistentes neste checkout.

O original foi preservado em backup local. O launcher foi substituido por
um wrapper que chama SOMENTE o preflight versionado, sem efeitos remotos.

## Continuacao autorizada (somente leitura)
No PowerShell do Fred-Win, dentro de C:\site-shopvivaliz:

    powershell -NoProfile -File .\scripts\windows\marina-precutover-preflight.ps1

O resultado esperado e MIGRATION_GATE=NO_CUT, com exit 2 intencional.
A aprovacao dos 18 testes Windows de worktree (18/10) NAO e certificacao da
migracao; os testes Linux/CI e E2E real da Marina seguem como gates separados.

## Fonte de verdade / pendencias
- docs/ops/oci-marina-migration-v5-20261017.md
- docs/ops/marina-migration-codex-handoff-20261017.md
- backend: /home/ubuntu/migration-marina-a1/STATUS.md

Gates pendentes: sincronizacao final autorizada de dados e filas, paridade de
release, restauracao Supabase permitida, zero duplicate writers, SAFE-T e OAuth,
UI real completa do destino, certificados/tunnel Cloudflare, CI/deploy,
reboot/rollback e aprovacao do cutover. Nao apagar a origem, nao mudar DNS,
nao executar metodos recusados de transferencia/IAM e nao duplicar workers.

## SSH / chave ja definida
Reutilizar a chave autorizada em C:\Users\FRED\Downloads\ssh-key-2026-07-04.key
tanto para a origem quanto para Marina, sem gerar outra chave ou transportar segredo.
Validar separadamente a host key de cada VM com StrictHostKeyChecking habilitado.
Chave presente e hosts publicos registrados no known_hosts do Fred-Win.
O teste SSH interativo desta retomada nao terminou; nao afirmar login remoto GREEN
com base apenas no arquivo. Isso nao exige uma nova chave e nao autoriza cutover.
