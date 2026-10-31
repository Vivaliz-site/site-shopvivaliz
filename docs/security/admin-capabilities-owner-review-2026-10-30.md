# ShopVivaliz - revisao individual de capacidades administrativas (2026-10-30)

**Status:** proposta para o proprietario; **nenhuma permissao foi concedida ou removida**.
**Escopo:** projeto ShopVivaliz e sistemas expressamente autorizados, em hosts existentes segundo a topologia canonica.
**Regra desta entrega:** PR em rascunho, sem merge automatico, sem deploy. O proprietario decide cada item abaixo separadamente.

## Por que existe este documento

A auditoria encontrou uma mistura de bloqueios operacionais reparaveis, lacunas de implementacao e controles de seguranca obrigatorios. Aprovar um PR por si so nao concede papel de administrador do SO, conta OCI, privilegio GitHub, OpenAI Platform, assinatura de lease, MFA ou permissao de marketplace. Essas autorizacoes precisam ser efetivadas no canal oficial do provedor, associadas a identidade correta e verificadas no runtime.

O MCP central ja expoe `admin_command_run` para hosts permitidos (veja `remote-control-mcp/SPEC.md`), mas isso **nao** significa que todas as operacoes de qualquer host estejam autorizadas. Marina fica fora da allowlist central; a VM `shopvivaliz-free-a1` foi excluida e nunca deve ser alvo.

## Capacidades solicitadas para revisao - inicialmente desabilitadas

Marque somente as capacidades aceitas. Cada uma requer papel/escopo, aprovacao real, lease/ownership valido quando aplicavel, logs redigidos, teste negativo e positivo, e reversao documentada. Os itens ainda nao constituem alteracoes de configuracao.

| Selecao | ID | Capacidade proposta | Escopo e condicoes minimas | Risco |
|---|---|---|---|---|
| [ ] | ADM-01 | Shell administrativo no backend | Apenas host backend canonico; comando aprovado por tarefa; timeout; auditoria | Alto |
| [ ] | ADM-02 | PowerShell administrativo no Fred-Win | Identidade Windows e dispositivo comprovados, sem mostrar segredos | Alto |
| [ ] | ADM-03 | Administracao no KOCEPSV | Via rota independente autorizada, maquina e usuario verificados | Alto |
| [ ] | ADM-04 | Manutencao privilegiada na Marina | Canal proprio aprovado pelo operador da OCI ESPOSA, sem reaproveitar credenciais do backend | Critico |
| [ ] | ADM-05 | Instalacao de software e dependencias | Fonte oficial, versao fixa, verificacao de integridade, rollback | Medio |
| [ ] | ADM-06 | Iniciar/parar/reiniciar servicos autorizados | Servico explicito, impacto, fila/lease e recuperacao | Alto |
| [ ] | ADM-07 | Administracao de containers e workers | Preservar idempotencia, exclusividade, integridade de bancos e filas | Alto |
| [ ] | ADM-08 | Ajustes de rede interna, DNS e tunel | Validacao de rotas, sem expor portas publicas desnecessarias | Alto |
| [ ] | ADM-09 | Gravacao em arquivos de configuracao | Restrita a caminhos autorizados; backup/diff; nunca editar release ativa | Alto |
| [ ] | ADM-10 | Deploy de release imutavel | Branch protegida, CI GREEN, hash do deploy e rollback | Alto |
| [ ] | ADM-11 | Operacoes em GitHub (branch/PR/checks) | Sem push direto a main; nenhuma aprovacao presumida; merge do proprietario nesta tarefa | Medio |
| [ ] | ADM-12 | Manutencao de runners/workflows | Identidade de runner, controle de concorrencia e funcao de bootstrap/recovery | Alto |
| [ ] | ADM-13 | Gestao de chaves no cofre corporativo | Acesso minimo a metadados e rotacao oficial; nao exportar secretos | Critico |
| [ ] | ADM-14 | Criacao do MCP independente na Marina | Service account isolada, RBAC/tunel oficiais, autenticao e sandbox | Alto |
| [ ] | ADM-15 | Browser UI corporativo persistente | Perfis Dev/Atendimento isolados, CDP de cada conta, lease e conta corretos | Alto |
| [ ] | ADM-16 | Tarefas duraveis com retomada | Ownership/fencing, comando finito, checkpoint real, detector de progresso e sem duplicidade | Medio |
| [ ] | ADM-17 | Recuperacao de snapshots/backups em homologacao | Backup valido, sandbox isolado, integridade e reauditoria por interface | Alto |
| [ ] | ADM-18 | Operacoes em integracoes comerciais | Consentimento/escopo do provedor; evitar pedidos, pagamentos e reivindicacoes duplicados | Critico |
| [ ] | ADM-19 | Mudancas de papeis e ACLs internas | Menor privilegio, separacao de funcoes, periodo de validade e trilha de aprovacao | Critico |
| [ ] | ADM-20 | Recuperacao de controlador e vinculacao de conversas | Sem tomar lease alheio, mantendo `RUNNING` ate validacao E2E; controller e contingencia | Alto |

## Bloqueios a reparar tecnicamente, nao desativar

| ID | Defeito / limite observado | Tratamento seguro proposto |
|---|---|---|
| FIX-01 | `required_docs` podia esconder `scope_docs` no worker | Consolidar as listas e bloquear metadados invalidos; implementacao para revisao no PR #3438 |
| FIX-02 | Suite de preflight nao era chamada pelo gate principal | Acrescentar testes ao CI; implementacao para revisao no PR #3438 |
| FIX-03 | Recibo de leitura pode ser reutilizado em outra execucao da mesma tarefa | **Proposta de desenho para revisao oficial**: vincular recibo a uma execucao autenticada, revalidar hashes e expirar por nova sessao; nenhuma alteracao anteriormente recusada foi reaplicada |
| FIX-04 | Checkout de agente desatualizado em relacao ao `main` | Reconciliar clean worktree e hashes antes de validar; nao fazer deploy de artefatos obsoletos |
| FIX-05 | Controller 24x7 ativo, mas sem retomada comprovada de varios checkpoints | Corrigir bindings por conversa real, sessao Dev/Atendimento, leases/fencing e E2E; nao gerar segunda fila |
| FIX-06 | Diretrizes antigas tratam host excluido ou perfil legado como atual | Normalizar instrucoes de roteamento usando documento canonico atual, sem renomear cegamente endpoints |
| FIX-07 | Restricao de loops e confundida com pausa de trabalho valido | Limites de sessoes e custo interrompem apenas loop sem progresso; tarefa autorizada permanece `RUNNING` com proxima acao enquanto houver caminho valido |

## Controles nao elegiveis a bypass por merge

Estas protecoes nao devem ser simplesmente removidas. O proprietario pode solicitar acesso por seus **fluxos oficiais**, mas um PR nao torna legitimo contorna-las:

- MFA, CAPTCHA, recuperacao de conta e isolamento de perfis.
- Resposta de recusa explicita 401/403 ou negacao RBAC de plataforma externa.
- Segredos em logs, chat, historico Git e saida de ferramentas.
- Leases/fencing/ownership pertencentes a outro agente.
- CI obrigatorio, protecao de branch, revisoes e integridade de releases.
- Escrita irreversivel fora de escopo, cobranca real e operacoes comerciais duplicadas.
- Acesso ou recriacao da maquina historica excluida.

## Fluxo de aprovacao por item

1. O proprietario examina e marca os IDs especificos, podendo remover itens inteiros.
2. Para cada ID escolhido, registrar: `task_id`, identidade operadora, host/tenancy, acao exata, periodo autorizado, recurso afetado, risco, lease e criterio de rollback.
3. Implementacao em PR especifico com testes RED/GREEN e execucao E2E; permissoes reais so pela identidade administrativa oficial.
4. Nao promover `main` ou executar deploy sem o merge explicito do proprietario **e** os gates externos exigidos.
5. Reverter pela mesma rota autorizada, registrar auditoria, verificar ausencia de duplicidade.

## Aceite desta revisao

- [ ] Proprietario revisou a matriz e selecionou os IDs que deseja implementar.
- [ ] Nenhuma permissao nova foi ativada por este PR documental.
- [ ] Itens excluidos pelo proprietario nao serao implementados.
- [ ] Implementacoes aprovadas serao feitas com controle de acesso, testes e autorizacoes oficiais, nunca por bypass.
- [ ] Merge e deploy permanecem **fora do escopo deste ciclo**, por ordem expressa do proprietario.

Fontes: `docs/knowledge/current-topology-and-access-2026-10-29.md`, `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md`, `docs/AGENT-DOCS-PREFLIGHT.md`, `remote-control-mcp/SPEC.md`, `docs/ops/marina-independent-secure-mcp-tunnel.md`.
