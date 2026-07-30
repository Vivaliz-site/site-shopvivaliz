# Política de Estrutura do Repositório

## Objetivo

Manter código, testes, workflows, documentação e integrações localizáveis, auditáveis e compatíveis com produção.

## Estrutura permitida

```text
app/                         aplicação/site quando aplicável
api/                         endpoints públicos e internos
config/                      configuração e manifesto estrutural
scripts/ai/                  agentes e automação IA
scripts/maintenance/         auditoria, diagnóstico, QA, segurança e rollback
scripts/marketplace/<canal>/  integrações de marketplace
scripts/dev/                 ferramentas locais, históricas ou experimentais
tests/unit/                  testes unitários
tests/integration/           testes de integração
tests/smoke/                 validações pós-deploy
docs/knowledge/              regras e memória permanente
docs/operations/             runbooks e guias
docs/audits/                 auditorias, incidentes e relatórios
archive/<ano>/               artefatos substituídos
.github/workflows/            workflows ativos
.github/workflows-archive/    workflows não ativos
```

## Fonte de verdade

`config/repository-structure-manifest.json` registra migrações de scripts, documentos, testes e workflows. Toda mudança de caminho deve atualizá-lo.

## Regras de criação

1. Não criar documentos operacionais na raiz.
2. Não criar scripts genéricos diretamente na raiz.
3. Não criar novo script diretamente em `scripts/` quando existir categoria canônica.
4. Não duplicar integração ou secret.
5. Não criar workflow sem registro operacional.
6. Não versionar logs, backups, dumps, `.env` real ou credenciais.
7. Não colocar código de produção em `scripts/dev/`.

## Regras de migração

Uma migração segura deve:

- preservar conteúdo/implementação no destino;
- verificar imports, workflows, CLI e links;
- manter wrapper ou stub quando houver compatibilidade necessária;
- atualizar testes e workflows para o caminho canônico;
- atualizar manifesto, índice e backlog;
- passar pelo validador do manifesto e CI.

## Wrappers e stubs

- Wrapper contém apenas encaminhamento para implementação canônica.
- Stub contém apenas link e aviso de migração.
- Não adicionar lógica nova em wrapper.
- Não adicionar documentação nova em stub.
- Remoção definitiva exige busca sem usos e CI verde.

## Workflows

- Ativos ficam apenas em `.github/workflows/`.
- Pausados/obsoletos ficam em `.github/workflows-archive/paused/`.
- Arquivar workflow exige confirmar que está pausado, duplicado ou substituído.
- Reativação exige PR próprio, registro de rotina e validação de permissões/secrets.

## Produção

Código que altera produto, preço, estoque, banco, deploy ou integração externa deve incluir proteção proporcional ao risco: confirmação, canário, backup, invariantes, rollback e read-back.

## Segurança

- Valor de secret em arquivo versionado é incidente.
- Remover da árvore atual não substitui rotação.
- Limpeza de histórico é procedimento separado.
- Auditor deve bloquear padrões conhecidos e credenciais genéricas contextualizadas.

## Bloqueios de merge

Não mergear quando:

- manifesto inconsistente;
- CI/testes falhando;
- documento raiz não é stub permitido;
- wrapper não aponta ao destino;
- secret/arquivo sensível detectado;
- rotina nova sem registro;
- produção sem evidência/rollback apropriado.
