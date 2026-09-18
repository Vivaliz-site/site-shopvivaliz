ARQUIVO HISTORICO / FLUXO APOSENTADO

O antigo fluxo que editava scripts/autonomous-executor.py para executar ai_collaboration.py foi aposentado.
ai_collaboration.py agora falha fechado e nao executa provedores externos.

Politica vigente:
- automacao recorrente/autonoma: Gemini e OpenRouter;
- Claude, GPT/OpenAI e Codex: somente com gatilho humano explicito;
- nao recriar ShopVivaliz Auto Sync no Task Scheduler;
- usar os watchdogs dedicados de Desktop Commander, relay e runtime janitor.
