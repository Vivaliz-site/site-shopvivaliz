# Regras para Agentes

## Fontes obrigatórias antes de alterar o repositório

1. `docs/knowledge/repository-index.md`
2. `docs/knowledge/routines-registry.md`
3. `docs/knowledge/ownership-map.md`
4. `docs/knowledge/secrets-and-integrations-map.md`
5. `docs/knowledge/structure-policy.md`
6. `config/repository-structure-manifest.json`
7. `docs/audits/repository-cleanup-backlog.md`

## Estrutura

- Criar código apenas em caminhos canônicos.
- Não adicionar lógica em wrappers legados.
- Não adicionar conteúdo em stubs da raiz.
- Toda migração de caminho atualiza o manifesto.
- Novo marketplace deve usar `scripts/marketplace/<canal>/`.
- Agentes e automação IA devem usar `scripts/ai/`.
- Auditoria, segurança e diagnóstico devem usar `scripts/maintenance/`.
- Experimentos e ferramentas locais devem usar `scripts/dev/`.
- Testes devem ser classificados em `unit`, `integration` ou `smoke`.

## Rotinas

Toda rotina nova ou alterada deve ser registrada no mesmo PR com:

- arquivo canônico;
- dono funcional;
- gatilho;
- entradas e secrets;
- saídas/artefatos;
- risco;
- validação objetiva.

## Secrets

- Nunca registrar token, senha, cookie, chave, JWT ou payload autenticado.
- Nunca pedir ao usuário para colar secret no chat.
- Usar apenas nomes canônicos do mapa.
- Aliases legados ficam exclusivamente no centralizador.
- Exposição encontrada deve ser removida da árvore atual, registrada e rotacionada externamente.
- Não afirmar que o histórico Git foi limpo sem procedimento e prova específicos.

## Evidência

Uma tarefa só pode ser marcada como concluída quando houver evidência correspondente:

- commit/PR identificável;
- diff compatível com o objetivo;
- teste/check/log/artifact;
- leitura posterior quando houver API externa;
- resultado real, nunca simulação apresentada como execução.

Mensagens autorreferidas, arquivos existentes ou `success: true` sem efeito verificável não são prova.

## Produção

Rotina que altera produção exige, conforme aplicável:

- confirmação explícita;
- limite ou canário inicial;
- backup;
- validação de invariantes;
- rollback;
- read-back;
- artifact/log sem secrets.

## Documentação

- Novos documentos operacionais não entram na raiz.
- Runbooks: `docs/operations/`.
- Auditorias/incidentes: `docs/audits/`.
- Memória permanente: `docs/knowledge/`.
- Relatórios recorrentes: artifacts ou diretório de relatórios aprovado.

## CI obrigatório

Não aprovar conclusão estrutural enquanto falharem:

- `scripts/maintenance/validate_structure_manifest.py`;
- `scripts/audit_repository.py`;
- testes unitários/integrados aplicáveis;
- workflows de qualidade do PR.
