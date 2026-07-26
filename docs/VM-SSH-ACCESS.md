# Acesso SSH à VM de produção (Oracle Cloud)

> ⚠️ **Este arquivo documenta apenas a CHAVE PÚBLICA e o processo de acesso.**
> A chave PRIVADA correspondente nunca é commitada no repositório — ela fica
> apenas localmente na máquina do Fred, em `C:\Users\FRED\Downloads\shopvivaliz_vm_agent`.
> Subir uma chave privada para o Git (mesmo em repositório privado) é
> exatamente o tipo de vazamento de credencial que já aconteceu antes neste
> projeto (Client Secret do Tiny/Olist) — nunca repita esse erro aqui.

## Dados de conexão

| Item | Valor |
|---|---|
| IP da VM | `137.131.156.17` |
| Usuário | `ubuntu` |
| Domínio servido | `dev.shopvivaliz.com.br` (produção real, ver `CLAUDE.md`) |
| Diretório do app na VM | `/home/ubuntu/site-shopvivaliz` (cron `git-auto-sync.py` roda aqui a cada intervalo curto, ver `scripts/deploy-production.sh`/`CLAUDE.md`) |
| Chave usada pelo agente | `claude-agent@shopvivaliz-vm-access` (par gerado em 2026-07-26) |

## Chave pública (adicionada ao `~/.ssh/authorized_keys` do usuário `ubuntu` na VM)

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILqHGR4SIOIUTasFDHcDbcdR6YRGQ117ZweMEfKxelWl claude-agent@shopvivaliz-vm-access
```

Se essa chave for revogada/rotacionada no futuro, gere um novo par (não
reaproveite chaves de outros serviços — cada uso deve ter sua própria chave
dedicada) e atualize apenas a linha acima com a nova chave pública, apontando
para onde a nova privada foi salva.

## Onde a chave privada está salva

- **Caminho local (máquina do Fred, Windows):** `C:\Users\FRED\Downloads\shopvivaliz_vm_agent`
- **Não existe cópia da privada neste repositório, em nenhum branch, em nenhum commit.**
- Se um agente futuro rodando em outra sessão/máquina precisar desse acesso,
  ele **não** vai encontrar a chave aqui — precisa ser gerada uma nova
  (seguindo o mesmo processo abaixo) e ter sua pública adicionada pelo Fred.

## Como usar (a partir de uma sessão com acesso à máquina do Fred, ex: via Desktop Commander/terminal local)

```powershell
ssh -i C:\Users\FRED\Downloads\shopvivaliz_vm_agent -o StrictHostKeyChecking=no ubuntu@137.131.156.17 "comando aqui"
```

Para forçar um deploy imediato sem esperar o cron (ver também `CLAUDE.md`):

```powershell
ssh -i C:\Users\FRED\Downloads\shopvivaliz_vm_agent ubuntu@137.131.156.17 "cd /home/ubuntu/site-shopvivaliz && python3 git-auto-sync.py"
```

## Histórico / troubleshooting já mapeado

- Havia chaves antigas em `C:\Users\FRED\.ssh\claude_shopvivaliz` e várias em
  `C:\Users\FRED\Downloads\` (`id_rsa`, `ssh-key-2026-07-04.key`, arquivos
  `.pem`) que **não** estavam autorizadas nesta VM — todas falhavam com
  `Permission denied` (exit 255) mesmo com a rede/porta 22 acessível
  (confirmado via `Test-NetConnection -Port 22`, que retornou sucesso). Não
  presuma que uma chave achada solta na máquina serve para esta VM sem
  confirmar que a pública dela está de fato no `authorized_keys` remoto.
- O cliente OpenSSH do Windows (`ssh.exe`/`ssh-keygen.exe`), quando executado
  via automação remota não-interativa (ex: MCP de terminal), às vezes não
  produz NENHUMA saída em stdout/stderr mesmo em caso de falha real
  (confirmado repetidamente nesta investigação, inclusive com redirecionamento
  explícito via `Start-Process -RedirectStandardError`). Se isso acontecer de
  novo, não assuma rede quebrada — teste a porta separadamente
  (`Test-NetConnection -ComputerName 137.131.156.17 -Port 22`) antes de
  investigar mais. Para gerar novas chaves de forma confiável nesse ambiente,
  use Python (`cryptography.hazmat.primitives.asymmetric.ed25519`) em vez de
  `ssh-keygen.exe`, que apresentou o mesmo problema de saída silenciosa.
