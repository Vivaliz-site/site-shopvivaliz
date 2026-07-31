# Validação da limpeza do histórico Git

Data: 2026-07-30/31

## Resultado da reescrita

O histórico alcançável do repositório foi substituído por uma raiz sanitizada:

- raiz limpa: `adc5f0401633beb174a9faa3a96edcd17b221afa`;
- a raiz não possui commit pai;
- `main` foi movida para a raiz limpa;
- branches antigos foram removidos;
- tags antigas foram removidas;
- a tag `history-cleanup-2026-07-30` identifica a raiz sanitizada;
- o workflow temporário usado na operação não faz parte da árvore final.

A árvore usada para a nova raiz já havia passado por testes, health check, auditoria de workflows e scanner de credenciais antes da reescrita.

## Proteção permanente

O workflow `.github/workflows/history-integrity.yml` executa em PRs, pushes para `main`, agendamento diário e despacho manual. Ele valida que:

- a raiz registrada em `.security/sanitized-history.json` existe e não possui pai;
- todos os branches remotos descendem da raiz limpa;
- todas as tags remotas descendem da raiz limpa;
- branches transitórios da limpeza não reapareceram;
- a tag de limpeza aponta diretamente para a raiz;
- um relatório JSON e Markdown é publicado como artifact obrigatório.

## Limite da limpeza

A reescrita remove os commits sensíveis dos branches e tags normais. Refs internos de pull requests, caches de objetos, forks, clones locais e caches da plataforma podem preservar objetos antigos por algum tempo. Como as credenciais já foram revogadas, esses objetos não devem conceder acesso, mas o purge definitivo de objetos internos do GitHub depende da plataforma.

Colaboradores com clones anteriores à limpeza devem descartar ou reclonar o repositório. Não devem fazer push de branches antigas, pois o gate de integridade bloqueará refs que não descendam da raiz sanitizada.
