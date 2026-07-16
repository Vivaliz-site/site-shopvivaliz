# Status task-033 - notificacoes de estoque

Data: 2026-07-05
Agente: Codex
Status: base segura implementada

## Escopo assumido

- Criar endpoint para usuario se inscrever em alerta de estoque por SKU.
- Criar processador autonomo em modo CLI para verificar estoque disponivel.
- Registrar notificacoes prontas em outbox local auditavel.
- Nao alterar preco, campanha, orcamento, estoque, deploy ou dados financeiros.

## Arquivos criados

- `api/stock-alerts/subscribe.php`
- `api/stock-alerts/process.php`
- `docs/status-task-033-stock-alerts-2026-07-05.md`

## Funcionamento

1. `POST /api/stock-alerts/subscribe.php` recebe `sku`, `email`, `name` opcional e `product_name` opcional.
2. A inscricao fica em `storage/stock-alerts/subscribers.jsonl`.
3. `php api/stock-alerts/process.php` deve ser executado por CLI pelo agente/cron.
4. Quando o SKU aparece com `stock > 0` no catalogo fallback, a notificacao e registrada em `storage/stock-alerts/outbox.jsonl`.

## Governanca

- Precos nao sao lidos para decisao e nao sao alterados.
- Campanhas nao sao criadas, publicadas ou ativadas.
- Nenhum deploy foi executado.
- Nenhum envio externo foi disparado sem canal/credencial aprovado.
- O processador publico e bloqueado: funciona somente via CLI.

## Pendencia controlada

O envio real por web push/e-mail depende da escolha de canal autorizado, credenciais e politica de opt-in. Ate isso existir, o sistema deixa mensagens em outbox local para despacho posterior aprovado.

## Proxima tarefa recomendada

Adicionar worker aprovado para consumir `storage/stock-alerts/outbox.jsonl` e enviar por provedor configurado, mantendo opt-in, unsubscribe e logs de entrega.
