# Knowledge Base do ShopVivaliz

Esta pasta é a referência operacional para agentes de IA e desenvolvedores.

## Documentos principais

- [`host-access.md`](host-access.md) — **bootstrap obrigatório de hosts e acesso; Remote Control MCP é a rota operacional primária, com SSH privado/Tailscale e OCI Bastion/GitHub apenas nas condições documentadas.**
- [`claude-vm-bootstrap.md`](claude-vm-bootstrap.md) — memória global do Claude na VM: projetos, hosts, navegador na VM, pesquisa técnica atual na web, segurança e fluxo de entrega.
- [`project.md`](project.md) — visão geral, objetivo e módulos do sistema.
- [`buscador.md`](buscador.md) — motor multi-IA de pesquisa, debate contraditório e consenso (OpenAI + Claude + Gemini).
- [`squad-chat.md`](squad-chat.md) — contrato do Squad Chat genérico, separado do Buscador.
- [`troubleshooting.md`](troubleshooting.md) — diagnóstico de erros HTTP, rede, integrações e deploy.
- [`deploy.md`](deploy.md) — fluxo de publicação, curl, CI e checklist.
- [`agent-rules.md`](agent-rules.md) — regras obrigatórias para agentes.
- [`dev-agent-briefing.md`](dev-agent-briefing.md) — onboarding canônico do `@dev`: projetos, hosts, regras, pesquisa técnica na web e padrão de engenharia.
- [`browser-sessions.md`](browser-sessions.md) — vínculo obrigatório entre perfis Chromium, portas CDP e contas ChatGPT; proíbe logout/troca cruzada de conta.
- [`atendimento-chatgpt-bootstrap.md`](atendimento-chatgpt-bootstrap.md) — bootstrap canônico e não secreto da conta corporativa `atendimento@shopvivaliz.com.br`, incluindo plugins mínimos e checklist E2E.
- [`dev-chatgpt-bootstrap.md`](dev-chatgpt-bootstrap.md) — bootstrap canônico da conta `dev@shopvivaliz.com.br`, destino da migração curada da conta pessoal para o assento Business de desenvolvimento.
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

1. `host-access.md` — descobrir o host e o método de acesso corretos;
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
