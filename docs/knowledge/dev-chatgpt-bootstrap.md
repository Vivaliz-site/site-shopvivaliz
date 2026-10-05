# ChatGPT Dev — bootstrap canônico

**Conta:** `dev@shopvivaliz.com.br`  
**Papel:** conta Business de desenvolvimento da ShopVivaliz.  
**Migração:** destino da migração curada da conta `fredmourao@gmail.com`; não importar histórico irrelevante.

## Ordem obrigatória de leitura

1. `docs/knowledge/host-access.md`
2. `docs/knowledge/README.md`
3. `docs/knowledge/agent-rules.md`
4. `docs/knowledge/project.md`
5. `docs/knowledge/dev-agent-briefing.md`
6. `docs/knowledge/browser-sessions.md`
7. `docs/knowledge/chatgpt-account-migration-curated.md`
8. documentação específica do projeto afetado

## Papel da conta

A conta `dev@shopvivaliz.com.br` deve atuar como conta principal de desenvolvimento no workspace Business, com foco em engenharia, debugging, testes, revisão, deploy e validação operacional.

O contexto migrado deve conter apenas conhecimento durável, decisões finais, tarefas ainda vigentes e incidentes relevantes. Chats casuais, repetitivos, status antigos e segredos não devem ser importados.

## Fonte de verdade

Prioridade obrigatória:

1. evidência viva do runtime/produção/provider;
2. repositório canônico e configuração atual;
3. `docs/knowledge/`;
4. histórico curado.

Histórico migrado nunca substitui evidência atual.

## Regras operacionais críticas

- Remote Control MCP é a rota operacional primária.
- Produção web: `shopvivaliz-free-a1`.
- Backend/controller/browser: `always-free-arm-1787907847-26`.
- `shopvivaliz-ai` é legado DEV/e-mail/testes, nunca produção web.
- Produção usa releases imutáveis em `/home/ubuntu/shopvivaliz-deploy/`; nunca editar `current/` ou release ativa.
- Auditoria/diagnóstico implica corrigir, prevenir, testar e validar, não apenas relatar.
- Não aceitar falso-verde.
- Nunca expor senha, token, chave, cookie, OTP/TOTP, seed ou conteúdo de secret.

## Sessão de navegador

A conta `dev@shopvivaliz.com.br` deve possuir perfil Chromium dedicado e isolado na VM backend.

Até que o perfil seja criado e validado com evidência fresca:
- não reutilizar o perfil `fredmourao`;
- não reutilizar o perfil `atendimento`;
- não marcar a conta `dev` como operacional no navegador;
- não fazer logout de outra conta para entrar como `dev`.

Depois da criação, registrar em `browser-sessions.md` o `user-data-dir`, porta CDP e evidência de identidade da sessão.

## Migração do assento Business

A troca `fredmourao -> dev` só deve ocorrer depois de:

1. `dev@shopvivaliz.com.br` estar adicionada ao workspace Business;
2. bootstrap lido e validado em sessão nova;
3. plugins/conectores necessários configurados;
4. perfil de navegador dedicado validado;
5. pacote de histórico curado disponível;
6. teste operacional inofensivo aprovado;
7. confirmação de que nenhuma tarefa ativa depende exclusivamente da conta `fredmourao`.

Somente então remover `fredmourao` do assento Business.

## Critério de migração concluída

A conta `dev` deve conseguir, sem consultar a conta antiga:
- identificar hosts e repositórios canônicos;
- selecionar a ferramenta/host corretos;
- distinguir estado histórico de estado vivo;
- localizar regras de SAFE-T, Mercado Livre, MEI-MG e Solange;
- trabalhar sem pedir novamente segredos já provisionados, salvo ausência/invalidez comprovada;
- executar teste inofensivo e retornar evidência fresca.

