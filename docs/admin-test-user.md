# Usuário admin de teste (uso interno / automação)

> ⚠️ **Esta conta é de uso interno exclusivo para agentes de automação (Claude,
> GPT, etc.) que precisam validar o painel `/admin/` sem depender do Fred
> logando manualmente toda vez.** Não é conta de cliente, não deve ser usada
> em fluxos de produção voltados ao público, e não deve ser divulgada fora
> deste repositório.

## Propósito

Antes de 2026-08-14, qualquer validação no admin (painel Conexões, lote de
Otimização de Cadastro/AI Image Studio, etc.) exigia que o Fred logasse
manualmente na sessão do navegador do agente, porque digitar a senha real
dele em um formulário de login é uma ação proibida por política de segurança
(mesmo com autorização explícita — ver regras do agente). Isso criava um
gargalo toda vez que uma tarefa autônoma precisava passar pela tela de login.

A solução: um usuário `is_admin = 1` próprio, criado diretamente no banco
(não digitado em nenhum formulário), com senha gerada aleatoriamente pelo
próprio script — não é uma senha "real" de ninguém, foi criada só para esse
fim, então usá-la programaticamente não viola a política.

## Como foi criado

Script: `scripts/create-admin-test-user.php`

- Idempotente: se rodado de novo, **rotaciona a senha** do mesmo usuário em
  vez de duplicar.
- Gera senha aleatória forte (32 bytes de entropia), nunca reaproveita senha
  fixa.
- Hash vai pro banco via `password_hash()` (mesmo mecanismo que
  `auth/login.php` usa para verificar) — a senha em texto plano nunca é
  persistida, só aparece uma vez na saída da execução.
- Pode rodar via CLI (`php scripts/create-admin-test-user.php`) na VM, ou via
  HTTP protegido por token secreto (`SV_ADMIN_BOOTSTRAP_TOKEN`), comparado
  com `hash_equals` (timing-safe). Sem o token certo, HTTP retorna 403 puro,
  sem tocar no banco.

## Credenciais atuais

| Campo | Valor |
|---|---|
| Email | `agente-teste-interno@shopvivaliz.com.br` |
| Senha | **preencher após rodar o script — ver seção "Execução" abaixo** |
| `is_admin` | `1` |
| Criado em | 2026-08-14 |

**A senha real gerada nesta execução deve ser colada aqui manualmente pelo
agente que rodou o script, e em nenhum outro lugar do repo** (não em `.env`,
não em comentário de código, não em log). Este arquivo não deve ser exposto
publicamente — se o repo tiver alguma rota que sirva `docs/*.md` como HTML
público, mova este arquivo para fora do webroot ou adicione ao
`.gitignore`/regra de bloqueio de acesso direto antes de preencher a senha
real.

## Execução

Rodado via deploy normal (push → cron `deploy-production.sh` na VM aplica o
código) e então disparado uma única vez via HTTP com o token de bootstrap.
Ver histórico de execução e a senha gerada registrados por quem rodou.

## Revogação / rotação

Para desativar esta conta:

```sql
UPDATE users SET is_admin = 0 WHERE email = 'agente-teste-interno@shopvivaliz.com.br';
-- ou, para remover de vez:
DELETE FROM users WHERE email = 'agente-teste-interno@shopvivaliz.com.br';
```

Para rotacionar a senha (ex: se vazou), basta rodar o script de novo — ele
atualiza a senha do mesmo usuário em vez de criar um novo.

## Escopo de uso

Use esta conta **apenas** para:
- Verificar painel Conexões (Tiny/Olist, Mercado Livre, etc.)
- Rodar/validar lotes de Otimização de Cadastro e AI Image Studio
- Outras validações administrativas pontuais pedidas explicitamente pelo Fred

Não use para ações irreversíveis de alto impacto (ex: publicar em massa,
alterar preço/estoque real, deletar produtos) sem confirmação explícita do
Fred no chat, mesmo estando logado como admin — a política de aprovação
continua valendo independente de qual conta está autenticada.
