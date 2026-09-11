# Knowledge Base do ShopVivaliz

Esta pasta é a referência operacional para agentes de IA e desenvolvedores.

## Documentos principais

- [`host-access.md`](host-access.md) — **bootstrap obrigatório de hosts, papéis, Desktop Commander, SSH e repositório para toda nova sessão/agente.**
- [`project.md`](project.md) — visão geral, objetivo e módulos do sistema.
- [`squad-chat.md`](squad-chat.md) — contrato, health check e providers do Squad Chat.
- [`troubleshooting.md`](troubleshooting.md) — diagnóstico de erros HTTP, rede, integrações e deploy.
- [`deploy.md`](deploy.md) — fluxo de publicação, curl, CI e checklist.
- [`agent-rules.md`](agent-rules.md) — regras obrigatórias para agentes.
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

## Bootstrap obrigatório de nova sessão

Antes de qualquer diagnóstico ou alteração, toda nova sessão deve ler, nesta ordem:

1. `host-access.md` — descobrir o host e o método de acesso corretos;
2. `agent-rules.md` — aplicar regras de evidência e segurança;
3. `project.md` — entender o sistema e seus módulos;
4. o documento específico da rotina afetada.

Nunca recuperar credenciais de arquivos versionados. Use apenas secrets/runtime autorizado, chave local protegida ou Desktop Commander conectado.

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

A documentação não substitui evidência do código, logs, banco, workflow ou resposta do servidor.
