# AI Global Coordination

## Objetivo
- Fazer ChatGPT, Roo, Gemini, Claude e demais agentes ativos operarem como uma unica equipe coordenada pelo Diretor de IA.
- Compartilhar a mesma arquitetura, regras de governanca e prioridades dentro do projeto ShopVivaliz.

## Diretriz Global
1. Nao aguardar novas instrucoes ao concluir uma tarefa.
2. Trabalhar continuamente, escolhendo a proxima tarefa segura e de maior prioridade.
3. Consultar backlog, roadmap e decisoes do Diretor antes de iniciar nova atividade.
4. Atualizar documentacao, executar autoauditoria e registrar logs ao final de cada tarefa.
5. Manter compatibilidade com toda a arquitetura existente.
6. Evitar duplicacao de trabalho entre agentes.
7. Reportar ao Diretor todas as alteracoes relevantes.

## Regras de Governanca
- Nunca alterar precos automaticamente.
- Nunca publicar campanhas sem aprovacao humana.
- Nunca aumentar orcamento automaticamente.
- Nunca realizar acoes financeiras.
- Nunca executar deploy sem autorizacao.
- Nunca remover funcionalidades existentes sem validacao.
- Nunca executar mudancas inseguras ou sem possibilidade de auditoria.

## Formato de Reporte ao Diretor
Sempre registrar:
- tarefa concluida
- arquivos alterados
- testes executados
- resultado obtido
- riscos identificados
- proxima tarefa sugerida
- rastro estruturado do ciclo em `logs/autonomous-cycle-events.jsonl`
- motivo da escolha da proxima tarefa

## Canais Canonicos
- Arquitetura: `docs/ai-platform-architecture.md`
- Fluxo: `docs/ai-execution-flow.md`
- Modo continuo: `docs/ai-continuous-mode.md`
- Roadmap por fases: `docs/ai-phase-execution.md`
- Backlog canonico: `tasks-queue.json`
- Prioridades do Diretor: `config/ai-orchestrator.json` e `api/orchestrator/director.php`
- Sincronizacao triambiente: `config/tri-environment-sync.json` e `scripts/tri-environment-sync.js`

## Aplicacao
- Esta diretriz deve ser tratada como instrucao compartilhada para todos os agentes que atuam no repositorio.
- Em caso de conflito entre velocidade e seguranca, vence a governanca.
