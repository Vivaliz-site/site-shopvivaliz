# Sessões de navegador e vínculo de contas

Esta é a fonte canônica para o vínculo entre perfis de navegador da VM backend e contas ChatGPT.

## Vínculos fixos

| Perfil / sessão | Porta CDP | Conta permitida |
|---|---:|---|
| `/home/fredrdp/.config/shopvivaliz-chromium` | `9555` | `fredmourao@gmail.com` |
| `/home/fredrdp/.config/shopvivaliz-atendimento-chromium` | `9556` | `atendimento@shopvivaliz.com.br` |
| `/home/fredrdp/.config/shopvivaliz-atendimento-mcp-chromium` | `9557` | `atendimento@shopvivaliz.com.br` (perfil MCP dedicado) |
| `/home/fredrdp/.config/shopvivaliz-dev-chromium` | `9558` | `dev@shopvivaliz.com.br` |

## Regras obrigatórias

- Nunca fazer logout de uma conta para entrar com a outra.
- Nunca trocar a conta autenticada dentro de um perfil já dedicado.
- Nunca reutilizar cookies, storage, perfil ou porta CDP de uma conta para a outra.
- Para tarefa destinada a `atendimento@shopvivaliz.com.br`, usar exclusivamente `shopvivaliz-atendimento-chromium` / porta `9556`.
- Para tarefa destinada a `fredmourao@gmail.com`, usar exclusivamente `shopvivaliz-chromium` / porta `9555`.
- Para tarefa destinada a `dev@shopvivaliz.com.br`, usar exclusivamente `shopvivaliz-dev-chromium` / porta `9558`.
- O perfil `shopvivaliz-atendimento-mcp-chromium` / porta `9557` pertence à conta Atendimento e não pode ser reutilizado pela conta `dev`.
- Se a sessão correta estiver indisponível, reparar ou reabrir o perfil correspondente; não usar a outra sessão como atalho.
- Preservar login e cookies existentes. Reinício de navegador só é permitido preservando o mesmo `user-data-dir`.
- Antes de qualquer autenticação, validar qual perfil/porta está sendo controlado.
- Nunca registrar senha, OTP/TOTP, cookie ou token. Este documento registra apenas identidade de conta e isolamento de sessão.

## Evidência operacional

Em 2026-10-04, o backend `always-free-arm-1787907847-26` apresentava processos Chromium distintos para:
- `shopvivaliz-chromium` com `--remote-debugging-port=9555`;
- `shopvivaliz-atendimento-chromium` com `--remote-debugging-port=9556`.

A conta de cada perfil deve permanecer conforme a tabela acima.


## Roteamento da retomada por checkpoint

O worker continua unico; nao criar um segundo consumidor da fila nem trocar
`CHATGPT_CONTINUITY_CDP_URL` globalmente para atender uma conta diferente.
Checkpoints corporativos devem fixar a conversa e depois a sessao:

```sh
python3 scripts/agent_task_state.py bind-conversation --task <id> --conversation-id <conversa-confirmada>
python3 scripts/agent_task_state.py bind-browser-session --task <id> --browser-session atendimento
```

`browser_session=atendimento` seleciona somente CDP9556; `fred` seleciona
somente CDP9555. Checkpoints legados sem o campo mantem a rota pessoal atual.
O binding e imutavel, nao conta como progresso e e herdado pelo sucessor junto
com a conversa. Nunca substituir implicitamente a conta/conversa antiga.

Antes de reload ou envio, o worker confirma a identidade da sessao e a rota
exata dentro da propria aba, retornando apenas booleano, sem credenciais.
Stream ativo ou nao confirmado e deferido mesmo se o DOM nao mostrar Stop.
Conta divergente, binding invalido, conversa divergente ou checkpoint terminal
falham sem usar outro perfil como fallback. O monitor legado permanece pessoal;
a cobertura corporativa e checkpoint-driven, nao descoberta global da conta.
Checkpoint corporativo ou com sessao invalida nao acorda o monitor pessoal.

A escolha de porta usa contexto assincrono por tentativa, sem alterar variaveis
de ambiente globais. Testes exercitam o endpoint realmente solicitado, a
expressao de identidade injetada, concorrencia, heranca e falha fechada.
Fonte de implementacao: Node.js `AsyncLocalStorage.run` (documentacao oficial:
https://nodejs.org/api/async_context.html#asynclocalstoragerunstore-callback-args).


## Migração para conta Dev

Em 2026-10-05 foi confirmado no runtime que o perfil `/home/fredrdp/.config/shopvivaliz-dev-chromium` é isolado e reservado a `dev@shopvivaliz.com.br`, com CDP `9558`. O perfil estava provisionado, mas a autenticação ainda exigia verificação por e-mail; portanto, existência do processo/porta não equivale a sessão autenticada. A remoção da conta `fredmourao` do workspace Business só deve ocorrer depois de validar a identidade da sessão Dev e o bootstrap operacional.
