> **Desktop Commander (RDC) retirado do repositorio — ordem do proprietario, 2026-11-09.** Workflows, scripts,
> units systemd, ops e testes do Desktop Commander foram removidos. **As rotas que o usavam passam a ser o MCP:**
> `shopvivaliz-remote-control` (MCP central) para backend, Fred-Win e KOCEPSV, e `shopvivaliz-marina-admin`
> (`marina_admin_shell`, inclusive `as_root`) para a Marina. Fallbacks continuam sendo os workflows de Actions
> (`shopvivaliz-remote-access.yml`, `marina-readonly-status.yml`) e SSH privado/Tailscale. Onde este e outros documentos ainda
> citarem "RDC"/"Desktop Commander" como rota, leia **MCP**; a citacao e historica e sera removida em revisao propria dos
> textos de regras de agentes (que exige a aprovacao prevista para essa categoria). Unidades e tarefas do Desktop Commander
> que ainda existam nos hosts devem ser desativadas com `scripts/retire-vm-legacy-runtime.sh`; o monitor de abandono as sinaliza.

# Acesso remoto canônico dos agentes

Este arquivo é leitura obrigatória antes de qualquer tarefa que envolva VM, runtime, navegador, serviço, deploy, logs ou recuperação operacional.

## Hosts canônicos

- `marina-a1-4c24g` — **produção web/deploy**, banco, workers e runner (OCI ESPOSA); rota MCP central de escrita administrativa autorizada e validada em 11/11/2026 com `hostname -s; id -un` (exit 0).
- `always-free-arm-1787907847-26` — backend/automacao/browser/controller MCP (OCI PRINCIPAL), VCN `10.0.1.38`, Tailscale observado `100.66.174.74`.
- `shopvivaliz-free-a1` — **origem excluida** segundo proprietario; SSH sem rota em 2026-10-29. Nao enviar comandos/probes/deploys. OCI `TERMINATED` nao foi validado nesta rodada.
- `Fred-Win` e `KOCEPSV` são hosts de apoio. Navegador de tarefas ShopVivaliz não deve rodar neles.

## Ordem de acesso

1. **Remote Control MCP:** rota operacional primária para backend, Marina e os hosts Windows publicados. Em 11/11/2026, `admin_command_run` comprovou backend, Marina e Fred-Win, mas a conexão do KOCEPSV falhou no relay `127.0.0.1:2223`; não inferir cobertura de um host somente pelo catálogo. O controller continua no backend e seu estado de continuidade precisa de certificação própria.
2. **SSH privado/Tailscale:** usar o usuário dedicado `shopvivaliz-agent` somente quando a operação não estiver disponível pelo Remote Control MCP ou quando o control plane estiver comprovadamente indisponível. SSH público direto, login root público e autenticação por senha continuam proibidos.
3. **GitHub Actions/OCI Bastion:** fallback auditável para bootstrap, recuperação e reparo do próprio Remote Control MCP quando não houver rota privada utilizável.
4. **GUI/validação visual:** executar pelas ferramentas MCP de desktop ou navegador realmente autorizadas para cada host, perfil e lease; validar a navegação real, efeitos e persistência. O navegador corporativo canônico e o controle de sessão ficam no backend, sem migração de cookies para Windows.

## Navegador

Qualquer navegador, Playwright/Selenium/CDP, MFA, CAPTCHA, consentimento ou validação visual deve usar a VM backend `always-free-arm-1787907847-26`. Não abrir browser em Fred-Win ou KOCEPSV para tarefas ShopVivaliz.

## Segurança e validação

- Nunca imprimir, versionar ou pedir ao usuário chave privada, senha, token ou secret.
- Material de chave do usuário `shopvivaliz-agent` é provisionado pelos fluxos autorizados; não copiar a chave para outro host.
- Antes de agir, validar `hostname`, `whoami`, diretório do repo e estado Git.
- Produção é imutável: no site, não editar a release ativa/`current` diretamente.
- Se o ambiente atual não tiver rota privada, declarar isso e usar o fallback autorizado em vez de concluir que a VM está offline.

## Fonte canonica

- Inventario dos MCPs, estado efetivo, rotas e evidencias: `docs/knowledge/current-topology-and-access-2026-10-29.md`.

A implementação e documentação operacional de referência ficam em:
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md`
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/agent-rules.md`
- `Vivaliz-site/site-shopvivaliz/docs/REMOTE-ACCESS-GITHUB.md`

Quando houver divergência, a versão mais recente desses arquivos no `main` de `site-shopvivaliz` prevalece.
