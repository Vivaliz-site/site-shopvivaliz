# ShopVivaliz Agents Runtime

Runtime do squad operacional construído com OpenAI Agents SDK.

## Componentes

- `agent.py`: orquestrador, agente de diagnóstico, agente de QA e ferramentas HTTP.
- `main.py`: API FastAPI, endpoint `/health`, endpoint `/run` e modo CLI.
- `pyproject.toml`: dependências isoladas do runtime.

## Variáveis

- `OPENAI_API_KEY`: obrigatória para executar o agente.
- `PORT`: quando definida, inicia o servidor HTTP.

Nunca registre valores de secrets no repositório ou nos logs.

## Execução local

```bash
cd agents/runtime
uv sync
uv run python main.py "Audite o site e reporte somente evidências."
```

Servidor:

```bash
PORT=8421 uv run python main.py
curl -fsS http://127.0.0.1:8421/health
```

Execução do agente:

```bash
curl -fsS -X POST http://127.0.0.1:8421/run \
  -H 'content-type: application/json' \
  -d '{"message":"Execute o smoke test público e classifique os resultados."}'
```

## Limites operacionais

O agente pode inspecionar URLs públicas e produzir diagnóstico. Ele não realiza compras, não altera pagamentos e não lê valores de secrets. Mudanças no repositório e deploy continuam protegidos pelos workflows e pelas políticas do projeto.
