# Cloudflare Zero Trust: tuneis administrativos independentes nas tres VMs Oracle

**Estado 2026-10-27:** consolidacao de fonte dos PRs #3262 e #3263, sem merge ou deploy; NO_CUT / NAO_APTO. Este documento nao concede permissoes e nao substitui aprovacao do operador de seguranca.

## Objetivo e topologia

- **Backend** `always-free-arm-1787907847-26` (10.0.1.38, tenancy PRINCIPAL): tunel dedicado instalado **nesta** VM, sem substituir o MCP/OpenAI Secure Tunnel nem o Docker `solange-demo-tunnel`.
- **Origem** `shopvivaliz-free-a1` (10.0.1.112, tenancy PRINCIPAL): tunel dedicado instalado nesta VM **somente quando** houver reinicializacao aprovada com workers/timers de escrita isolados e rollback validado.
- **Marina** `marina-a1-4c24g` (10.80.1.116, tenancy ESPOSA): tunel dedicado instalado **nesta** VM, sem transitar pelo backend/controller; autorizacao root real do host exigida para instalacao.

Nao reutilizar credencial do Cloudflare API super-admin como token de runtime. Cada `cloudflared` usa somente seu token de tunel exclusivo, mantido em secret manager e arquivo 0600 no host correto.

## Solucao recomendada

1. Pela **conta Cloudflare oficial autenticada**, criar tres tuneis remotamente gerenciados separados, como `vivaliz-backend-admin`, `vivaliz-origem-admin` e `vivaliz-marina-admin`. Cada um com rota distinta e identidade da VM.
2. **Preferencial:** Cloudflare **Access for Infrastructure**. Publicar a rota **privada /32** de cada VM por seu respectivo tunel; configurar um alvo Access por host/usuario Linux, Cloudflare One Client no dispositivo operador, identidade com MFA e certificados SSH efemeros, com auditoria de comandos. Evitar anunciar CIDRs amplos entre tenancies. Revalidar IP/VNIC antes da criacao.
3. **Alternativa:** aplicativo SSH com hostname proprio por VM e servico `ssh://127.0.0.1:22`; configurar e testar a politica **deny-by-default** de Cloudflare Access ANTES de adicionar a rota publicada, com MFA e identidades explicitamente permitidas. Ex.: `ssh-backend.shopvivaliz.com.br`, `ssh-origem.shopvivaliz.com.br`, `ssh-marina.shopvivaliz.com.br` (NOMES PROPOSTOS, DNS nao alterado).
4. **Contrato unico consolidado (#3262 + #3263, ainda em review):** somente o executavel aprovado `/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared`, servico `shopvivaliz-cloudflared-admin.service` e token runtime **local a cada VM** `/etc/shopvivaliz/cloudflare-admin/token` (root:root, 0600 ou 0400). Tokens diferentes por VM, nunca reutilizados. O unit template versionado em `ops/cloudflare-admin/shopvivaliz-cloudflared-admin.service` usa `LoadCredential=tunnel-token`, `--token-file %d/tunnel-token`, identidade dinamica sem root, `NoNewPrivileges=yes` e `ConditionPathExists` para o token e para `access-approved`. Este ultimo arquivo e apenas uma marca operacional controlada pelo administrador apos revisao efetiva de Access/MFA; nao comprova autenticacao por si so. A ferramenta `scripts/cloudflare-admin-stage-unit.sh` instala **somente** esse contrato no contexto root oficialmente autorizado e deixa o servico parado/desabilitado; rejeita a antiga unidade `shopvivaliz-cloudflare-admin.service`, unidades existentes e overrides. Nao substituir tuneis ativos de Returns, Pricing ou Solange.
5. **Sudo e uma permissao do Linux, nao do Cloudflare.** Configurar acesso administrativo via usuario SSH dedicado e sudoers allowlist revisada pelo mantenedor autenticamente autorizado. Para Marina, a sessao RDC do usuario `ubuntu` esta em `NoNewPrivs=1`, sem `sudo -n`, e houve recusa expressa para manutencao privilegiada. Nao usar OCI Run Command, GitHub Actions, outro MCP, SSH alternativo ou o tunel como bypass dessa recusa. O operador do host precisa conceder oficialmente a capacidade.
6. **ChatGPT MCP direto:** requer MCP proprio no host, autenticacao no endpoint, aprovacao de acoes, ownership, leases/fencing, CAS/idempotencia e E2E. Cloudflare Tunnel nao instala automaticamente um MCP nem concede root.

## Evidencia real - 26/10

- OCI CLI `DEFAULT` no Fred-Win autentica e inventaria: backend `RUNNING`, origem `STOPPED`. Nao ligar a origem sem gate de duplicidade.
- Fred-Win tem `cloudflared`, OCI CLI e arquivo `C:\\Users\\FRED\\.cloudflare\\api-token.txt`. Tentativa de usar o token pela ferramenta foi bloqueada; nao extrair/repetir por outro conector. A UI Cloudflare na backend exigiu autenticacao adicional. Na CLI Windows, `OCI_BOOTSTRAP` esta expirado e `ESPOSA` nao e perfil disponivel; GitHub Actions havia comprovado autenticacao dual antes, mas nao constitui sessao local atual.
- Backend tem acesso MCP como root e conta com Docker Cloudflare de Solange; nao foi criado tunel administrativo novo.
- Marina responde diretamente por RDC (ubuntu UID 1001), tem ssh.service ativo, mas nao tem servico systemd de tunel dedicado ou privilegio root operacional via RDC.

## Gates obrigatorios

1. Cloudflare: role oficial com permissoes de Tunel/Access, politica autenticada testada inclusive tentativa NEGADA; nao utilizar a rota de token bloqueada.
2. Host: identidade e privilegio oficial do operador validada, binario `cloudflared` de origem confiavel; arquivo token runtime distinto e restrito. Diagnostico nao deve ler o valor do segredo.
3. VM origem: isolamento oficial dos timers, workers e filas duplicadas ANTES de ligar; rollback registrado.
4. Marina: concessao oficial de manutencao privilegiada sem burlar NoNewPrivileges do canal RDC.
5. Conexao: tunel Connected no painel, SSH permitido e negado por identidade, certificado/MFA, revogacao, auditoria e um comando sudo **reversivel e autorizado** verificados de ponta a ponta.
6. Restart e reboot da VM testados e independentemente recuperaveis; nao concluir APTO so por HTTP 200 ou `systemctl active`.
7. Nao alterar cutover, DNS comercial, Pricing, Returns, Supabase nem desativar VMs neste fluxo.

## Fontes oficiais

- https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/use-cases/ssh/ssh-infrastructure-access/
- https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/use-cases/ssh/ssh-cloudflared-authentication/
- https://developers.cloudflare.com/tunnel/reference/run-parameters/
- https://developers.cloudflare.com/tunnel/reference/tunnel-tokens/

## Canonico ShopVivaliz

Consultar `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md`, `docs/ops/oci-dual-account-access.md`, `docs/ops/marina-independent-secure-mcp-tunnel.md`. Nenhum operador/autorizacao/lease e criado implicitamente pelo presente documento.

## Evidencia de consolidacao de fonte (27/10/2026)

A branch do PR #3262 incorpora preservados os cinco arquivos exclusivos do PR #3263 (runbook, template do unit, shell preflight e dois testes). O stager Python, testes existentes e script root-only de #3262 continuam presentes, adaptados ao mesmo executavel, token e unit. CI deve validar **ambas** as suites antes de considerar #3263 substituido. A presenca do codigo numa branch NAO autoriza fechar/mergear automaticamente: exigir revisao independente, protecoes de branch e comprovacao dos testes no SHA final. Nenhum token ou arquivo `access-approved` foi gerado e nenhum servico ativado por esta consolidacao.
