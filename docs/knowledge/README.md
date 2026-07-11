# Knowledge Base do ShopVivaliz

Esta pasta é a referência operacional para agentes de IA e desenvolvedores.

## Documentos principais

- [`project.md`](project.md) — visão geral, objetivo e módulos do sistema.
- [`squad-chat.md`](squad-chat.md) — contrato, health check e providers do Squad Chat.
- [`troubleshooting.md`](troubleshooting.md) — diagnóstico de erros HTTP, rede, integrações e deploy.
- [`deploy.md`](deploy.md) — fluxo de publicação, curl, CI e checklist.
- [`agent-rules.md`](agent-rules.md) — regras obrigatórias para agentes.
- [`updater.md`](updater.md) — atualizações cumulativas, migrations e reparos automáticos.
- [`data-integrity.md`](data-integrity.md) — integridade de catálogo, imagens, pedidos e banco.
- [`testing.md`](testing.md) — testes mínimos, fluxo de compra e pós-deploy.

Outros documentos existentes na pasta podem registrar versões, dispositivos, decisões históricas e referências específicas.

## Ordem recomendada para diagnóstico

1. Identifique o sintoma e o erro real.
2. Consulte `troubleshooting.md`.
3. Valide o módulo correspondente no código.
4. Use `testing.md` para reproduzir.
5. Consulte `deploy.md` quando houver diferença entre repositório e produção.
6. Registre lacunas na documentação ao encontrar comportamento novo.

A documentação não substitui evidência do código, logs, banco, workflow ou resposta do servidor.
