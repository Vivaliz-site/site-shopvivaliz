# Guia seguro de agentes e fila canônica ShopVivaliz

Este documento descreve o contrato atual de operação. A antiga fila autônoma que implementava tarefas e promovia código sem revisão está aposentada.

## Estado do sistema

- `tasks-queue.json` é um registro canônico de tarefas e bloqueios.
- Não existe executor autorizado a transformar uma entrada da fila em commit, push ou deploy automaticamente.
- A existência de uma tarefa, um heartbeat ou uma mensagem de sucesso não comprova trabalho.
- Workflows ativos devem ser confirmados diretamente em `.github/workflows/` e pelos respectivos runs e artifacts.

## Inspeção da fila

O utilitário `scripts/manage-tasks-queue.py` é somente leitura:

```bash
python3 scripts/manage-tasks-queue.py list
python3 scripts/manage-tasks-queue.py list --status blocked
python3 scripts/manage-tasks-queue.py stats
```

Os antigos comandos de mutação retornam código diferente de zero e não alteram o arquivo. Isso inclui `add`, `remove`, `mark` e `priority`.

## Como alterar uma tarefa

1. Crie uma branch a partir do `main` atual.
2. Edite `tasks-queue.json` preservando `metadata.schema_version = 2`, todos os campos de evidência e os estados permitidos.
3. Execute os testes da fila.
4. Abra um pull request.
5. Aguarde checks verdes e revisão humana independente.
6. Faça merge somente pelo fluxo de governança aprovado.

Não edite a fila diretamente no branch principal e não use um commit documental para representar execução operacional.

## Estados permitidos

- `pending`: trabalho ainda não iniciado.
- `running`: execução real em andamento, vinculada a uma tentativa identificável.
- `blocked`: depende de acesso, credencial, aprovação ou pré-condição ausente.
- `failed`: a tentativa terminou sem satisfazer o contrato.
- `completed_verified`: trabalho concluído e confirmado por evidência independente.

`completed` não é um estado válido.

## Evidência mínima para `completed_verified`

Uma tarefa concluída deve conter `last_result.success = true` e um objeto `verification` com, no mínimo:

```json
{
  "run_id": "123456789",
  "commit_sha": "40-character-sha",
  "pull_request": "#123",
  "artifact_digest": "sha256:...",
  "verified_at": "2026-08-06T10:00:00Z",
  "tests_passed": true,
  "read_back_verified": true
}
```

A validação deve falhar quando qualquer campo estiver ausente. Contadores, arquivos existentes, mensagens de log ou o término de um processo não substituem read-back e artifact.

## Escrita e integridade

`scripts/task_queue_lib.py`:

- aceita somente o schema canônico `metadata` + `tasks`;
- rejeita o schema legado com chave `queue` quando lido do disco;
- preserva campos desconhecidos e evidências existentes;
- valida estados e IDs duplicados;
- bloqueia por padrão qualquer escritor de runtime;
- para uma alteração revisada, usa lock, arquivo temporário, `fsync` e `os.replace`;
- grava apenas `tasks-queue.json`, sem criar uma segunda fila em `logs/`.

Uma chave `queue` pode existir apenas como visão de compatibilidade em memória para leitores legados. Ela nunca é persistida.

## Verificação local

```bash
python3 -m unittest tests/test_task_queue_safety.py -v
python3 -m py_compile scripts/task_queue_lib.py scripts/manage-tasks-queue.py
python3 scripts/manage-tasks-queue.py list
```

## Monitoramento

Para considerar uma automação saudável, verifique:

- run ID e evento correto;
- SHA executado igual ao SHA esperado;
- conclusão do job e de todas as etapas obrigatórias;
- artifact presente e com digest;
- códigos de saída dos comandos;
- read-back do efeito esperado;
- ausência de threads de revisão bloqueantes.

Um ciclo sem alteração deve ser registrado como `verified_no_action` em summary ou artifact, sem criar commit de progresso e sem mudar a fila para concluída.
