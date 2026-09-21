# Remote MCP para agentes

> **Fred-Win possui uma rota operacional própria e canônica.** Para acessar, diagnosticar ou declarar o estado do Fred-Win, a leitura de [`docs/FRED-WIN-PRIVATE-RELAY.md`](FRED-WIN-PRIVATE-RELAY.md) é obrigatória. Não use `https://rce-shopvivaliz.trycloudflare.com` e não declare o computador inativo sem testar primeiro o `health` pelo relay privado GitHub Actions -> Oracle VM -> reverse SSH -> Fred-Win.

**Fonte verificada:** `C:\Users\FRED\Downloads\mcp.pdf`, inspecionado visualmente em 2026-08-11.

Este documento registra a informacao operacional extraida do PDF sem publicar identificadores pessoais, codigo de device ou token efemero.

## Dois canais que não devem ser confundidos

1. **Remote MCP/Desktop Commander autenticado por device flow:** canal descrito pelo PDF e pelas regras gerais deste documento.
2. **Fred-Win private relay:** canal operacional do projeto, documentado em `docs/FRED-WIN-PRIVATE-RELAY.md`, que usa `.github/workflows/fred-win-remote-action.yml`, SSH para a Oracle VM `137.131.156.17` e reverse tunnel na porta 5557.

A ausência do primeiro canal em uma sessão não prova indisponibilidade do segundo. Quando a tarefa mencionar Fred-Win, Exchange Admin, navegador real no Windows, porta 5557, `ssh-tunnel-service-managed.ps1` ou `ops/fredwin-request.json`, prevalece o protocolo do relay privado.

## Estado observado no PDF

- O terminal estava em `Administrador: Windows PowerShell`.
- A autenticacao com o Remote MCP server foi iniciada por device authorization flow.
- A URL exibida para verificacao foi `https://mcp.desktopcommander.app/device/verify`.
- O codigo de device tinha validade de 15 minutos e nao deve ser reutilizado nem commitado.
- A autorizacao foi concluida com sucesso.
- O canal foi assinado, o dispositivo foi marcado como online e a presenca passou a ser rastreada.
- O dispositivo ficou pronto para uso como Remote MCP, vinculado a uma conta Gmail do proprietario e a um dispositivo Windows local. O e-mail completo e o device ID completo devem permanecer fora do repositorio.

## Regras para agentes

1. Tratar o Remote MCP como canal operacional autenticado, nao como secret versionado.
2. Nunca commitar codigo de device, device ID completo, e-mail completo, token, cookie, session dump ou screenshot contendo esses dados.
3. Quando uma tarefa depender desse MCP, validar estado atual antes de declarar disponibilidade:
   - confirmar que o conector/ferramenta MCP esta acessivel na sessao;
   - confirmar que o dispositivo aparece online, quando a ferramenta disponibilizar essa evidencia;
   - registrar data/hora absoluta e resultado `COMPROVADO`, `FALHOU` ou `INCONCLUSIVO`.
4. Quando a tarefa depender especificamente do Fred-Win, aplicar `docs/FRED-WIN-PRIVATE-RELAY.md`:
   - executar primeiro a ação allowlisted `health` em `.github/workflows/fred-win-remote-action.yml`;
   - considerar `ATIVO/COMPROVADO` somente com HTTP 200 e os campos esperados (`status=ok`, `environment=fred-win`, `mcp_version=1.0.0`);
   - usar `INCONCLUSIVO`, nunca `INATIVO`, quando a rota oficial não puder ser testada;
   - nunca validar pelo hostname histórico `rce-shopvivaliz.trycloudflare.com`.
5. Se o device authorization flow for solicitado novamente, o codigo expira em 15 minutos e deve ser tratado como dado temporario. O agente pode orientar o operador a abrir a URL de verificacao, mas nao deve publicar o codigo no Git, em PR, em logs permanentes ou em relatorios publicos.
6. Falha de acesso ao MCP nao autoriza pular validacao obrigatoria. O agente deve usar uma rota aprovada alternativa quando existir, ou encerrar aquele item como `INCONCLUSIVO`.
7. O Remote MCP nao amplia permissoes:
   - nao autoriza bypass de branch protection;
   - nao autoriza force-push;
   - nao autoriza expor secrets;
   - nao autoriza alterar preco, estoque, pedido, pagamento, ERP ou marketplace fora do escopo aprovado.

## Variaveis de ambiente

O `.env.example` documenta apenas nomes e valores seguros. Valores reais devem ficar somente em `.env` local, `.env` protegido da VM ou secret manager.

```dotenv
REMOTE_MCP_ENABLED=false
REMOTE_MCP_PROVIDER=desktopcommander
REMOTE_MCP_VERIFY_URL=https://mcp.desktopcommander.app/device/verify
REMOTE_MCP_AUTH_FLOW=device_authorization
REMOTE_MCP_DEVICE_NAME=
REMOTE_MCP_AUTH_USER_EMAIL=
REMOTE_MCP_DEVICE_ID=
REMOTE_MCP_ACCESS_TOKEN=
```

Observacao: o PDF comprova que houve autorizacao por device flow, mas nao contem token persistente reutilizavel. O codigo exibido na tela era temporario e expirava em 15 minutos.

## Uso esperado

- Usar o Remote MCP como apoio de observacao/controle quando a tarefa exigir estado real do ambiente do proprietario.
- Para Fred-Win, preferir o relay privado canônico documentado em `docs/FRED-WIN-PRIVATE-RELAY.md`.
- Preferir conectores oficiais, CLI autenticado, SSH autorizado ou GitHub Actions quando eles forem a rota mais verificavel para a tarefa.
- Registrar evidencia objetiva sempre que o MCP for usado para validar UI, ambiente local, arquivo, servico ou integracao.

## Dados que devem ficar fora do Git

- Codigo de device exibido durante autenticacao.
- Device ID completo.
- E-mail completo do usuario autenticado.
- Capturas sem mascara contendo qualquer um dos itens acima.
- Tokens, cookies, chaves, refresh tokens ou arquivos de sessao.
