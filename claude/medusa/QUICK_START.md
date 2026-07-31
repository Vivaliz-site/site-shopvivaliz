# Medusa — início rápido seguro

## Preparação

```bash
npm ci
npm run build
npm run migrate
```

Configure somente por secret protegido:

```text
DATABASE_URL=<SECRET_PROTEGIDO>
MEDUSA_ADMIN_EMAIL=<EMAIL_ADMINISTRATIVO>
MEDUSA_ADMIN_PASSWORD=<SECRET_PROTEGIDO>
JWT_SECRET=<SECRET_PROTEGIDO>
COOKIE_SECRET=<SECRET_PROTEGIDO>
```

## Inicialização

```bash
npm run dev
```

Crie o administrador pelo mecanismo oficial do projeto. Não use login padrão em scripts, documentação ou seed de produção.

## Teste de produto

Autentique em tempo de execução usando variáveis protegidas. Crie um produto canário, valide a resposta e confirme por leitura posterior. Não registre access token em terminal compartilhado, issue ou artifact.

## Evidência

- build e migration com exit code zero;
- login sem exposição de senha;
- ID do produto canário;
- read-back do produto;
- artifact associado ao run e commit.

O exemplo anterior com senha padrão foi removido e deve ser rotacionado caso tenha sido utilizado.
