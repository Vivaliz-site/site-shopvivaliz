<?php
declare(strict_types=1);

/**
 * Wrapper fino chamado por scripts/autonomous-orchestrator-loop.sh a cada
 * ciclo. A implementacao real (ProjectDirectorAgent::run_full_audit()) ja
 * existia em agents/project-director-agent.php e ja se auto-executa quando
 * chamada via CLI -- so faltava este arquivo no caminho que o loop espera.
 * Sem ele, o orquestrador rodava todo minuto sem fazer nenhum trabalho real
 * (erro de "arquivo nao encontrado" engolido silenciosamente por `|| true`
 * no shell script).
 */
require __DIR__ . '/../../agents/project-director-agent.php';
