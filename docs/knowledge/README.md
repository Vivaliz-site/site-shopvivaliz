<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->
## REGRA ABSOLUTA: todo erro identificado exige correcao real e validacao

Esta regra vale para **todos os agentes, auditorias, automacoes, repositorios, servicos, ambientes e fluxos ShopVivaliz**, inclusive durante a propria recuperacao: erro, falha, regressao, timeout, bloqueio tecnico, resultado incorreto ou problema de validacao detectado **nao pode ser ignorado, apenas documentado ou encerrado sem remediacao**.

Para cada erro, executar e evidenciar: (1) RED/reproducao observavel e impacto, com prioridade e proprietario; (2) investigacao da causa raiz e de falhas semelhantes; (3) correcao efetiva minima, segura e autorizada com prevencao de recorrencia; (4) GREEN, regressao, verificacao da revisao implantada e E2E na UI real, com trace/midia, persistencia e revisao independente quando aplicavel; (5) registro do resultado e somente entao fechamento individual. Um erro descoberto na tentativa de reparo integra a mesma obrigacao; nenhuma contagem de falhas pode cair por expiracao de janela ou descarte de checkpoints.

**Continuidade obrigatoria:** se a reparacao estiver bloqueada por recusa explicita da plataforma, ownership/lease/fencing ausente, gate de I/O, autorizacao especifica pendente, restricao de seguranca ou terceiro, manter o erro PENDENTE e a tarefa `RUNNING/NAO_APTO` com evidencias, responsavel, impedimento concreto e `next_action` seguro; continuar a retomada pelo fluxo ja existente, sem criar duplicata. Nunca declarar `APTO`, `CONCLUIDO` ou sucesso com erro aplicavel em aberto, diagnostico, ACK, HTTP 200, PR, CI verde ou teste isolado.

**Sem bypass:** esta obrigacao nao amplia permissoes. Respeitar protecoes de branch/producao, controles de seguranca, segredos, MFA, gastos, backups, CAS, ownership, leases/fencing, bloqueios explicitos e gate de I/O. Se a tarefa/automacao for `SOMENTE LEITURA`, **nao executar mutacoes**: registrar e encaminhar cada defeito ao mecanismo de reparo autorizado ja existente. Proibido contornar recusas por conectores, shell ou agentes alternativos ou duplicar agentes, monitorias, automacoes, tarefas, bindings e PRs.
<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

# Knowledge Base do ShopVivaliz
- [Monitor de provedores gratuitos](ai-free-provider-sentinel.md) — monitor somente leitura das duas VMs para uso de provedores pagos e desvios da política Gemini/Ollama.

Esta pasta é a referência operacional para agentes de IA e desenvolvedores.

**Topologia atual (2026-10-29):** producao web/deploy na `marina-a1-4c24g` (OCI ESPOSA), backend/controller/browser na `always-free-arm-1787907847-26` (OCI PRINCIPAL). A origem `shopvivaliz-free-a1` foi excluida segundo proprietario e nao deve ser usada. Ver `current-topology-and-access-2026-10-29.md` para as rotas e MCPs comprovados.

## Auditoria de divergências e hierarquia documental

- [Auditoria de consistência de instruções de agentes — 2026-10-29](../audits/2026-10-29-agent-documentation-consistency.md) — inventário de 814 arquivos Markdown/TXT, origens históricas, instruções conflitantes, correções e pendências de runtime. Uma varredura automatizada não equivale à validação E2E da produção.

## Documentos principais

- [`current-topology-and-access-2026-10-29.md`](current-topology-and-access-2026-10-29.md) — **inventario operacional apos exclusao da VM origem: Marina web/deploy, backend PRINCIPAL, rotas RDC/SSH/OCI, MCP central desatualizado e MCP independente Marina inativo; prova e limites do runtime em 2026-10-29.**
- [`host-access.md`](host-access.md) — **bootstrap obrigatório de hosts e acesso; Remote Control MCP é a rota operacional primária, com SSH privado/Tailscale e OCI Bastion/GitHub apenas nas condições documentadas.**
- [`claude-vm-bootstrap.md`](claude-vm-bootstrap.md) — memória global do Claude na VM: projetos, hosts, navegador na VM, pesquisa técnica atual na web, segurança e fluxo de entrega.
- [`project.md`](project.md) — visão geral, objetivo e módulos do sistema.
- [`buscador.md`](buscador.md) — motor multi-IA de pesquisa, debate contraditório e consenso (OpenAI + Claude + Gemini).
- [`squad-chat.md`](squad-chat.md) — contrato do Squad Chat genérico, separado do Buscador.
- [`troubleshooting.md`](troubleshooting.md) — diagnóstico de erros HTTP, rede, integrações e deploy.
- [`deploy.md`](deploy.md) — fluxo de publicação, curl, CI e checklist.
- [`agent-rules.md`](agent-rules.md) — regras obrigatórias para agentes.
- [`dev-agent-briefing.md`](dev-agent-briefing.md) — onboarding canônico do `@dev`: projetos, hosts, regras, pesquisa técnica na web e padrão de engenharia.
- [`chatgpt-access-parity.md`](chatgpt-access-parity.md) — cross-account Dev/Atendimento parity contract for MCP tools, RDC 2 provider identity, permissions and independent OAuth; includes E2E acceptance gates.
- [`browser-sessions.md`](browser-sessions.md) — vínculo obrigatório entre perfis Chromium, portas CDP e contas ChatGPT; proíbe logout/troca cruzada de conta.
- [`shopvivaliz-login-integrity.md`](shopvivaliz-login-integrity.md) — verificacao read-only a cada 5 minutos das tres contas corporativas, protecao dos perfis e limites de expiracao.
- [`atendimento-chatgpt-bootstrap.md`](atendimento-chatgpt-bootstrap.md) — bootstrap canônico e não secreto da conta corporativa `atendimento@shopvivaliz.com.br`, incluindo plugins mínimos e checklist E2E.
- [`chatgpt-account-migration-curated.md`](chatgpt-account-migration-curated.md) — política de migração curada entre contas ChatGPT: o que preservar, o que descartar e como revalidar histórico antes de usá-lo.
- [`repository-index.md`](repository-index.md) — índice canônico de aplicação, automações e áreas alvo.
- [`structure-policy.md`](structure-policy.md) — política de reorganização por lotes e critérios de conclusão.
- [`updater.md`](updater.md) — atualizações cumulativas, migrations e reparos automáticos.
- [`data-integrity.md`](data-integrity.md) — integridade de catálogo, imagens, pedidos e banco.
- [`testing.md`](testing.md) — testes mínimos, fluxo de compra e pós-deploy.
- [`image-policy.md`](image-policy.md) — política sem placeholders e imagens reais por categoria.
- [`product-images.md`](product-images.md) — critérios de imagens válidas por produto.
- [`pricing-integrity.md`](pricing-integrity.md) — integridade de preços comerciais.
- [`stock-integrity.md`](stock-integrity.md) — disponibilidade e bloqueio de itens esgotados.
- [`cart-integrity.md`](cart-integrity.md) — validação server-side do carrinho.
- [`order-integrity.md`](order-integrity.md) — validação autoritativa de itens, preço, estoque e frete.
- [`order-request-security.md`](order-request-security.md) — contexto único, idempotência, rate limit e prevenção de pedidos duplicados.
- [`order-processing.md`](order-processing.md) — locks atômicos, limpeza automática e proxy confiável.
- [`order-context.md`](order-context.md) — leitura única do corpo e processamento somente após validação.
- [`official-site.md`](official-site.md) — uso do domínio oficial como fonte institucional e comercial.
- [`legal-source-map.md`](legal-source-map.md) — correspondência entre páginas oficiais e arquivos legais locais.
- [`../audits/repository-cleanup-backlog.md`](../audits/repository-cleanup-backlog.md) — fases e pendências da reorganização.

Outros documentos existentes na pasta podem registrar versões, dispositivos, decisões históricas e referências específicas.

- [`chatgpt-vm-auth.md`](chatgpt-vm-auth.md) — autenticação ChatGPT na VM, OTPClient, regra de TOTP local e digitação segura de senha/OTP.

## Bootstrap obrigatório de nova sessão

Antes de qualquer diagnóstico ou alteração, toda nova sessão deve ler, nesta ordem:

1. `host-access.md` e `current-topology-and-access-2026-10-29.md` — descobrir o host e o metodo de acesso corretos, sem usar a origem excluida;
2. `agent-rules.md` — aplicar regras de evidência e segurança;
3. `project.md` — entender o sistema e seus módulos;
4. para o `@dev`/agente programador adicional, `dev-agent-briefing.md`;
5. o documento específico da rotina afetada.


> **Regra contínua de execução:** toda sessão/agente deve usar **@Superpowers em cada etapa material** (planejamento, investigação, implementação, debugging, testes, revisão, correção, PR/merge, deploy, pós-deploy, auditoria e retomadas). Uma única invocação no início não é suficiente. A regra completa está em `agent-rules.md` e `../../REGRAS-AGENTES-CENTRALIZADAS.md`.

Nunca recuperar credenciais de arquivos versionados. Use apenas secrets/runtime autorizado e material protegido já provisionado; o Remote Control MCP não autoriza exibir ou copiar secrets.

## Ordem recomendada para diagnóstico

1. Identifique o sintoma e o erro real.
2. Consulte `host-access.md` para confirmar ambiente e host.
3. Consulte `troubleshooting.md`.
4. Valide o módulo correspondente no código.
5. Use `testing.md` para reproduzir.
6. Consulte `deploy.md` quando houver diferença entre repositório e produção.
7. Consulte `official-site.md` quando a dúvida envolver conteúdo institucional, termos, categorias ou meios de pagamento.
8. Consulte `repository-index.md` e `structure-policy.md` antes de mover arquivos ou alterar automações.
9. Registre lacunas na documentação ao encontrar comportamento novo.
10. <!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 --> Ao confirmar defeito, **não pare no diagnóstico**: corrija o que estiver autorizado e ao alcance, aplique prevenção pertinente, teste e valide no runtime/E2E aplicável; enquanto houver ação segura executável, a tarefa permanece `RUNNING`. <!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->

A documentação não substitui evidência do código, logs, banco, workflow ou resposta do servidor.

- [Recuperacao grafica ShopVivaliz e isolamento de login](shopvivaliz-graphical-session-recovery.md) — runbook obrigatorio para agentes: RustDesk/LightDM/XFCE versus perfis corporativos Dev/Atendimento, validacao real e preservacao do Keyring.
- [Verificacao do Gemini Free](gemini-free-tier-billing-guard.md) - gate para jobs persistentes.
- [Vigia Windows sem IA paga](windows-recurring-ai-free-watch.md) - evita loops persistentes de Codex em checkpoints externos.
