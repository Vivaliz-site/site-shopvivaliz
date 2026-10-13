# Integridade dos logins ChatGPT na VM ShopVivaliz

Data da ativacao: 2026-10-13. Host: `always-free-arm-1787907847-26`.

## Problema e limites da garantia

As contas Dev canonica (CDP 9559), Atendimento (9556) e Dev auxiliar (9557)
estavam autenticadas na verificacao E2E apos a recuperacao manual de MFA.
Essas sessoes devem ser preservadas nos seus proprios perfis Chromium,
sem logout, perfil temporario, copia de cookies ou token entre contas.

Nao e tecnicamente possivel garantir que um provedor nunca expire ou revogue
uma sessao. O objetivo e **evitar perda acidental do perfil e detectar queda
de autenticacao cedo, sem tentar contornar MFA**.

## Controles instalados

* Unit permanente: `shopvivaliz-login-integrity.timer`, habilitada,
  `OnCalendar=*:0/5`, primeira tentativa tambem via `OnBootSec=2min`.
* Service somente leitura: `shopvivaliz-login-integrity.service` executa
  `/usr/local/libexec/shopvivaliz-login-integrity.py`. Le as APIs oficiais
  de sessao dos navegadores canonicos pelo probe autorizado do MCP
  e o `/health` autorizado do Dev auxiliar, sem navegar ou submeter dados.
* Cada uma das tres units Chromium tem um drop-in independente
  `99-profile-preservation.conf` com `ExecStartPre=/usr/bin/test -s`
  no respectivo `Local State`. Se o arquivo do perfil estiver ausente,
  falha fechada, em vez de recriar um perfil vazio.
* Os diretórios de perfil permanecem em `/home/fredrdp/.config/`, restritos
  ao dono e **nao** sao copiados, backupados ou exportados por estes controles.
* Metadados apenas: `/var/lib/shopvivaliz-login-integrity/state.json`
  (root 0600), com timestamp, estados de autenticacao, integridade dos perfis
  e contagem de falhas. Nada de senhas, cookies, tokens ou OTP.

Estados: `HEALTHY` se todas as tres identidades e os perfis estiverem
confirmados; `WATCH` na primeira falha; `DEGRADED` a partir da segunda
verificacao consecutiva ruim. Em `DEGRADED`, a service falha e registra
alerta no journal, mas **nao** reinicia os navegadores nem executa login.

A unit systemd oneshot aparecer `inactive (dead)` apos uma execucao bem
sucedida e **normal**. A timer deve permanecer `active (waiting)`.

## Inspecao (somente leitura)

```bash
systemctl is-enabled shopvivaliz-login-integrity.timer
systemctl list-timers shopvivaliz-login-integrity.timer --no-pager
systemctl show shopvivaliz-login-integrity.service -p Result -p ExecMainStatus
sudo journalctl -u shopvivaliz-login-integrity.service -n 15 --no-pager
sudo python3 -c 'import json;d=json.load(open("/var/lib/shopvivaliz-login-integrity/state.json"));print(d["status"],d["checked_at"])'
```

Prova de autenticacao E2E sempre exige `browser_auth_status(session="dev")`
e `browser_auth_status(session="atendimento")` com `identity_match=true`,
mais MCP Dev `127.0.0.1:5583/health` validando a identidade exata.
A variavel `continuity_ready=false` pode permanecer por checkpoints
historicos sem vinculacao: isso nao significa logout das contas.

## Instalacao idempotente e validacao

Fonte versionada:
* `scripts/chatgpt-continuity/shopvivaliz-login-integrity.py`
* `ops/systemd/shopvivaliz-login-integrity.service`
* `ops/systemd/shopvivaliz-login-integrity.timer`
* `scripts/chatgpt-continuity/install-login-integrity.sh`

Executar o instalador somente do clone/release autorizado, depois de validar
sua origem, o estado vivo e as aprovacoes normais. Ele confirma a existencia
dos perfis e unidades antes de instalar, executa smoke test e **compara PIDs**
antes/depois. `systemctl daemon-reload` nao reinicia Chromium.

Para suspender o monitor em manutencao autorizada,
`sudo systemctl disable --now shopvivaliz-login-integrity.timer`.
Nao parar, reiniciar, apagar ou transportar os perfis para "corrigir" alertas.
As protecoes de inicializacao sao independentes da timer; removelas exige
uma mudanca revisada. Nao tocar nos opt-ins `90-verified-dev-aux.conf` do
worker, guardian e MCP sem o procedimento especifico de rollback.

## Bloqueio preventivo de logout por automacao

Desde 2026-10-13, o Remote Control MCP impede navegacao automatizada
para URLs de logout do ChatGPT/OpenAI e clicks nos controles explicitos
`Log out`, `Sign out`, `Sair` e equivalentes via CSS/indice CDP.
Formularios de logout acionados por `browser_type` tambem falham
fechados. As ferramentas graficas bloqueiam navegacao para URLs de
logout, mas a GUI direta do proprietario continua sob seu controle.
O MCP pode ser atualizado sem reiniciar os tres Chromiums: veja
`scripts/chatgpt-continuity/install-corporate-logout-guard.sh`.

O bloqueio nao impede logout humano voluntario nem expiracao/revogacao
pelo provedor. A verificacao periodica `shopvivaliz-login-integrity.timer`
continua necessaria e independente desse bloqueio.
