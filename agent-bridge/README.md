# ShopVivaliz Mobile Agent Bridge

Fila local para tarefas geradas no ChatGPT mobile.

## Pasta observada

- `agent-bridge/inbox/`

## Acoes aceitas

- `create_issue`
- `apply_patch_pr`
- `read_file`
- `run_readonly_audit`

## Regras

- nunca altera `main` diretamente
- nunca faz merge automatico
- nunca aceita patches com segredos
- sempre grava evidencias no resultado

## Execucao

```bash
cp agent-bridge/config.example.json agent-bridge/config.json
python3 agent-bridge/agent_bridge.py --config agent-bridge/config.json --sleep 30
```

## Resultado

- tarefas processadas viram `*.json.done` ou `*.json.failed`
- respostas ficam em `agent-bridge/outbox/`
