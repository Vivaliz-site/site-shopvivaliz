# Knowledge Base do ShopVivaliz

Esta pasta é a referência operacional para agentes de IA e desenvolvedores.

## Documentos principais

- [`project.md`](project.md) — visão geral, objetivo e módulos do sistema.
- [`repository-index.md`](repository-index.md) — índice operacional do repositório, rotinas, workflows, scripts e regra obrigatória de registro de novas rotinas.
- [`routines-registry.md`](routines-registry.md) — registro obrigatório de rotinas, workflows, scripts operacionais, gatilhos, entradas, saídas, risco e validação.
- [`secrets-and-integrations-map.md`](secrets-and-integrations-map.md) — nomes canônicos de secrets, aliases legados e mapa de integrações.
- [`ownership-map.md`](ownership-map.md) — donos funcionais por área, caminho e regra de mudança.
- [`structure-policy.md`](structure-policy.md) — estrutura alvo, regras de criação, arquivamento e bloqueios.
- [`squad-chat.md`](squad-chat.md) — contrato, health check e providers do Squad Chat.
- [`troubleshooting.md`](troubleshooting.md) — diagnóstico de erros HTTP, rede, integrações e deploy.
- [`deploy.md`](deploy.md) — fluxo de publicação, curl, CI e checklist.
- [`agent-rules.md`](agent-rules.md) — regras obrigatórias para agentes.
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

## Auditorias e limpeza

- [`../audits/repository-cleanup-backlog.md`](../audits/repository-cleanup-backlog.md) — backlog controlado de limpeza estrutural, duplicidades e itens sem dono.

Outros documentos existentes na pasta podem registrar versões, dispositivos, decisões históricas e referências específicas.

## Ordem recomendada para diagnóstico

1. Identifique o sintoma e o erro real.
2. Consulte `repository-index.md` para localizar rotinas, workflows e scripts envolvidos.
3. Consulte `routines-registry.md` quando houver script, workflow, cron, trigger, job ou automação.
4. Consulte `secrets-and-integrations-map.md` quando houver credenciais, marketplace, ERP, email, deploy ou API externa.
5. Consulte `ownership-map.md` para descobrir a área dona.
6. Consulte `structure-policy.md` antes de criar, mover, arquivar ou remover arquivos.
7. Consulte `troubleshooting.md`.
8. Valide o módulo correspondente no código.
9. Use `testing.md` para reproduzir.
10. Consulte `deploy.md` quando houver diferença entre repositório e produção.
11. Consulte `official-site.md` quando a dúvida envolver conteúdo institucional, termos, categorias ou meios de pagamento.
12. Registre lacunas na documentação ao encontrar comportamento novo.

## Regra de atualização obrigatória

Toda nova rotina, workflow, script operacional, integração, secret canônico, alias de compatibilidade ou mudança estrutural deve atualizar, no mesmo PR/commit, os documentos correspondentes:

- `repository-index.md`
- `routines-registry.md`
- `secrets-and-integrations-map.md`, quando envolver credenciais ou integrações
- `ownership-map.md`, quando envolver área dona nova ou alterada
- `structure-policy.md`, quando alterar padrão estrutural
- `../audits/repository-cleanup-backlog.md`, quando identificar sujeira, legado ou risco

A documentação não substitui evidência do código, logs, banco, workflow ou resposta do servidor.
