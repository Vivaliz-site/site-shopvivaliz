# Checklist rápido — Secrets e FTP

> Migrado de `CHECKLIST-RAPIDO-SECRETS-FTP.md` durante organização estrutural do repositório.

## Regra

Antes de alterar deploy, FTP ou secrets relacionados, consultar também:

- `docs/knowledge/secrets-and-integrations-map.md`
- `docs/knowledge/routines-registry.md`
- `.github/workflows/deploy.yml`

---

## Erros conhecidos que já deixaram o site offline

### FTP_PORT

```text
ERRADO: FTP_PORT = 2121
CORRETO: FTP_PORT = 21
```

Motivo: o deploy legado usa FTP puro.

### FTP_REMOTE_DIR

```text
ERRADO: FTP_REMOTE_DIR = /home1/shop506/public_html/dev
CORRETO: FTP_REMOTE_DIR = /public_html/dev/
```

Motivo: o workflow deve usar o caminho remoto relativo aceito pelo FTP.

### Newline em deploy.yml

```text
ERRADO: "\\n"
CORRETO: "\n"
```

Motivo: `\n` precisa ser newline real, não barra+n literal.

---

## Nomes canônicos

Usar os nomes abaixo em código novo:

```text
FTP_SERVER
FTP_USERNAME
FTP_PASSWORD
FTP_PORT
FTP_REMOTE_DIR
```

Aliases legados como `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` e `FTP_REMOTE_PATH` só devem existir no centralizador `config/secrets.py`.

---

## Checklist antes de alterar

- [ ] Conferir mapa de secrets.
- [ ] Conferir workflow de deploy.
- [ ] Confirmar `FTP_PORT=21`.
- [ ] Confirmar `FTP_REMOTE_DIR=/public_html/dev/` quando for ambiente dev.
- [ ] Fazer alteração em branch.
- [ ] Criar PR.
- [ ] Não fazer push direto para `main`.
- [ ] Validar com log do workflow e teste HTTP/curl pós-deploy.

---

## Se algo der errado

1. Pausar novas alterações.
2. Não fazer `push --force`.
3. Reverter commit com `git revert`.
4. Registrar evidência no PR ou issue.

---

## Status de migração

- Caminho antigo: `CHECKLIST-RAPIDO-SECRETS-FTP.md`
- Caminho canônico: `docs/operations/deploy/ftp-secrets-checklist.md`
- Compatibilidade: arquivo antigo mantido como ponte.
