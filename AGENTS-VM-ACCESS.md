# Acesso direto dos agentes as VMs

Esta e uma instrucao operacional obrigatoria para agentes que precisem executar comandos nas VMs Oracle Cloud da ShopVivaliz. Nao coloque chaves privadas, tokens, senhas, headers de autenticacao ou conteudo de `security_token_file` em commits, logs, issues, PRs ou prompts.

## VMs atuais

- `vm1` / `shopvivaliz-free-a1`
- `vm2` / `always-free-arm-1787907847-26`
- Regiao OCI: `sa-saopaulo-1`
- Controlador OCI canonico: Fred-Win (`LAPTOP-NIG4IFUU`)
- Perfil OCI obrigatorio: `AGENTS`
- Config local protegida: `C:\Users\FRED\.oci\agents\config`

## Ordem de acesso

1. Remote Desktop Commander diretamente pelo `device_name`, quando online.
2. SSH somente quando a chave e o host tiverem sido validados; nunca use `StrictHostKeyChecking=no`.
3. OCI Compute Instance Run Command como fallback independente de SSH/porta de entrada.
4. OCI serial console somente como ultimo recurso de recuperacao.

## Comando canonico via OCI

No Fred-Win, use o helper instalado em `C:\Users\FRED\.local\bin\sv-oci-vm-run.ps1`:

```powershell
sv-oci-vm-run vm1 "hostname; id; pwd"
sv-oci-vm-run vm2 "hostname; id; pwd"
```

O helper usa exclusivamente o perfil `AGENTS`, cria um `Instance Agent Command`, aguarda estado terminal, imprime o stdout e devolve o exit code remoto. Ele nao depende de SSH.

Para comandos de diagnostico ou scripts curtos:

```powershell
sv-oci-vm-run shopvivaliz-free-a1 "systemctl is-active apache2; df -h /"
sv-oci-vm-run always-free-arm-1787907847-26 "docker ps; systemctl --failed"
```

## Estado validado em 2026-09-06

Nas duas VMs o Oracle Cloud Agent snap esta habilitado e ativo, e o plugin `Compute Instance Run Command` aparece como `RUNNING`. Testes reais via API OCI retornaram `SUCCEEDED` e exit code `0` nas duas instancias.

O Run Command executa como usuario `ocarun` (UID 999) por padrao. Nao presuma privilegio root. Para operacao administrativa, use um canal ja autorizado com sudo ou uma regra de privilegio minimo previamente configurada; nao tente contornar controles do host.

## Seguranca obrigatoria

- Nunca copiar a chave privada OCI para as VMs apenas para facilitar automacao.
- Nunca usar perfil `DEFAULT` como fallback; usar somente `AGENTS`.
- Nunca colocar segredo no texto de Run Command. Para dados sensiveis, use mecanismo seguro aprovado (Vault/Object Storage protegido) e minimo privilegio.
- Validar resultado por `lifecycle-state`, stdout e exit code; criacao do comando ou estado `ACCEPTED` nao prova execucao.
- Se `Run Command` ficar parado, confirmar o status do plugin e revisar `/var/log/oracle-cloud-agent/plugins/runcommand/runcommand.log` antes de alterar configuracao.
- Nao interromper o Oracle Cloud Agent ou o plugin a partir de um Run Command.

## Verificacao minima

Antes de declarar acesso operacional:

```powershell
oci iam region list --config-file C:\Users\FRED\.oci\agents\config --profile AGENTS
sv-oci-vm-run vm1 "echo OCI_RUN_COMMAND_OK; hostname; id -u"
sv-oci-vm-run vm2 "echo OCI_RUN_COMMAND_OK; hostname; id -u"
```

Resultado esperado: identidade OCI valida, hostname correto da VM, estado `SUCCEEDED` no backend e exit code `0`.
