# Configuração segura do Supabase

## Criar o projeto

1. Acesse o painel oficial do Supabase.
2. Crie o projeto na região apropriada.
3. Gere uma senha única e aleatória em um gerenciador de senhas.
4. Não registre a senha em documentação, issue, comando ou screenshot.

## Secrets necessários

```text
SUPABASE_URL=<SECRET_PROTEGIDO>
SUPABASE_ANON_KEY=<SECRET_PROTEGIDO>
SUPABASE_SERVICE_ROLE_KEY=<SECRET_PROTEGIDO>
SUPABASE_DB_URL=<SECRET_PROTEGIDO>
```

A connection string deve existir somente em secret protegido. Use a configuração do ambiente para montar a conexão em tempo de execução.

## Banco

Execute migrations por ferramenta controlada, com backup e validação. Não cole SQL destrutivo diretamente no painel sem revisão.

## Validação

- conexão autenticada sem imprimir a URL completa;
- migration exit code zero;
- consulta de read-back;
- artifact ligado ao run e commit.

A senha anteriormente sugerida neste documento deve ser considerada insegura caso tenha sido usada e deve ser rotacionada.
