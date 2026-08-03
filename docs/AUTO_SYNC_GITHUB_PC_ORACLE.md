# Auto Sync PC / GitHub / Oracle

O GitHub e a branch `main` são a fonte da verdade.

## Regras atuais

- O sincronizador nunca publica alterações locais.
- O sincronizador nunca cria commit, branch remota ou pull request automaticamente.
- Qualquer working tree suja bloqueia a execução antes do `fetch`.
- O fluxo normal usa `git fetch` seguido de `merge --ff-only`.
- Não existe push para `main` nem force-push.
- Deploy e sync compartilham o mesmo lock na VM para impedir operações Git concorrentes.

## Recuperação após reescrita sanitizada do histórico

Uma limpeza de histórico torna clones antigos incompatíveis com fast-forward. Nessa situação, o sincronizador só realinha o checkout quando todas as condições abaixo forem verdadeiras:

1. a branch local é `main`;
2. a working tree está limpa;
3. o remoto contém `.security/sanitized-history.json` válido;
4. o `root_sha` do marcador é exatamente a única raiz de `origin/main`;
5. a raiz marcada é ancestral da ponta remota.

Depois dessas verificações, o checkout é colocado na ponta remota e a branch local `main` é realinhada. A operação não usa push, não cria histórico alternativo e não descarta alterações locais.

Se o histórico divergir sem um marcador verificável, o sync termina com estado `blocked-diverged-history` e não altera o checkout.

## Oracle Cloud

Implementação canônica:

- `git-auto-sync.py`: decisão e evidência estruturada;
- `scripts/safe-repo-sync.sh`: wrapper operacional e cópia do relatório para o diretório compartilhado;
- `scripts/auto-sync-oracle.sh`: entrada compatível, sem staging ou publicação;
- `scripts/install-auto-sync-oracle.sh`: instala cron a cada cinco minutos.

### Instalar ou atualizar o cron

```bash
cd /home/ubuntu/shopvivaliz-deploy/repo
bash scripts/install-auto-sync-oracle.sh
```

### Executar manualmente

```bash
cd /home/ubuntu/shopvivaliz-deploy/repo
bash scripts/auto-sync-oracle.sh
```

### Evidência

Relatórios:

- checkout: `logs/tri-environment-sync.json`;
- runtime compartilhado: `/home/ubuntu/shopvivaliz-deploy/shared/logs/tri-environment-sync.json`;
- log do cron: `/home/ubuntu/shopvivaliz-deploy/shared/logs/safe-repo-sync-cron.log`.

A execução é válida somente quando o relatório contém `ok: true`, o `remote_sha` corresponde à `main` e a ação é uma destas:

- `noop`;
- `fast-forward-to-canonical`;
- `realigned-to-verified-sanitized-history`.

## PC Windows

O script do PC continua sendo uma ferramenta local e deve ser executado somente com revisão do operador:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\auto-sync-pc.ps1
```

O PC não participa do deploy de produção. A VM Oracle cria releases imutáveis a partir da `main` por meio de `scripts/deploy-production.sh`.
