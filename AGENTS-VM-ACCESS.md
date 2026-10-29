# Acesso direto dos agentes as VMs

Instrucao operacional obrigatoria para agentes que precisem executar comandos nas VMs Oracle Cloud da ShopVivaliz. Nunca versionar, imprimir ou copiar para logs/prompts chaves privadas, tokens, senhas, headers de autenticacao ou conteudo de `security_token_file`.

## VMs atuais — 2026-10-29

| Tenancy | Host | Endereco documentado | Papel |
| --- | --- | --- | --- |
| ESPOSA | `marina-a1-4c24g` | OCI privado `10.80.1.116`; Tailscale observado `100.118.170.46` | **producao web/deploy**, MySQL, workers, runner e Cloudflare admin |
| PRINCIPAL | `always-free-arm-1787907847-26` | OCI privado `10.0.1.38`; Tailscale observado `100.66.174.74` | backend/controller MCP/browser/MEI/M365 |

**Origem excluida:** `shopvivaliz-free-a1` (antiga `vm1`; `10.0.1.112`). Exclusao relatada pelo proprietario; SSH sem rota em 2026-10-29, lifecycle OCI nao auditado. **Nunca executar comandos por alias `vm1`, `shopvivaliz-a1-site-raw`, ou enderecos legados.** A antiga `vm2` e o perfil local `AGENTS` eram ligados a topologia historica; nao pressupor que permitem acessar Marina/ESPOSA.

As contas OCI `PRINCIPAL` e `ESPOSA` exigem identidades isoladas, conforme `docs/ops/oci-dual-account-access.md`. Enderecos Tailscale sao observacoes, nao prova de SSH. Ver `docs/knowledge/current-topology-and-access-2026-10-29.md`.

## Ordem obrigatoria de acesso

1. Backend: ShopVivaliz Remote Control MCP, somente para hosts vivos/allowlisted. A allowlist ainda inclui origem excluida e nao inclui Marina.
2. Marina: RDC 1/2 direto, com descoberta do `deviceId` em `list_devices` e `hostname -s`, `id -un` antes de operar. Ambos listavam Marina online em 2026-10-29.
3. SSH privado/VCN/Tailscale: somente se autenticacao/host key/permissoes forem comprovadas; nao habilitar acesso publico.
4. Runner self-hosted **da Marina** para CI/deploy versionado; GitHub nao substitui shell/heartbeat/queue da controladora.
5. OCI Bastion/Run Command/console serial: somente por canal oficial aprovado para bootstrap/recovery, sem burlar negativas de privilegio.

`shopvivaliz-cloudflared-admin.service` ativo nao e equivalente a MCP OpenAI. `shopvivaliz-marina-mcp.service` e `shopvivaliz-marina-mcp-tunnel.service` estavam inativos em 2026-10-29. Um `NoNewPrivileges=no` observado na unidade RDC nao concede privilegios nem revoga bloqueios/controles existentes.

## Shell direto via token/API OCI

**Os comandos antigos `sv-oci-vm-run vm1` e `sv-oci-vm-run vm2` nao sao instrucoes atuais**. Antes de novo Run Command, confirmar explicitamente a tenancy OCI, compartment, lifecycle, instance OCID, perfil autorizado e o suporte do agente. O perfil legado `AGENTS` do Fred-Win nao comprova acesso a conta ESPOSA. Nao converter `vm1` em um alias da Marina sem implementacao revisada e validada. Consultar `docs/ops/oci-dual-account-access.md`.

### Privilegio do Run Command

- O Run Command executa como `ocarun` (UID 999) nas duas VMs.
- Em validacao real de 2026-09-06, `sudo -n` como `ocarun` exigiu senha nas duas VMs. Portanto **nao presuma root pelo token OCI**.
- Use Run Command para shell/diagnostico que nao exija root. Para operacao administrativa, use o SSH autorizado abaixo ou o console serial de recuperacao.
- `ACCEPTED` significa apenas que o comando foi aceito. Sucesso exige `SUCCEEDED`, stdout coerente e exit code `0`.
- O polling do Oracle Agent usa jitter e pode levar alguns minutos. Nao relance comandos duplicados apenas porque permaneceram em `ACCEPTED` por um ciclo.

## SSH administrativo canonico

O alias historico `shopvivaliz-a1-site-raw` aponta a alvo descomissionado e nao deve ser usado. Acesso atual SSH exige destino privado, usuario/chave protegidos ja autorizados, `known_hosts` valido e identidade real comprovada. Nao executar o alias antigo para conferir se a origem voltou. Nunca usar `StrictHostKeyChecking=no`.

## Remote Desktop Commander

Em 2026-10-29, os conectores RDC 1/2 listaram `marina-a1-4c24g` e `LAPTOP-NIG4IFUU` online; backend e origem apareceram offline **no inventario RDC**, embora o backend estivesse acessivel por MCP central. A origem nao deve ser utilizada. Descobrir o ID por nome a cada sessao; validar autorizacao e privilegios efetivos, nao somente conexao.

## Evidencia historica 2026-09-06 (nao usar como acesso atual)

- Oracle Cloud Agent e plugin `Compute Instance Run Command`: `RUNNING` nas duas instancias pela API OCI.
- Helper `sv-oci-vm-run vm1`: retornou `HELPER_OK`, `shopvivaliz-free-a1`, `ocarun`, exit code `0`.
- Helper `sv-oci-vm-run vm2`: retornou `HELPER_OK`, `always-free-arm-1787907847-26`, `ocarun`, exit code `0`.
- SSH Git + alias `shopvivaliz-a1-site-raw`: acesso administrativo validado, `sudo -n id -u` = `0`.
- SSH Git + alias `shopvivaliz-a1-backend-raw`: acesso administrativo validado, `sudo -n id -u` = `0`.

## Diagnostico e seguranca

- Validar OCI antes de operar: `oci iam region list --config-file C:\Users\FRED\.oci\agents\config --profile AGENTS`.
- Se Run Command ficar parado, revisar o plugin e `/var/log/oracle-cloud-agent/plugins/runcommand/runcommand.log`.
- Nunca colocar segredo no texto de Run Command. Use mecanismo seguro aprovado para dados sensiveis.
- Nunca copiar a chave privada OCI para as VMs por conveniencia.
- Nunca imprimir a chave SSH privada nem a chave OCI em logs, issues, PRs ou respostas.
- Nao interromper o Oracle Cloud Agent ou o plugin a partir de um Run Command.
- Depois de qualquer comando de mudanca, executar verificacao real independente antes de declarar sucesso.
