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

No issue `#1586`, publique um comentário no formato:

```text
/remote target=fred-win action=identity reason=diagnostico operacional
```

O workflow `.github/workflows/shopvivaliz-remote-access.yml` aceita somente comentários criados nesse issue pelo usuário autorizado `fredmourao-ai`. Também pode ser acionado manualmente por `workflow_dispatch` com inputs tipados.

Esse modelo evita commits operacionais repetitivos e não dispara os demais pipelines de `push` do repositório.

## Segurança

- Nenhuma chave, token, senha ou OTP é gravada no comentário.
- SSH usa secrets do GitHub e `known_hosts` verificado.
- Windows permanece loopback-only atrás dos relays privados já existentes.
- Toda execução fica associada ao issue/comment, workflow run, target, action e motivo.
- O canal não autoriza bypass de branch protection, exposição pública de RCE ou leitura de secrets.

## Relação com Desktop Commander

Desktop Commander passa a ser canal opcional/fallback quando houver cota e necessidade específica. O canal GitHub Remote Access deve ser preferido para diagnóstico e operação de terminal/serviços, pois não depende da cota mensal do DC.
