# 🐧 Instalação Automática - Ubuntu / Cloud

Para instalar auto-sync em Ubuntu ou Cloud, execute:

\\\ash
# Opção 1: Setup Completo (requer sudo)
sudo bash scripts/setup-auto-sync-linux.sh

# Opção 2: Setup Rápido (sem systemd, apenas cron)
bash scripts/bootstrap.sh

# Opção 3: Rodar manualmente
bash scripts/auto-sincronizar.sh
\\\

## Verificar Status

\\\ash
# Systemd (se instalado com Opção 1):
systemctl status shopvivaliz-sync

# Logs:
journalctl -u shopvivaliz-sync -f

# Cron:
crontab -l | grep shopvivaliz
\\\

## Próximos Passos

1. SSH na máquina Ubuntu/Cloud
2. Clone o repo: \git clone ... && cd site-shopvivaliz\
3. Execute: \sudo bash scripts/setup-auto-sync-linux.sh\
4. Pronto! Tudo automático!
