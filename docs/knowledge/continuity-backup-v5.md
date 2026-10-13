# Backup V5 de checkpoints de persistência (13/10/2026)

O backend canônico usa `/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state` como estado durável. O backup V5 é **local e privado**: não substitui backup externo nem restaura automaticamente a conversa do ChatGPT.

## Implementação
- Código: `scripts/continuity/backup_state_v5.py`.
- Instalador: `scripts/continuity/install_backup_v5.sh`.
- Serviço: `shopvivaliz-continuity-state-backup.service`, `Type=oneshot`, `ProtectSystem=strict`, sem rede.
- Timer: `shopvivaliz-continuity-state-backup.timer`, duas vezes ao dia (03:00 e 15:00 UTC), persistente entre reinícios.
- Destino: `/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-backups`, diretório root 0700; arquivos tar.gz e sha256 root 0600.

## Garantias e limites
O programa bloqueia a escrita concorrente via `_agent-task-state.lock`, rejeita symlinks e JSON inválido, não inclui prompts temporários, gera o arquivo no mesmo diretório em modo privado, reabre a cópia, valida todos os JSONs e só então a publica com `sha256`. Não copia perfis de navegador, cookies, OTP ou tokens.

O instalador executa primeiro uma cópia real, confirma `sha256sum -c` e só então habilita o timer. Ele não reinicia ou encerra navegadores, bridge ou controlador. A retenção/descarte automático e a replicação externa continuam fora do escopo; **não remover backups sem prova de restauração e sem política aprovada**.

## Verificação
```bash
systemctl list-timers shopvivaliz-continuity-state-backup.timer --no-pager
systemctl show shopvivaliz-continuity-state-backup.service -p Result -p ExecMainStatus
sudo ls -ld /home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-backups
sudo journalctl -u shopvivaliz-continuity-state-backup.service -n 20 --no-pager
```

Para validar a restauração, extrair uma cópia de teste em um diretório **isolado**, conferir a integridade com `sha256sum -c` e ler os JSONs restaurados. Nunca extrair sobre o diretório vivo de checkpoints e não declarar `continuity_ready=true` apenas porque o backup está saudável.


## Recuperação corretiva auditável

Quando o timer apresentar `75/TEMPFAIL` por lease efêmero, nunca apagar
checkpoints, cookies ou backups. O patch aplicado em `main` deve ser
promovido ao backend pela operação allowlisted do GitHub Remote Access,
separada da automação horária **somente leitura**:

```text
/remote target=always-free-arm-1787907847-26 action=continuity_backup_v5_repair reason=Validar e reinstalar backup V5 canonico
```

Este comando depende de runner autorizado, SSH privado com host key fixada,
identidade exata da VM e script da revisão versionada. Antes de escrever,
confere SHA-256 do código e espaço livre (ao menos 2× o tamanho dos
checkpoints + 256 MiB). Espaço insuficiente **bloqueia** a instalação sem
limpar dados existentes. O instalador promove apenas o serviço de backup,
executa um backup de verdade e comprova checksum e timer ativo.
`BACKUP_V5_ONESHOT_AND_TIMER=PASS` não comprova recuperação das conversas
ou `PROGRESS_CONFIRMED`; esse gate continua separado.

A operação não reinicia Chromium, altera perfis/credenciais, cria binding
histórico, modifica checkpoints ou promove o controlador. Nunca adicionar
esta ação ao monitor horário read-only.
