# Antigravity Pre-Command

Antes de qualquer alteração de código, execute obrigatoriamente:

```bash
python git-auto-sync.py
```

Regras:

- sempre trabalhar na branch temporária criada pelo orquestrador
- nunca editar `.env`, `docker-compose.yml`, `git-auto-sync.py` ou `.devcontainer/` sem ordem expressa
- respeitar `LF`, `4 spaces` e sem trailing whitespace
- abortar se o health check local falhar

