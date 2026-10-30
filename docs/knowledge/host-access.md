
<!-- EXHAUST_ALL_AUTHORIZED_ROUTES_GATE_20261030 -->
## Regra obrigatoria: continuidade ate esgotamento comprovado de rotas autorizadas (2026-10-30)

Quando o usuario disser "retome", "continue", "nao pare" ou solicitar conclusao integral, **nao encerrar por falha de uma unica ferramenta, autenticacao, pagina ou transporte**. Antes de classificar qualquer tarefa como bloqueada, o agente deve verificar, para o host e operacao especificos, cada rota REALMENTE instalada, acessivel e autorizada: (1) conectores oficiais do servico e MCP ShopVivaliz/controlador; (2) RDC/Remote Desktop Commander 1 e 2, com identificacao atual do dispositivo e privilegios efetivos; (3) SSH privado/Tailscale e tuneis Cloudflare/MCP independentes comprovados; (4) navegador/sessao grafica corporativa ja autenticada e respectivo controle de interface autorizado; (5) OCI Bastion, runner e GitHub Actions apenas nas funcoes de bootstrap e recuperacao oficialmente autorizadas. Nao pressupor que um tunel concede permissao administrativa, que uma sessao grafica permite cliques por MCP ou que uma credencial valida para leitura autoriza escrita.

**Para cada rota:** obter evidencia do estado real, identidade, autorizacao, lease/fencing, resultado da acao e motivo de indisponibilidade; distinguir NOT_PROVISIONED, DENIED_BY_PROVIDER, AUTH_EXPIRED, RUNTIME_LOCKED, NOT_REACHABLE e VERIFIED_OK. Reutilizar credenciais e sessoes protegidas ja provisionadas sem expor segredos nem pedir credenciais repetidas. Se uma rota falhar por motivo tecnico reparavel, corrigir, testar novamente e prosseguir para as demais rotas permitidas. Registrar checkpoint duravel e proxima acao acionavel; manter o incidente aberto e a continuidade pelo controlador existente, sem duplicar workers ou automacoes. Nao afirmar APTO/CONCLUIDO antes de validacao de escrita, persistencia, regressao e UI E2E quando houver interface.

**Limite inegociavel:** a exaustao de caminhos nao autoriza contornar um 401/403 ou recusa explicita do provedor, RBAC, CAPTCHA/MFA, protecoes de plataforma, runtime_lock_required, approvacao obrigatoria ou delimitacao do escopo comercial. Outra rota so pode ser utilizada para finalidade INDEPENDENTEMENTE autorizada; nunca como bypass da mesma acao negada. Preservar dados, precos, estoques, publicacoes organicas e terceiros. Se depender de permissao externa, identificar o provedor, capacidade faltante, evidencias, fluxo oficial de liberacao e status NAO_CONCLUIDO/BLOCKED_EXTERNAL, continuando por tarefas seguras executaveis e sem prometer execucao em segundo plano inexistente.

**Exemplo Mercado Ads #3416:** advertiser 20098, token com leitura de Product Ads mas escrita explicitamente recusada pelo Mercado Livre HTTP 401. A UI autenticada no laptop nao equivale a ferramenta de clique autorizada, e o agrupamento por variantes impede pausar toda campanha/grupo misto quando anuncios ROAS >=12x devem permanecer ativos. Conferir oficialmente permissao de escrita e operacao individual sem alterar publicacoes organicas. Evidencia: https://github.com/Vivaliz-site/site-shopvivaliz/issues/3416 .
<!-- /EXHAUST_ALL_AUTHORIZED_ROUTES_GATE_20261030 -->
<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Runtime comprovado em 2026-10-29 (prevalece sobre snapshots anteriores):** MCP central sem `shopvivaliz-free-a1`; `hosts_list` = backend + Fred-Win + KOCEPSV, `host_health` confirmado. Marina nao esta no MCP central e seu MCP OpenAI independente segue inativo. O tunel privado de aplicacao MEI-MG para Marina esta ativo com health HTTP 200, nao equivale ao MCP OpenAI. PR #3395 bloqueou 20 jobs antigos. Controller 24x7 ainda `NAO_APTO` por checkpoints/lease, runner MEI `mei-ci-a1` ainda offline. Rotas atuais e evidencias: `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->
## Gate obrigatorio: esgotar rotas de acesso remoto antes de bloquear

Em `Retome`, `Continue`, `Prossiga`, `Siga` ou qualquer trabalho remoto, **um erro em um unico canal jamais comprova inacessibilidade do host**. Antes de `BLOCKED_EXTERNAL` ou de solicitar ao usuario uma acao que o agente pode executar:
1. Ler `docs/knowledge/host-access.md` no `main` atual e o runbook do repositorio; verificar destino, identidade, privilegios, capacidades expostas e autorizacoes efetivas. Nunca adivinhar que uma rota existe.
2. **Avaliar todas as rotas remotas realmente provisionadas, permitidas e adequadas a acao**, com a prioridade do runbook: (a) ShopVivaliz Remote Control MCP/controlador e ferramentas de host/tarefa/desktop; (b) RDC e RDC 2 para os hosts e capacidades explicitamente autorizados; (c) SSH privado/VCN/Tailscale e tunel/reverse SSH documentado, usando credenciais protegidas existentes; (d) GUI remota por bridge `desktop_*` ou RustDesk self-hosted quando a tarefa exigir interface; (e) OCI Bastion, OCI Compute Run Command, console serial e GitHub Actions/control plane somente para bootstrap, recuperacao ou reparo, conforme suas regras. Usar outras rotas somente se estiverem documentadas e autorizadas.
3. Para cada rota aplicavel, provar disponibilidade, autenticacao/autorizacao, conectividade, identidade do host e capacidade da operacao; diagnosticar e reparar falhas tecnicas que estejam ao alcance, testar novamente com tentativas limitadas e idempotentes e passar ao proximo caminho quando apropriado. Nao confundir canal de GUI com shell nem rotas de recovery com transporte normal de runtime.
4. Registrar checkpoint nao secreto `remote_access_attempts` por host e tarefa: canal, objetivo, permissao, teste, resultado, causa, reparo, proximo fallback e evidencia com instante. Canal nao disponivel deve ser classificado `NOT_PROVISIONED` com evidencia, nao `TRIED`.
5. **So declarar host inacessivel/bloqueio externo depois de examinar e esgotar todos os caminhos adequados, seguros, tecnicamente viaveis e autorizados**, justificando cada nao utilizacao. Enquanto houver rota executavel, manter `RUNNING` e continuar a tarefa. Pedir aprovacao apenas quando obrigatoria, indicando a permissao exata.

Limites: nao contornar MFA, CAPTCHA, RBAC, protecoes de branch, limites administrativos ou aprovacoes; nao procurar/extrair secrets nem tentar senhas indiscriminadamente; nao habilitar SSH publico ou usar IP publico como fallback indevido. Browser de agente ShopVivaliz permanece na VM backend com perfil isolado; Windows e apenas host de tarefas/GUI autorizadas, nao fallback de navegador. Sem loops ilimitados nem alegacao de acesso nao testado.
<!-- /REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->

# Acesso a hosts — bootstrap obrigatório para agentes

Este documento é a referência canônica de **como localizar e acessar os hosts ShopVivaliz** ao iniciar uma conversa, sessão de IDE ou agente novo.

## Regra zero

Antes de diagnosticar, alterar ou validar qualquer ambiente, o agente deve:

1. ler `docs/knowledge/README.md` e `docs/knowledge/agent-rules.md`;
2. identificar o host correto pelo papel atual;
3. confirmar o acesso com evidência (`hostname`, `whoami`, diretório e, quando aplicável, `git status`);
4. nunca assumir que um IP antigo continua sendo produção;
5. usar o **Remote Control MCP** como rota operacional primaria; recorrer a SSH privado/Tailscale apenas quando a capacidade necessaria nao existir no MCP ou ele estiver comprovadamente indisponivel; RustDesk fica para GUI.

## Hosts operacionais atuais — verificados em 2026-10-29

**Inventario completo, rota por host e matriz dos MCPs:** [current-topology-and-access-2026-10-29.md](current-topology-and-access-2026-10-29.md). Antes de qualquer operacao, confirmar disponibilidade e identidade no runtime; nomes/IPs nao sao prova de acesso.

| Host | Endereco documentado | Papel |
|---|---|---|
| `marina-a1-4c24g` (OCI ESPOSA) | privado `10.80.1.116` (doc OCI); Tailscale observado `100.118.170.46` | **producao web/deploy**, Apache, MySQL, workers, runner e Cloudflare admin |
| `always-free-arm-1787907847-26` (OCI PRINCIPAL) | privado `10.0.1.38`; Tailscale observado `100.66.174.74` | backend/controller do Remote Control MCP, browser corporativo, MEI/M365 e relay Windows |
| `Fred-Win` / `LAPTOP-NIG4IFUU` | RDC direto, dispositivo descoberto por nome | suporte Windows, OCI de contingencia |
| `KOCEPSV` / `DESKTOP-KOCEPSV` | rota individual a validar | apoio Windows; RDC offline no ultimo inventario |

**Origem retirada:** `shopvivaliz-free-a1` (`10.0.1.112`, origin `137.131.149.55`) foi **excluida segundo o proprietario** em 2026-10-29 e nao tem rota SSH (`No route to host`). A ausencia de rota nao prova `TERMINATED` na OCI sem consulta de lifecycle. **Nunca usar origem como alvo de comandos, workflow, health ou deploy; nao recriar automaticamente.** `shopvivaliz-ai` e outras E2 sao legadas e nao operacionais.

<!-- MARINA_DIRECT_RDC_V1 -->
## Marina: acesso direto pelo RDC, sem depender de outra VM (revalidado em 2026-10-29)

- **Identidade OCI:** a conta `ESPOSA` autenticou na API Oracle Cloud por chave de API já protegida no laptop Fred-Win; `marina-a1-4c24g` aparece `RUNNING`, VNIC privada `10.80.1.116`. Isto comprova identidade cloud, não privilegio root no Linux.
- **Transporte DIRETO JA OPERANTE (Remote Desktop Commander):** o bridge de desktop vinculado ao ChatGPT lista `marina-a1-4c24g` **online**, e `start_process` executou `hostname -s`, `id -un` e `systemctl show` diretamente na Marina como `ubuntu`, **sem passar pela controladora** `always-free-arm-1787907847-26`. Para tarefas na Marina, buscar no `list_devices` o ID atual desse dispositivo e usar suas ferramentas de host/terminal (sem adivinhar ID estavel). O status deve ser confirmado em cada sessao; RDC 2 depende de vinculo/quota propria e nao equivale a RDC 1.
- **Historico de limite de privilegio:** em 2026-10-19 a unidade `shopvivaliz-desktop-commander.service` aplicava `NoNewPrivileges=yes`; em **2026-10-29** o `systemctl show` direto na Marina retornou **`NoNewPrivileges=no`**. Nenhum desses valores concede root/sudo, e a recusa expressa anterior nao pode ser contornada. **Nao contornar** a negativa por GitHub Actions, OCI Run Command, MCP da controladora, outra conta, SSH ou tunel alternativo. O operador autorizado deve obter a aprovacao/capacidade oficial, independentemente do dispositivo RDC.
- **ShopVivaliz Remote Control (outro MCP):** em 2026-10-29 o catalogo operacional central ainda expunha quatro hosts, incluindo a origem excluida, mas **nao incluia Marina**; seu transporte SSH para `10.80.1.116:22` a partir da controladora nao respondeu no ultimo teste, e a host key nao estava inscrita no inventario seguro. Um servidor **ShopVivaliz Remote Control dedicado na Marina** continua **nao provisionado** (sem `shopvivaliz-remote-control-mcp.service`, sem `cloudflared`/tunel seguro proprio). O PR #3149 prepara apenas inventario nao executavel; nunca confundir inventario com conexao MCP direta.
- **Configuracao futura do MCP direto:** requer daemon proprio na Marina, credenciais separadas e protegidas, conexao autenticada propria por HTTPS/tunel seguro independente, comprovacao de ownership, auditoria, leases/fencing, testes de conexao e autorizacao efetiva antes de habilitar comandos administrativos. Nao reaproveitar tokens/SSH do MCP central e nao expor uma porta MCP administrativa sem autenticao e controle de rede.
- **Uso imediato:** o caminho direto que ja funciona e o bridge de desktop da Marina para operacoes na capacidade efetivamente aprovada. Se uma acao exigir root nao disponivel, registrar o impedimento para manutencao oficial sem substituir a rota autorizada por uma indireta.

### Gate de encaminhamento administrativo da Marina — 2026-10-27

**Autorização do proprietário já expressa:** o proprietário solicitou repetidamente conclusão da migração, acesso administrativo com `sudo` e túneis independentes por VM. **Nunca atribuir a ele a exigência `NoNewPrivileges=yes` nem solicitar novamente autorização genérica.** O valor `NoNewPrivileges=true` foi introduzido no template técnico `ops/systemd/shopvivaliz-desktop-commander.service` (plano de 2026-08-23) para isolar o **agente RDC**; não é uma proibição de `sudo` desejada pelo proprietário, nem prova de indisponibilidade de `sudo` no login SSH do próprio Linux.

**Interromper a repetição do erro:** ao receber uma tarefa que exige root em Marina, verificar primeiro **sem elevação** a identidade efetiva, o tipo de sessão e o `NoNewPrivs` do processo. Se `NoNewPrivs: 1` no agente `shopvivaliz-desktop-commander.service`, registrar `PRIVILEGED_ACTION_ROUTE_UNAVAILABLE=RDC_NO_NEW_PRIVS` e **não repetir `sudo` nesse agente**, pois a mesma falha será determinística. `blockedCommands=[]`, grupo Linux `sudo` e token OCI não anulam a restrição do processo. A existência de `NoNewPrivs: 1` no agente não determina o resultado de uma sessão SSH administrativa independente.

**Encaminhamento válido:** distinguir a autorização operacional reiterada do proprietário de (a) credenciais/autenticação de cada provedor, (b) capacidade privilegiada do host, e (c) concessão/lease efetivo da plataforma para a ação concreta. Utilizar o **mecanismo oficial de manutenção administrativa separado do RDC**, quando autenticado, provisionado, aprovado e testado pelo operador do host. A recusa explícita de manutenção privilegiada permanece vinculante: jamais executar a **mesma ação recusada** por OCI Run Command, GitHub Actions, SSH alternativo, túnel, outro MCP, desativação de `NoNewPrivileges`, alteração de `sudoers` ou mudança de identidade como atalho. Não converter um túnel Cloudflare em shell root genérico. O serviço de transporte `cloudflared` pode continuar não privilegiado; o acesso `sudo` pertence à conta SSH administradora verificada e às regras UNIX aprovadas.

**Condição exata de retomada:** o operador/plataforma responsável deve disponibilizar, pelo fluxo oficialmente aceito, uma sessão de manutenção com capacidade privilegiada efetiva e escopo da instalação do conector/admin da Marina. Atestar `hostname`, identidade, `sudo -l` e uma ação administrativa inofensiva permitida **somente nessa sessão oficialmente habilitada**; preservar autenticação, audit trail, leases/fencing, rollback e negativas de acesso. Sem isso, declarar `NAO_APTO` e `BLOCKED_EXTERNAL: HOST_PRIVILEGED_CAPABILITY_NOT_GRANTED` com evidência, sem repetir tentativas da sessão RDC e sem transferir essa etapa ao usuário como se faltasse sua autorização.

<!-- /MARINA_DIRECT_RDC_V1 -->

A arquitetura atual deve ser confirmada no código e nos hosts antes de qualquer intervenção. Se houver divergência entre este arquivo e evidência ao vivo, pare a hipótese e atualize a documentação com a evidência encontrada.

## Produção web/deploy

Diretório operacional:

```text
/home/ubuntu/shopvivaliz-deploy/
```

Estrutura esperada:

```text
repo/       clone de deploy
releases/   releases imutáveis
current -> releases/<release-ativa>
shared/     estado/segredos/runtime persistentes
```

Nunca editar diretamente `current/` nem `releases/<ativa>/`.

## SSH

SSH publico direto continua desabilitado. O **Remote Control MCP** e a rota operacional primaria. Quando for necessario shell direto fora das capacidades do MCP, agentes usam `shopvivaliz-agent` por rede privada/Tailscale. GitHub Actions administrativos usam runners privados autorizados; OCI Bastion/GitHub Remote Access ficam restritos a bootstrap/recovery. RustDesk self-hosted e o canal grafico principal.

Usuário administrativo legado das VMs Oracle:

```text
ubuntu
```

Usuário operacional de agentes:

```text
shopvivaliz-agent
```

Esse usuario aceita somente chave publica provisionada, restringida a redes privadas/Tailscale, sem senha interativa e com `sudo` limitado a comandos allowlisted.

A chave privada deve vir de armazenamento local seguro ou secret de automação. **Não versionar chave privada, senha, token ou conteúdo de secret.**

Referências aceitas de credencial:

```text
Windows local: C:\Users\FRED\Downloads\ssh-key-2026-07-04.key
GitHub Actions: ORACLE_VM_SSH_KEY
Ambiente de agente/projeto: SHOPVIVALIZ_VM_SSH_KEY (quando fornecido pelo runtime)
```

Exemplos:

```bash
ssh -i ~/.ssh/id_rsa ubuntu@127.0.0.1  # somente no runner self-hosted do site
ssh -i ~/.ssh/id_rsa ubuntu@10.0.1.38  # somente dentro da VCN
## Alvo historico aposentado: nao conectar ao antigo DEV/E2
```

### iPhone / cliente SSH humano

O acesso humano pelas conexoes SSH do iPhone deve permanecer privado. **O perfil/relay da antiga VM web foi aposentado e nao deve ser utilizado nem como fallback**; para Marina, confirmar a identidade na rede Tailscale e a autenticacao SSH antes de criar perfil novo:

| Perfil | Host | Porta | Usuario | Destino real |
|---|---|---:|---|---|
| Backend A1 | `100.66.174.74` | `22` | `ubuntu` | backend A1 pela rede Tailscale |
| Marina (nao provisionado como perfil iPhone) | `100.118.170.46` (observado, dinamico) | `22` (a validar) | `ubuntu` (a validar) | acesso SSH privado somente se autenticacao/host key forem comprovadas; nao herdar chave/relay da origem |

O antigo relay do site era provisionado por `scripts/setup-iphone-private-ssh-relay.sh`; e historico e nao deve ser iniciado/reutilizado para a origem excluida. Nao usar os IPs publicos das VMs como fallback no iPhone. A chave privada continua armazenada apenas no cliente autorizado e nunca deve ser copiada para Git, logs ou chat.


## Prioridade operacional

1. **Remote Control MCP** — primeira escolha para controle, observacao e tarefas duraveis.
2. **SSH privado/Tailscale** — somente para shell direto que o MCP nao exponha ou indisponibilidade comprovada do control plane.
3. **GitHub Actions/OCI Bastion** — bootstrap, recovery e reparo.
4. **RustDesk** — GUI/validacao visual; navegador de agente permanece na backend.

Para GUI de `Fred-Win`/`KOCEPSV`, prefira as ferramentas `desktop_*` do Remote Control quando disponíveis. No `Fred-Win`, a rota normal é o bridge interativo nativo por reverse SSH (`scripts/shopvivaliz-native-desktop-bridge.ps1`), que executa screenshot/mouse/teclado na sessão Windows logada sem senha RustDesk e sem depender de registro cloud de ferramenta legada de desktop remoto. No `KOCEPSV`, `desktop_*` continua operando o cliente RustDesk na sessão `fredconsole` da backend. O mapeamento de IDs RustDesk fica somente em `/var/lib/shopvivaliz-remote-control/desktop.env` (`root:root`, `0600`); nunca versionar nem imprimir esse conteúdo. Nenhuma dessas rotas transforma o Windows em host de navegador de agente.

Antes de operar qualquer host, valide `hostname`, identidade e contexto do repositorio sem expor credenciais.

## Repositório principal

Fonte de verdade versionada:

```text
https://github.com/Vivaliz-site/site-shopvivaliz
branch principal: main
```

Em uma cópia Git válida, confirmar:

```bash
git remote -v
git branch --show-current
git status --porcelain
```

O caminho `/home/ubuntu/shopvivaliz-deploy/repo` pode ser um worktree/clone de deploy. Se o metadata Git estiver quebrado ou apontar para worktree removido, **não force correção destrutiva**: use clone limpo temporário ou GitHub API e preserve o deploy ativo.

## Validação de health

Para Squad Chat, health só é válido se a resposta comprovar simultaneamente:

```text
ok=true
endpoint=squad-chat
providers presente
```

`configured=true` sozinho não prova autenticação do provider.

## Onde esta informação deve aparecer

- `docs/knowledge/host-access.md` — fonte canônica;
- `docs/knowledge/README.md` — índice;
- `AGENTS.md` — regra de bootstrap para qualquer agente;
- `CLAUDE.md` — bootstrap para Claude;
- `README.md` — ponte para a Knowledge Base;
- instruções de projeto/assistente — devem apontar para este documento e para `docs/knowledge/`;
- memória do produto, quando habilitada — guardar apenas o mapa não secreto de hosts e a regra de consultar a Knowledge Base; nunca guardar chave privada ou token.

## Segurança

Este arquivo é público no repositório. Por isso contém somente endereços, papéis e **nomes de secrets/paths**, não o conteúdo de credenciais. Segredos devem permanecer em Secret Manager, GitHub Secrets, arquivo local protegido ou runtime autorizado.

<!-- REMOTE_CONTROL_MCP_HOST_ROUTES_V2 -->
## Remote Control MCP — rotas atuais e estado comprovado

**Advertencia de 2026-10-29:** `hosts_list` ainda divulga `shopvivaliz-free-a1` excluida e nao divulga `marina-a1-4c24g`. Tratar essa allowlist como **DESATUALIZADA**, sem tentar chamadas ao host retirado e sem fazer alias para a Marina. Na Marina, RDC 1/2 direto sao as rotas **comprovadas** para operacoes permitidas; MCP direto e tunnel-client OpenAI da Marina seguem **INATIVOS/NAO CONECTADOS**. `shopvivaliz-cloudflared-admin.service` estava ativo, mas isso nao comprova MCP. Consultar [inventario atualizado](current-topology-and-access-2026-10-29.md) e [runbook do MCP independente](../ops/marina-independent-secure-mcp-tunnel.md). O controlador de backend continuava acessivel, com continuidade degradada.

Esta e a rota operacional prioritaria para agentes **nos hosts atualmente incluidos e validos**. O uso de rotas alternativas exige necessidade tecnica concreta ou indisponibilidade comprovada do MCP.

Controller canônico:

- host: `always-free-arm-1787907847-26` (`10.0.1.38`)
- MCP: `127.0.0.1:5580`, somente loopback
- GitHub pode ser usado para source/bootstrap/recovery, mas **não** pode ser o transporte normal de comandos, queue, heartbeat ou estado do runtime.

Rotas Windows pelo backend:

| Host | Rota runtime | Relay legado | Estado comprovado em 2026-09-28 |
|---|---|---|---|
| Fred-Win / `LAPTOP-NIG4IFUU` | `127.0.0.1:2222 -> Windows:22` | `5557` | `2222` PASS recente |
| KOCEPSV / `DESKTOP-KOCEPSV` | `127.0.0.1:2223 -> Windows:22` | `5558` | `2223` **ainda não comprovado** |

Os relays `5557/5558` são apenas bootstrap/recovery. Nunca tratá-los como transporte normal do Remote Control MCP.

Até existir evidência fresca de `2223` + identidade administrativa correta no KOCEPSV, o host deve ser considerado **indisponível para o E2E final do control plane**. Não inferir Administrator apenas porque a porta SSH abriu.

Ao validar os hosts **ainda existentes e autorizados**, exigir evidência real de:

1. rota de rede/SSH disponível;
2. `hostname` correto;
3. identidade correta (`id`/root nos Linux, Administrator/elevado nos Windows);
4. execução de tarefa durável via controller;
5. recuperação de status/resultado sem usar GitHub como transporte de runtime.
<!-- /REMOTE_CONTROL_MCP_HOST_ROUTES_V2 -->


<!-- CHATGPT_ATENDIMENTO_CREDENTIAL_REF_V1 -->
## ChatGPT corporativo — referência de credencial

A conta `atendimento@shopvivaliz.com.br` possui senha própria já configurada. O segredo não é versionado neste repositório. Para autenticação, reutilizar primeiro o perfil/navegador autenticado da VM `always-free-arm-1787907847-26` e as fontes seguras autorizadas. Não solicitar novamente a senha ao usuário sem evidência de que a credencial provisionada está ausente, revogada ou inválida.
<!-- /CHATGPT_ATENDIMENTO_CREDENTIAL_REF_V1 -->

<!-- SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
**GUI RustDesk backend:** seguir `docs/knowledge/shopvivaliz-graphical-session-recovery.md`. Login LightDM `:1` nao prova perda de sessao Linux: examinar `loginctl` e o XFCE de `fredconsole/:0`; Chromium corporativo permanece separado em `fredrdp/:99`. Uma troca `loginctl activate c1` precisa ser validada por estabilidade e pela imagem real do RustDesk. Nao redefinir senha nem confundir GUI com sessao ChatGPT autenticada.
<!-- /SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
