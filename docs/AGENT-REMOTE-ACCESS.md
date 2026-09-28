# Acesso remoto canônico dos agentes

Este arquivo é leitura obrigatória antes de qualquer tarefa que envolva VM, runtime, navegador, serviço, deploy, logs ou recuperação operacional.

## Hosts canônicos

- `shopvivaliz-free-a1` — produção web — VCN `10.0.1.112`.
- `always-free-arm-1787907847-26` — backend/automação/browser — VCN `10.0.1.38`; Tailscale conhecido `100.66.174.74`.
- `Fred-Win` e `KOCEPSV` são hosts de apoio. Navegador de tarefas ShopVivaliz não deve rodar neles.

## Ordem de acesso

1. **Remote Control MCP:** rota operacional primária para hosts, serviços, diagnóstico, arquivos e tarefas duráveis quando a capacidade necessária estiver allowlisted. O controller canônico fica em `always-free-arm-1787907847-26` e o endpoint de controle permanece privado/loopback.
2. **SSH privado/Tailscale:** usar o usuário dedicado `shopvivaliz-agent` somente quando a operação não estiver disponível pelo Remote Control MCP ou quando o control plane estiver comprovadamente indisponível. SSH público direto, login root público e autenticação por senha continuam proibidos.
3. **GitHub Actions/OCI Bastion:** fallback auditável para bootstrap, recuperação e reparo do próprio Remote Control MCP quando não houver rota privada utilizável.
4. **GUI/validação visual:** usar RustDesk self-hosted. Navegador, Playwright/Selenium/CDP, MFA, CAPTCHA, consentimento ou validação visual continuam na backend `always-free-arm-1787907847-26`, nunca nos hosts Windows.

## Navegador

Qualquer navegador, Playwright/Selenium/CDP, MFA, CAPTCHA, consentimento ou validação visual deve usar a VM backend `always-free-arm-1787907847-26`. Não abrir browser em Fred-Win ou KOCEPSV para tarefas ShopVivaliz.

## Segurança e validação

- Nunca imprimir, versionar ou pedir ao usuário chave privada, senha, token ou secret.
- Material de chave do usuário `shopvivaliz-agent` é provisionado pelos fluxos autorizados; não copiar a chave para outro host.
- Antes de agir, validar `hostname`, `whoami`, diretório do repo e estado Git.
- Produção é imutável: no site, não editar a release ativa/`current` diretamente.
- Se o ambiente atual não tiver rota privada, declarar isso e usar o fallback autorizado em vez de concluir que a VM está offline.

## Fonte canônica

A implementação e documentação operacional de referência ficam em:
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md`
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/agent-rules.md`
- `Vivaliz-site/site-shopvivaliz/docs/REMOTE-ACCESS-GITHUB.md`

Quando houver divergência, a versão mais recente desses arquivos no `main` de `site-shopvivaliz` prevalece.
