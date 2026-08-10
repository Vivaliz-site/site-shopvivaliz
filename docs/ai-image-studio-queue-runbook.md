# AI Image Studio Queue Runbook

## Estado atual

- `admin/ai-image-studio/process_item.php` suporta modo assíncrono via `enqueue_only=1` ou `async=1`.
- `scripts/ai-image-studio-worker.php` consome jobs com `--once`, `--limit=` e `--sleep=`.
- `api/health.php` reconhece a fila e reporta `queue` no payload.
- `core/queue/queue.php` usa SQLite quando disponível e fallback em arquivo quando o driver não existir.

## Smoke check

1. Suba o servidor local:
   - `php -S 127.0.0.1:8011 -t C:\site-shopvivaliz`
2. Abra:
   - `http://127.0.0.1:8011/api/health.php?health=1`
3. Verifique:
   - `ok: true`
   - `queue.schema_version` reconhecido
4. Processamento em segundo plano:
   - `php scripts/ai-image-studio-worker.php --once --limit=1 --sleep=1`

## Observações

- Se o ambiente não tiver driver SQLite, a fila opera em `storage/queue.json`.
- O payload do health não deve ser usado para cobrar sucesso de geração real; ele só confirma saúde estrutural.
