# Acesso remoto operacional sem Desktop Commander

## Objetivo

Este canal existe para manter acesso remoto aos quatro hosts ShopVivaliz sem depender da cota mensal do Desktop Commander.

Fluxo canônico:

```text
ChatGPT / agente
  -> GitHub connector
  -> comentario auditavel no issue #1586
  -> GitHub Actions
  -> runner self-hosted shopvivaliz-free-a1
     -> execução local no site VM
     -> SSH privado 10.0.1.38 para backend VM
     -> relay 127.0.0.1:5557 para Fred-Win
     -> relay 127.0.0.1:5558 para KOCEPSV
```

## Hosts suportados

- `shopvivaliz-free-a1`
- `always-free-arm-1787907847-26`
- `fred-win`
- `kocepsv`

## Ações iniciais allowlisted

- `health`
- `identity`
- `disk`
- `runtime_status`
- `repo_status`
- `ai_squad_ui_audit` (somente `shopvivaliz-free-a1`; orquestra login do admin interno, browser backend e auditoria UI completa)

O comando não aceita shell arbitrário. Para adicionar uma operação nova, altere o workflow ou adicione um script versionado e revisável.

## Como acionar

No issue `#1586`, publique um comentário de uma única linha:

```text
/remote target=fred-win action=identity reason=diagnostico operacional

# Auditoria funcional real do AI Squad pela UI
/remote target=shopvivaliz-free-a1 action=ai_squad_ui_audit reason=auditoria extrema AI Squad
```

O workflow `.github/workflows/shopvivaliz-remote-access.yml` aceita somente comentários criados nesse issue pelo usuário autorizado `fredmourao-ai`. Também pode ser acionado manualmente por `workflow_dispatch` com inputs tipados.

O arquivo `ops/remote-access-request.json` permanece temporariamente apenas como registro legado para não interromper execuções concorrentes. Ele não é mais gatilho do canal canônico.

Esse modelo evita commits operacionais repetitivos e não dispara os demais pipelines de `push` do repositório.

## Operações MLRR no runner canônico

O runner `shopvivaliz-a1-deploy` é registrado no repositório `Vivaliz-site/site-shopvivaliz`. Portanto operações de produção do repositório `Vivaliz-site/mercadolivre-returns-recovery` devem ser disparadas pelo bridge dedicado `.github/workflows/mlrr-production-ops-bridge.yml`, e não por workflows self-hosted dentro do próprio repositório MLRR.

No issue canônico `#1586`, o comando auditável é:

```text
/mlrr operation=rollout reason=promover e validar MLRR main
```

Operações permitidas: `prepare`, `cutover`, `validate`, `shadow`, `preflight` e `rollout`. `rollout` executa a sequência fixa `prepare -> shadow -> validate -> preflight`. O bridge aceita somente comentários do usuário autorizado `fredmourao-ai`, sincroniza `main` por fast-forward, exige árvore limpa e registra provenance assinado no host. Ele não aceita shell arbitrário.

Se um dos relays Windows estiver indisponível, use no mesmo issue `#1586`:

```text
/recover-windows reason=restaurar relays privados Windows
```

Esse comando aciona `.github/workflows/windows-private-peer-recovery.yml`, que tenta recuperar Fred-Win e KOCEPSV pela rede privada/Tailscale via backend VM, sem expor RCE público.

Se o runner privado estiver indisponível ou congestionado, use o fallback independente:

```text
/recover-windows-oci reason=recuperar relays Windows sem depender do runner privado
```

O fallback executa em `ubuntu-latest` e usa **OCI Bastion** para abrir um túnel SSH temporário até a backend VM. Ele gera um par SSH efêmero exclusivo para autenticar a sessão Bastion; a chave ShopVivaliz permanece separada e é usada somente para autenticar backend/Windows via agent forwarding. Nenhuma chave privada é enviada em OCI Run Command, issue, artifact ou log. O workflow acrescenta somente o IP `/32` do runner à allowlist do Bastion durante a sessão, restaura a lista original no cleanup, usa SSH agent forwarding até a backend e alcança Fred-Win/KOCEPSV pela rede Tailscale. Assim, a recuperação não depende nem do Desktop Commander nem do runner `shopvivaliz-a1-deploy`.

## Segurança

- Nenhuma chave, token, senha ou OTP é gravada no comentário.
- SSH usa secrets do GitHub e `known_hosts` verificado. O fallback OCI usa Bastion com sessão temporária e nunca copia chave privada para OCI Run Command ou para as VMs.
- Windows permanece loopback-only atrás dos relays privados já existentes.
- Toda execução fica associada ao issue/comment, workflow run, target, action e motivo.
- O canal não autoriza bypass de branch protection, exposição pública de RCE ou leitura de secrets.

## Relação com Desktop Commander

Desktop Commander passa a ser canal opcional/fallback quando houver cota e necessidade específica. O canal GitHub Remote Access deve ser preferido para diagnóstico e operação de terminal/serviços, pois não depende da cota mensal do DC.


## RustDesk self-hosted (acesso grafico principal)

O RustDesk passa a ser o canal grafico principal das duas VMs Linux, sem depender da cota do Desktop Commander.

Arquitetura:

- servidor OSS `hbbs` + `hbbr`: `always-free-arm-1787907847-26`;
- endereco interno dos clientes das VMs: `10.0.1.38`;
- endereco Tailscale para clientes humanos: `100.66.174.74`;
- cliente RustDesk instalado em `shopvivaliz-free-a1` e `always-free-arm-1787907847-26`;
- chave publica gerada e persistida em `/opt/shopvivaliz-rustdesk-server/data/id_ed25519.pub`;
- senha de acesso nao assistido gerada localmente e mantida root-only em `/etc/shopvivaliz/rustdesk-unattended-password`; ela nunca deve ser escrita em issue, workflow log ou artifact;
- portas de web client `21118/21119` nao sao usadas e sao bloqueadas localmente;
- Desktop Commander fica somente como fallback.

Acoes auditaveis no issue #1586:

```text
/remote target=always-free-arm-1787907847-26 action=rustdesk_server_install reason=instalar servidor RustDesk self-hosted
/remote target=always-free-arm-1787907847-26 action=rustdesk_client_install reason=configurar cliente RustDesk backend
/remote target=shopvivaliz-free-a1 action=rustdesk_client_install reason=configurar cliente RustDesk web
/remote target=always-free-arm-1787907847-26 action=rustdesk_status reason=validar RustDesk backend
/remote target=shopvivaliz-free-a1 action=rustdesk_status reason=validar RustDesk web
```

A VM web deve permanecer fail-closed quanto a GUI: se nao houver sessao grafica utilizavel, a instalacao do cliente nao deve substituir nem interferir nos servicos web. Um desktop virtual dedicado deve ser provisionado separadamente e validado antes de ser promovido como acesso grafico.
