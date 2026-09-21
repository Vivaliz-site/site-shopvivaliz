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
- Gera senha aleatória forte (32 bytes de entropia), nunca reaproveita senha fixa.
- Hash vai pro banco via `password_hash()`, como em `auth/login.php`.
- Em CLI, a senha não é impressa: fica persistida em arquivo privado `0600`,
  por padrão `/home/ubuntu/.config/shopvivaliz-admin-test.credentials.json`.
- `--credential-file=/caminho/protegido.json` permite escolher outro destino
  operacional sem colocar a senha em argv, logs, Git ou chat.
- O modo HTTP legado continua protegido por `SV_ADMIN_BOOTSTRAP_TOKEN`; para
  automação nova, preferir sempre o modo CLI com arquivo protegido.

## Credenciais da automação

| Campo | Valor |
|---|---|
| Email | `agente-teste-interno@shopvivaliz.com.br` |
| Senha | **não documentar em Git** |
| Arquivo protegido | `/home/ubuntu/.config/shopvivaliz-admin-test.credentials.json` |
| Permissão | `0600` |
| `is_admin` | `1` |

A conta real do proprietário continua separada. A fonte canônica dessa
credencial humana permanece o arquivo privado `admsite.txt` no Google Drive
autorizado; o usuário de automação existe justamente para a UI poder ser
validada sem transportar ou registrar a senha pessoal.

## Execução

Após deploy normal, executar via CLI na VM de produção:

```bash
php scripts/create-admin-test-user.php
```

A execução cria/rotaciona a conta e grava a credencial protegida sem imprimir a
senha. O gate `ai_squad_ui_audit` sincroniza esse arquivo com a VM de browser
por SSH privado, mantendo `0600`, e usa o perfil persistente
`shopvivaliz-admin-test`.

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
