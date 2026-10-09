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

## Hosts operacionais atuais

| Host | IP | Papel |
|---|---:|---|
| `shopvivaliz-free-a1` | origin `137.131.149.55`, privado `10.0.1.112` | site/web/deploy de produção |
| `always-free-arm-1787907847-26` | privado `10.0.1.38`, sem IP publico | backend, controller do Remote Control MCP, navegador, MEI, M365 e relay Windows |
| `shopvivaliz-ai` | `137.131.156.17` | DEV legado / e-mail / testes; **não tratar como produção web** |

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
ssh -i ~/.ssh/id_rsa ubuntu@137.131.156.17
```

### iPhone / cliente SSH humano

O acesso humano pelas conexoes SSH do iPhone deve permanecer privado:

| Perfil | Host | Porta | Usuario | Destino real |
|---|---|---:|---|---|
| Backend A1 | `100.66.174.74` | `22` | `ubuntu` | backend A1 pela rede Tailscale |
| shopvivaliz A1 | `100.66.174.74` | `2224` | `ubuntu` | relay Tailscale-only no backend -> `10.0.1.112:22` |

O relay do site e provisionado por `scripts/setup-iphone-private-ssh-relay.sh` e deve escutar somente no IPv4 Tailscale atual do backend. Nao usar os IPs publicos das VMs como fallback no iPhone. A chave privada continua armazenada apenas no cliente autorizado e nunca deve ser copiada para Git, logs ou chat.


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

Esta e a rota operacional prioritaria para agentes. O uso de rotas alternativas exige necessidade tecnica concreta ou indisponibilidade comprovada do MCP.

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

Ao validar os quatro hosts, exigir evidência real de:

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
