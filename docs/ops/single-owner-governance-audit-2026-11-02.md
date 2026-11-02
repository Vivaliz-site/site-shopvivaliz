# Auditoria transversal de governança — operação com proprietário humano único

Data de inspeção: 2026-11-02 (UTC). Escopo: diretrizes de agentes, 11 repositórios GitHub visíveis na organização Vivaliz-site, controladora OCI principal e VM Marina. **Status: auditoria operacional parcial, NÃO CERTIFICAÇÃO V5/APTO.** Nenhuma proteção externa foi removida.

## Contexto e regra de proporcionalidade

O proprietário declara que é o único operador humano e que as contas envolvidas estão sob sua titularidade. **Mesmo titular não significa mesma identidade ou mesmo papel de segurança:** a conta humana `shopvivaliz-human` é reservada às decisões formais; agentes e serviços executam sob suas credenciais específicas, sem fingir revisão humana.

| Classe | Exemplo | Procedimento |
| --- | --- | --- |
| P0 — observação ou preparação | Inventário, leitura de estado, diagnóstico, testes, rascunho de código em branch | Sem aprovação humana repetida; usar as permissões existentes, registrar evidência suficiente |
| P1 — técnica reversível já autorizada | Correção de bug, CI seletivo, PR comum, manutenção de serviço explicitamente allowlisted no grant vigente, arquivo de workspace não sensível | Executor autorizado corrige, testa o impacto, usa lease/fencing quando aplicável e conclui integração após checks; nenhuma segunda aprovação humana para o mesmo grant |
| P2 — novo poder ou efeito protegido | Alteração de RBAC, sudo/capacidades privilegiadas de MCP, novo ingress de rede, segredos, grants, operação comercial protegida, deleção irreversível ou mudança de branch protection | Requer autorização formal específica quando ainda não concedida; avaliação de efeito, rollback, proteção de identidade, checks e controle do provedor. Uma aprovação válida não deve ser exigida duas vezes para o mesmo escopo |

A classificação acima **não concede acesso novo nem dispensa** restrições de OpenAI, Cloudflare, OCI, GitHub, MFA, proteção contra gastos, segredo, autorização humana exclusiva, proteção de branch, lease ou lock. Ação P1 só é P1 quando a permissão efetiva preexistente for comprovada; caso contrário vira P2 ou fica bloqueada pelo provedor.

## Resultados com evidência

### VM Marina `marina-a1-4c24g`

- RDC 1 e RDC 2: mesmo login do proprietário `fred.discouto@hotmail.com`; dispositivo Marina online (ID de sessão observado `3871094b-c6a8-4e23-8970-c54bd8769960`).
- A execução pelo RDC na Marina usou `ubuntu`; `NoNewPrivs: 0` na sessão e `sudo -n id -un` retornou `root`. **Não existe bloqueio de root global nessa VM.**
- `shopvivaliz-marina-mcp.service` e `shopvivaliz-cloudflared-admin.service` estavam ativos, cada qual com `NoNewPrivileges=yes`, isolando o daemon. Essa restrição não contradiz o sudo da sessão RDC e não deve ser removida sem revisão da superfície do serviço.
- Requisição local sem bearer para `http://127.0.0.1:5580/mcp` respondeu 401, comportamento esperado de endpoint protegido; `mcp-marina.shopvivaliz.com.br` não resolveu no DNS observado. Serviço em execução **não prova ingress MCP autenticado no ChatGPT**.
- Disco raiz observado: 20% ocupado; memória disponível aproximadamente 20 GiB. Services Apache, MySQL, Docker, cloudflared, Amazon Returns, Pricing e MCP ativos. Existem timers de MLRR, catálogo, Shopee e jobs do site: verificar exclusividade de cada escritor/lease e resultados funcionais antes de declarar saúde completa.
- A rota publicada do MCP deve usar o túnel Cloudflare existente, application/policy Access separadas e validação JWT/OAuth no origin, conforme docs canônicos. Não publicar interface administrativa sem esses controles.

### Backend `always-free-arm-1787907847-26`

- ShopVivaliz Remote Control MCP e controlador 24/7 ativos; serviço de navegador corporativo ativo. A unidade `shopvivaliz-desktop-commander.service` do backend retornou `failed`, `ExecMainStatus=20`, `NoNewPrivileges=yes`; o timer guardian retornou sucesso sem comprovar recuperação do serviço.
- `controller_status` reportou `continuity_ready=false`, runtime degradado com checkpoints não vinculados, sessão/dispatcher não vinculados e falhas de retomada ChatGPT. O release ativo era `7d5f67073a564ae8161b735ec7a642b68be5637e`, enquanto o `main` remoto verificado era `d8e9ae12306b1e1adc0c6696d9b021b1c06739f6` no instante da leitura. NÃO promover por fora do fluxo autorizado com owner/lease/fencing.
- Disco raiz: 58% ocupado, memória disponível cerca de 11 GiB. A controladora roda sem ser considerada E2E pronta; não criar segundo worker/controlador concorrente.
- Diretório `/home/ubuntu/shopvivaliz-claude-workspace/site-shopvivaliz` estava em branch histórica e tinha arquivos `storage/codex-bridge/` alterados. Não sobrescrever o checkout; editar código novo via worktree limpa ou GitHub branch.
- Runners Linux ligados a backend, incluindo Solange e MEI, aparecem no inventário; endpoint do GitHub confirmou runner `solange-ci-a1` online e ocupado e `mei-backend-a1` online na organização.

### GitHub e repositórios

Inventário do conector GitHub: **11 repositórios visíveis em `Vivaliz-site`**, com permissão admin na identidade conectada `fredmourao-ai`: site, pipeline, MEI, consultório Solange, legado Solange, Amazon Returns, Pricing, ML Returns, M365, buscador e demo. A consulta filtrada para repositórios pessoais retornou zero; isso **não comprova que nenhum repositório pessoal externo existe**.

- `site-shopvivaliz`: duas regras ativas no `main` (`Audit V5 - required PR and verified checks` e `Copilot review for default branch`); regra de PR exige **0 aprovações gerais** e dois checks `Audit repository automation` e `validation-gate`. Portanto a origem de atrasos não deve ser atribuída automaticamente a revisão humana obrigatória. Revisão automática Copilot merece medir custo/latência, não remover cegamente.
- `-shopvivaliz-pipeline`: ruleset ativo com `governance-gate`, 0 revisões gerais.
- `mei-mg-email`, `solange-rolla-consultorio`, `amazon-returns-safet`, `buscador`, legado `solange-rolla`: branch protections disponíveis, exigência geral de revisões igual a 0, mas conjuntos de checks diferentes. O consultório Solange exige 8 checks e é um candidato a seleção de testes por impacto.
- `ml-pricing-api`, `mercadolivre-returns-recovery`, `shopvivaliz-m365` e `demo-repository`: consultas de branch protection/rulesets retornaram HTTP 403 informando indisponibilidade do recurso no plano atual para repositórios privados. **Não torná-los públicos para contornar essa limitação; não declarar que a branch está tecnicamente protegida.** Validar compensações por merge e Main Guard, reconhecendo que workflow sozinho não impede push direto.
- 10 repositórios apresentaram `CODEOWNERS` com owner humano apenas em `/security/owner-approvals/` ou arquivos de segurança explícitos; o legado `solange-rolla` não tinha `.github/CODEOWNERS`. O demo não tinha `AGENTS.md`. Classificar legado/demo antes de exigir paridade total.
- Busca de PRs abertas da organização no instante da auditoria retornou a PR técnica `Vivaliz-site/solange-rolla-consultorio#315`, destinada a evitar E2E/DB clínico em alterações isoladas de roteamento de revisores. Ela estava `Ready for review`, não era autorização humana e tinha jobs na fila. O runner Solange estava online e ocupado; **não concluir que estava sem runner**. Não iniciar PR concorrente para o mesmo seletor.
- No repositório principal, `scripts/ci_impact_selection.py` distingue fast/full, mas `AGENTS.md`, `agent-rules.md` e workflows executam full por serem transversais; `repository-governance.yml` possui validação ampla. Otimizar por diff real, dependências e teste negativo, não rebaixar mudanças de segurança para docs-only indiscriminadamente.

## Causas e prioridades

1. **P0 operacional:** restaurar continuidade real da controladora; reconciliar binds de sessão/checkpoints e atualizar release apenas com grant/lease/owner válidos. Sem `continuity_ready=true` e E2E, não declarar resolvido.
2. **P1 operacional:** diagnosticar e corrigir o RDC do backend que falhou, preservando a identidade e sessão dos dispositivos ativos; o RDC Marina já oferece sudo legitimamente.
3. **P1 integração:** completar DNS/Access OAuth/JWT do MCP Marina no túnel existente, com grant humano específico de exposição se exigido, sem publicar MCP aberto.
4. **P1 CI/GitHub:** concluir PR #315 após checks; medir latência por job, configurar escopo por impacto e evitar testes redundantes, mantendo checks globais e regras comerciais.
5. **P1 segurança:** documentar proteção efetiva dos quatro repositórios privados e mitigações suportadas pelo plano; não mascarar HTTP 403 como configuração correta.
6. **P2 manutenção:** reconciliar timer/worker único por responsabilidade em cada VM e inventário dos repositórios legados/demo; não desativar timers produtivos sem identificar efeitos.

## Regra para agentes em operação de proprietário único

- Não solicitar revisão humana, autorização de chat, novo token ou MFA de forma redundante em uma ação P0/P1 já concedida ao principal correto.
- Uma negativa em um transporte por falha técnica requer diagnóstico e reparo; uma **recusa explícita de segurança** não autoriza executar a mesma ação por outro transporte.
- Interpretar separadamente `sudo` da conta humana, `NoNewPrivileges` do daemon, RBAC do MCP, regras GitHub e políticas externas. Nenhum deles substitui outro.
- Testar apenas componentes afetados, com gates compartilhados e negativos para impedir regressão/false-green; não repetir suítes clínicas/ecommerce em alteração isolada de roteamento de revisão.
- Uma autorização formal aprovada e vigente deve ser consumida pelo autorizador, sem criar nova solicitação idêntica. Não confundir ser proprietário de todas as contas com a capacidade de bot emitir revisão humana.
- Um checklist durável por tarefa material, não um novo ciclo de aprovação por subcomando; checkpoints não substituem testes. Concluir efetivamente PR comum, merge, deploy e E2E quando aplicáveis.

## Evidência e limitações

Fontes: leitura direta em GitHub API por `fredmourao-ai`, connector GitHub, ShopVivaliz Remote Control `controller_status`, comandos somente leitura no backend e RDC na Marina. Não foram removidos gates, branches, grants, segredos, serviços ou regras de segurança durante a inspeção. A auditoria **não executou E2E gráfico completo das aplicações web, recuperação da controladora, publicação do MCP ou mudanças em rulesets**; esses itens permanecem explicitamente não validados.

## Adendo de validação e remediações (2026-11-02, após inspeção inicial)

**Backup de continuidade recuperado (verificado, não inferido):** o único JSON que o backup rejeitava era um sidecar durável de evidência referenciado pelo checkpoint pai. Uma correção concorrente mais estrita, PR #3525, entrou em `main` pelo SHA `38fa57cf9cf6132afb9c624beb96a525ea35e8fc`; a correção duplicada #3524 foi encerrada com justificativa. Na VM backend, o SHA-256 do script instalado e do arquivo `main` era **idêntico**: `bbaff90c024671f169eb0d361525338f9c84e7967b169f6fb9dfe8780df387ac`. O serviço `shopvivaliz-continuity-state-backup.service` retornou `Result=success`, `ExecMainStatus=0` e backup recente com SHA-256 igual ao sidecar. Isso prova **backup operacional**, não resolve binds de sessão ou certifica `continuity_ready`.

**Bloqueio de CI por identidade do criador da PR:** a PR técnica #3523, criada pelo `github-actions[bot]`, gerou seis runs `conclusion=action_required`, com zero jobs, embora não fosse um grant de autorização. O GitHub documenta esse fluxo especial para PRs criadas por `GITHUB_TOKEN`: https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows . Cada run foi validado quanto ao SHA, PR e origem, e aprovado individualmente pela API oficial `POST /actions/runs/{run_id}/approve` com a conta autorizada `fredmourao-ai`; **aprovação de execução de CI não é revisão humana de grant**. Todos os seis checks passaram no SHA inicial. A branch técnica foi atualizada por script canônico e identidade GitHub externa, sob comparação do SHA esperado, para incluir o `main` sem conflito; CI novo SHA deve terminar antes de merge. Prevenção arquitetural: preferir criação de PRs técnicas pelo principal de serviço GitHub independente com permissão oficial e evento CI verificável, ao invés de `GITHUB_TOKEN` que exige liberação de execução; nunca automatizar a aprovação de workflow de fork/desconhecido sem validação do contexto e permissão.

**Risco de dependências:** consulta à API oficial Dependabot no repositório principal mostrou **40 alertas abertos**, sendo **1 crítico, 15 altos, 21 médios e 3 baixos**, nos manifests `requirements.txt`, `.ai/package-lock.json` e `services/okx-trading-pilot/copy-mcp/package-lock.json`. O crítico e vários altos estão ligados a `PyJWT==2.13.0`; outros altos a `aiohttp==3.14.1`. Versões corrigidas indicadas pela API: PyJWT 2.14.0 e aiohttp 3.14.4. A PR técnica #3527 atualiza somente essas duas versões e adiciona testes de piso; testes direcionados e importações em pacote isolado passaram, **sem atualização da produção**. Alertas npm restantes exigem reconciliação separada e testes em seus respectivos módulos, sobretudo OKX sem executar ordens reais. Não alegar alertas resolvidos até confirmar `main`, Dependabot e runtimes afetados.

**Pendências remanescentes da auditoria:** controlador 24x7 permanece degradado por vínculos ausentes de conversas/checkpoints; Atendimento não autenticado apesar da credencial protegida presente; RDC do backend em estado de autorização oficial necessária; MCP Marina via túnel Cloudflare existente ainda sem DNS/Access/OAuth/JWT e E2E; quatro repositórios privados sem rulesets GitHub por limite de plano; PR clínica #315 com runner único e testes de execução longa. Não declarar auditoria completa V5 nem proteção equivalente entre GitHub público/privado.
