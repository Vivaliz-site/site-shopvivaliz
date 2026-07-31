# Setup rápido seguro

## Pré-requisitos

- dependências instaladas;
- banco criado;
- migrations aplicadas;
- secrets configurados em store protegido;
- usuário administrativo criado com senha única.

## Instalação

```bash
npm ci
npm run build
npm run migrate
```

Use o mecanismo oficial do framework para criar o administrador. Não publique email ou senha padrão em documentação e não reutilize credenciais de seed em produção.

## Variáveis

```text
DATABASE_URL=<SECRET_PROTEGIDO>
ADMIN_EMAIL=<EMAIL_ADMINISTRATIVO>
ADMIN_PASSWORD=<SECRET_PROTEGIDO>
```

Para PostgreSQL local, gere usuário e senha únicos em um gerenciador e monte a connection string apenas no `.env` ignorado ou no secret store.

## Execução

```bash
npm run dev
```

## Validação

- build e migrations com exit code zero;
- login administrativo testado sem registrar senha;
- endpoint de saúde respondendo;
- artifact ligado ao run e commit.

As senhas padrão anteriormente documentadas devem ser consideradas inseguras caso tenham sido usadas e devem ser rotacionadas.
