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

O comando não aceita shell arbitrário. Para adicionar uma operação nova, altere o workflow ou adicione um script versionado e revisável.

## Como acionar

No issue `#1586`, publique um comentário de uma única linha:

```text
/remote target=fred-win action=identity reason=diagnostico operacional
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
