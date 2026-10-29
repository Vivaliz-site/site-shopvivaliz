# Acesso remoto canônico dos agentes

Este arquivo é leitura obrigatória antes de qualquer tarefa que envolva VM, runtime, navegador, serviço, deploy, logs ou recuperação operacional.

## Hosts canônicos

- `marina-a1-4c24g` — **producao web/deploy**, banco/workers/runner (OCI ESPOSA), privado documentado `10.80.1.116`, Tailscale observado `100.118.170.46`; RDC 1/2 direto online em 2026-10-29.
- `always-free-arm-1787907847-26` — backend/automacao/browser/controller MCP (OCI PRINCIPAL), VCN `10.0.1.38`, Tailscale observado `100.66.174.74`.
- `shopvivaliz-free-a1` — **origem excluida** segundo proprietario; SSH sem rota em 2026-10-29. Nao enviar comandos/probes/deploys. OCI `TERMINATED` nao foi validado nesta rodada.
- `Fred-Win` e `KOCEPSV` são hosts de apoio. Navegador de tarefas ShopVivaliz não deve rodar neles.

## Ordem de acesso

1. **Remote Control MCP:** rota operacional primaria **do backend/hosts allowlisted ativos**. Seu inventario em 2026-10-29 ainda exibe origem excluida, mas **nao** Marina; nunca usar alias da origem para Marina. Para Marina, usar **RDC 1/2 direto** (online, ID descoberto pelo nome) ou SSH privado apenas apos autenticacao validada. MCP independente Marina permanece inativo. O controller canônico fica em `always-free-arm-1787907847-26` e o endpoint de controle permanece privado/loopback.
2. **SSH privado/Tailscale:** usar o usuário dedicado `shopvivaliz-agent` somente quando a operação não estiver disponível pelo Remote Control MCP ou quando o control plane estiver comprovadamente indisponível. SSH público direto, login root público e autenticação por senha continuam proibidos.
3. **GitHub Actions/OCI Bastion:** fallback auditável para bootstrap, recuperação e reparo do próprio Remote Control MCP quando não houver rota privada utilizável.
4. **GUI/validação visual:** usar o canal grafico autorizado por host (RDC direto para Marina quando apropriado, RustDesk self-hosted no fluxo backend). Navegador, Playwright/Selenium/CDP, MFA, CAPTCHA, consentimento ou validação visual continuam na backend `always-free-arm-1787907847-26`, nunca nos hosts Windows.

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
