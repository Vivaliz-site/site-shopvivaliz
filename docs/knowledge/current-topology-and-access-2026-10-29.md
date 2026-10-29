# Topologia, acessos e MCPs vigentes — 2026-10-29

> **Leitura obrigatoria para ChatGPT Dev/Atendimento, Claude, Codex, Gemini, Gepeto, subagentes, controladores e automacoes** antes de operar hosts. Este inventario prevalece sobre topologias em relatorios historicos, mas deve ser revalidado no runtime a cada sessao. Fonte de bootstrap: [host-access.md](host-access.md), [agent-rules.md](agent-rules.md) e `AGENTS.md`.

## Contrato de roteamento para todos os agentes (2026-10-29)

**Aplica-se a GPT Dev/Atendimento, Claude, Codex, Gemini, Gepeto, Copilot, Cursor, Windsurf, subagentes, agentes de QA/UI, runners e controladores.** Ler esta página, `host-access.md` e `agent-rules.md` no `main` e registrar SHA antes de cada retomada ou alteração.

| Destino / função | Rota de acesso comprovada | Não presumir |
|---|---|---|
| `marina-a1-4c24g` (OCI ESPOSA): produção web, releases, MySQL, workers e runner | RDC direto via `list_devices` pelo nome; validar `hostname -s`, `id -un`, release e permissão | Que o MCP central execute Marina; que RDC 2, SSH ou sudo estejam automaticamente habilitados |
| `always-free-arm-1787907847-26` (OCI PRINCIPAL): backend, MCP, navegador Dev/Atendimento, MEI/M365 | ShopVivaliz Remote Control central, restrito à allowlist válida, com `hosts_list` e `host_health` | Que o inventário anunciado já tenha sido reconciliado |
| MCP próprio da Marina | Consultar `marina-direct-mcp/README.md` e `docs/ops/marina-independent-secure-mcp-tunnel.md` | Código read-only em staging não é instalação, túnel OpenAI ou app ChatGPT ativo |
| SSH/Tailscale/VCN privados | Apenas identidade aprovada, host key e autenticação testadas; usar canal dedicado ao host | Que o IP observado permita SSH e acesso administrativo |
| OCI, GitHub Actions, Bastion/console | Apenas bootstrap/recovery oficialmente habilitado | Contornar negativas de plataforma ou usá-los como transporte contínuo |
| Browser/UI de agentes, Seller Central | Perfis corporativos isolados no backend, quando sessão e bridge estiverem comprovadas | Reaproveitar cookies/perfis da VM excluída ou usar Windows como browser do agente |

**Origem definitivamente proibida como destino operacional:** `shopvivaliz-free-a1`, `10.0.1.112`, antigos aliases SSH/RDC e endpoints associados. Embora constem em código, inventários, workflows, sentinelas e relatórios, **não executar** neles healthcheck, deploy, runner recovery, workers, SSH ou qualquer job; não recriar a VM e não redirecionar seu alias para Marina. Preservar apenas histórico e backups disponíveis. A exclusão, informada pelo proprietário, não constitui prova de paridade de dados ou de aceitação dos demais gates.

**Pendência técnica concreta:** `remote-control-mcp/server.py` e `scripts/setup-remote-control-access.sh` ainda incluem a origem; `scripts/desktop-commander-four-host-sentinel.sh`, `scripts/oci-private-access-bootstrap.sh` e parte de `.github/workflows/` mantêm referências executáveis. Não rodar esses caminhos contra a origem. Correção de código/runtime e testes de regressão devem ser separados da atualização documental; substituir o nome da VM sem validar tenancy, IAM, SSH, leases e ownership é proibido.

**Snapshot de evidências deste atendimento:** RDC: Marina e laptop online; `hostname -s=marina-a1-4c24g`, `id -un=ubuntu`; Apache, MySQL, runner canônico e `shopvivaliz-cloudflared-admin.service` ativos. `shopvivaliz-marina-mcp.service` e túnel `shopvivaliz-marina-mcp-tunnel.service` inativos. `current` apontou para `releases/20261029-101846-79307562` no instante da consulta. Backend acessível via MCP e identidade confirmada; `hosts_list` ainda retornou a origem e não Marina. Controlador 24x7 ativo, porém com `degraded=true` e SHA de release anterior ao remoto. Isso não certifica migração, privilégios ou app MCP Marina.

**Aceite para agentes:** nenhuma rota válida pode apontar para a VM removida; o preflight obrigatório deve incluir esta página e revogar recibos desatualizados; usar Marina apenas por canal e identidade provados; considerar MCP Marina `NOT_CONNECTED` até teste real via ChatGPT; reconciliar código, CI e documentação com testes antes de declarar `APTO`.

## Hosts operacionais e descomissionamento

| Alvo | Conta OCI / funcao | Endereco documentado / observacao | Estado da evidencia |
|---|---|---|---|
| `marina-a1-4c24g` | **ESPOSA**; site publico, releases, Apache, MySQL, workers e runner de deploy | privado `10.80.1.116` (inventario OCI anterior); Tailscale `100.118.170.46` observado nesta auditoria | **ONLINE via RDC** em 2026-10-29; `hostname=marina-a1-4c24g`, `user=ubuntu` |
| `always-free-arm-1787907847-26` | **PRINCIPAL**; backend/controlador de continuidade, ShopVivaliz Remote Control MCP, navegador corporativo, MEI/M365 e relays | privado `10.0.1.38`; Tailscale `100.66.174.74` observado da Marina | **ONLINE via MCP central** em 2026-10-29; `hostname` e `uid=0` validos |
| `Fred-Win` / `LAPTOP-NIG4IFUU` | estacao Windows de suporte/autenticacao OCI; nao e producao | dispositivo RDC descoberto por nome a cada sessao; relay do backend a validar | ONLINE via RDC no inventario 2026-10-29 |
| `KOCEPSV` / `DESKTOP-KOCEPSV` | estacao Windows de apoio | Tailscale detectado, mas RDC marcado OFFLINE nesta auditoria | **Nao presumir acesso** |

**Host retirado:** `shopvivaliz-free-a1`, antigo web/origem (`10.0.1.112`, origin `137.131.149.55`). O proprietario informou em 2026-10-29 que a VM foi **excluida**; a verificacao real pelo MCP retornou `No route to host` em `10.0.1.112:22`. Essa verificacao de rede, isoladamente, nao comprova o lifecycle `TERMINATED` na OCI; somente inventario read-only atualizado da conta principal pode confirmar o estado OCI. **Nunca enviar comandos, deploys, probes, DNS ou jobs para esse alvo, nem recria-lo automaticamente.** `shopvivaliz-ai` e antigas VMs E2 sao apenas referencias historicas, nao rotas de producao.

## Rotas de acesso — por destino, nao por ferramenta favorita

1. **Backend PRINCIPAL:** usar o `ShopVivaliz Remote Control` e, se instalado, o Orquestrador com escopo e allowlist; confirmar `hosts_list` + `host_health` e identidade. Controller documentado em loopback `127.0.0.1:5580` na backend. Continuar respeitando leases/fencing, propriedade e recusas explicitas.
2. **Marina ESPOSA (RDC 1/2 direto):** o MCP central **nao apresenta Marina entre os hosts executaveis** (a allowlist nao inclui Marina) (snapshot de 2026-10-29). Para leitura e operacoes autorizadas hoje, `Remote Desktop Commander` e `Remote Desktop Commander 2` apresentaram `marina-a1-4c24g` online; descobrir o `deviceId` por `list_devices` em toda nova sessao e usar `start_process`/ferramentas no proprio dispositivo; validar `hostname -s`, `id -un`, privilegio efetivo e repositorio antes de cada mutacao. RDC direto **nao precisa da VM origem**, nem deve ser anunciado como MCP independente da Marina.
3. **SSH/Tailscale/VCN privado:** alternativa apenas com chave/identidade aprovada, host key verificada e conectividade efetivamente comprovada. O Tailscale observado `100.118.170.46` da Marina e `100.66.174.74` do backend e informativo, nao prova login SSH. Proibido SSH publico improvisado, `StrictHostKeyChecking=no`, acesso root publico ou senha exportada.
4. **GitHub Actions runner local da Marina:** `actions.runner.Vivaliz-site-site-shopvivaliz.marina-a1-deploy-canonical.service` estava ativo. Usar workflows allowlisted para publicacao/CI e verificar SHA/releases imutaveis; GitHub nao substitui transporte normal de shell, heartbeat ou fila de tarefas MCP.
5. **Cloudflare:** `shopvivaliz-cloudflared-admin.service` estava ativo na Marina, mas estado `active` **nao prova** que um MCP OpenAI esteja registrado, autenticado ou com permissoes administrativas. Validar separadamente acesso de rede, Zero Trust, identidade, dominio, escopo e ferramentas. Nao expor endpoint administrativo sem autenticacao.
6. **OCI control plane:** identidades das contas PRINCIPAL e ESPOSA separadas conforme `docs/ops/oci-dual-account-access.md`; a evidencia de autenticacao em Actions e de 2026-10-16, e nao comprova lifecycle atual de instancias nem permissao de executar privilegiado. Bastion, Instance Agent Run Command e serial console somente via fluxo oficialmente habilitado para recuperacao/bootstrap, sem contornar recusas.
7. **Browser/UI:** os perfis corporativos Dev/Atendimento e controladores de continuidade permanecem na backend enquanto comprovadamente operantes; nao migrar perfis/cookies entre maquinas. Para UI de storefront, visitar a URL publica e provar release e interacoes reais. Integracoes historicamente no browser da origem (ex.: Seller Central) exigem **nova validacao de rota** antes de tentar login; nao apontar novamente para VM excluida.

## Inventario de MCPs — nao confundir codigo com runtime

| Conector / componente | Situacao observada em 2026-10-29 | Limitacao |
|---|---|---|
| `ShopVivaliz Remote Control` / Orquestrador | MCP central acessivel no backend; `hosts_list` ainda retorna a origem excluida, backend e dois Windows | **Inventario desatualizado: Marina nao roteavel por esse MCP. Nao usar alias da origem para chegar a Marina.** |
| `Remote Desktop Commander` e `Remote Desktop Commander 2` | Marina e laptop online; outros dispositivos listados com status individual | Provider de acesso direto, nao equivale a novo MCP privado; quotas/escopos distintos devem ser verificados ao vivo |
| `marina-direct-mcp` | Codigo, teste e runbook no repo; `shopvivaliz-marina-mcp.service` e `shopvivaliz-marina-mcp-tunnel.service` **inativos** na Marina; `127.0.0.1:5580/health` nao respondeu | **NAO INSTALADO/NAO CONECTADO** como app independente; nao anunciar shell/sudo; implementacao proposta e read-only |
| Cloudflare admin da Marina | Servico systemd de `cloudflared` ativo | Nao comprova app OpenAI, publicacao MCP nem escopo de escrita |
| Browser Dev / Atendimento | Controller relata autenticacao/health parcial, com continuidade degradada | Validar perfil correto e checkpoint; nao concluir paridade apenas por sessao autenticada |

**Leituras obrigatorias:** `docs/AGENT-MCP-REMOTE.md`, `remote-control-mcp/SPEC.md`, `marina-direct-mcp/README.md`, `docs/ops/marina-independent-secure-mcp-tunnel.md`, `docs/knowledge/chatgpt-access-parity.md` e `docs/knowledge/browser-sessions.md`.

## Evidencia read-only e gate de aceite

- **Marina, 2026-10-29:** `hostname=marina-a1-4c24g`, `user=ubuntu`, Linux Oracle; Apache, MySQL, Tailscale, runner e Cloudflare admin `active`; release link `releases/20261029-095822-9374600d`; root volume ~193G (27G usados, 14%); tunnel-client/MCP privado inativos. O valor atual `systemctl show shopvivaliz-desktop-commander.service -p NoNewPrivileges` foi **no**, diferente de observacoes anteriores. Isto nao concede `sudo` nem revoga negativas anteriores: exigir privilegio, identidade e autorizacao efetiva em cada operacao.
- **Backend, 2026-10-29:** `host_health` retorna `hostname=always-free-arm-1787907847-26`, `uid=0`; controlador relata servico ativo, porem continuidade `degraded=true` por vinculos de conversas/sessoes incompletos.
- **Origem, 2026-10-29:** conexao SSH por MCP a `10.0.1.112` resulta `No route to host`. Excluida conforme afirmacao do proprietario; OCI lifecycle nao foi consultado nesta rodada.
- Para completar a atualizacao de **runtime** ainda e necessario reconciliar allowlist/hosts do MCP central e comprovar a futura instalacao/registro do MCP Marina. Documentacao atualizada **nao e** deploy de um MCP nem certificacao da migracao integral.
- Nenhum agente deve declarar `APTO` apenas por essa verificacao. Preservar testes RED/GREEN, paridade do SHA no deploy e E2E visual conforme `AUDIT_POLICY.md`.

Atualize esta pagina sempre que uma verificacao posterior alterar os fatos. Em divergencia, registre a evidencia nova e corrija os pontos de entrada, nao os logs historicos.
