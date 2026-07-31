# Política de estrutura do repositório

## Princípios

1. `main` recebe somente mudanças revisáveis, com checks verdes e evidência ligada ao SHA.
2. Reorganizações físicas são feitas por lotes pequenos; não há movimentação global em um único PR.
3. Workflows agendados ou automáticos usam `permissions: contents: read`, salvo exceção documentada e revisada.
4. Nenhuma automação pode usar auto-merge, push para `main`, `git add -A`, limpeza destrutiva ou concluir tarefas sem evidência verificável.
5. Artifacts obrigatórios usam `if-no-files-found: error`.
6. Secrets ficam apenas em GitHub Secrets/environments ou gerenciador de segredos; documentos e exemplos não usam valores com formato real.

## Estrutura alvo

- `.github/workflows/`: workflows ativos e auditáveis.
- `.github/workflows-archive/`: workflows desativados, sem gatilho executável.
- `scripts/ai/`: agentes e orquestração.
- `scripts/maintenance/`: scanners, validadores e manutenção não destrutiva.
- `scripts/marketplace/<canal>/`: integrações por marketplace.
- `scripts/production/`: rotinas de produção com confirmação, backup, read-back e rollback.
- `scripts/dev/`: ferramentas locais e experimentais.
- `docs/knowledge/`: arquitetura, índices e decisões canônicas.
- `docs/operations/`: procedimentos operacionais atuais.
- `docs/audits/`: relatórios e backlog de saneamento.
- `archive/`: histórico sem execução ativa.
- `tests/unit`, `tests/integration`, `tests/smoke`: validações automatizadas.

## Processo por lote

Cada lote deve:

1. declarar origem e destino;
2. comprovar referências existentes antes da mudança;
3. mover no máximo uma categoria funcional;
4. manter wrapper/stub apenas quando existir consumidor comprovado;
5. adicionar ou atualizar testes;
6. gerar artifact com manifesto de arquivos, diff e resultados;
7. falhar quando qualquer evidência estiver ausente;
8. remover a ponte temporária em lote posterior com prazo explícito.

## Critério de conclusão

Uma etapa só é concluída quando há commit, PR, checks verdes, artifact, arquivos alterados listados e ausência de regressão de automação. Mensagem, log ou alteração de fila isolada não constitui execução.