# ShopVivaliz / Marina - checkpoint seguro (18/10/2026)

**ESTADO: NO_CUT / NAO_APTO.** A migracao nao esta pronta para trocar DNS.
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
