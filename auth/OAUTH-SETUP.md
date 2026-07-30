# Configuração segura de Login com Google e Apple

## Estado

A aplicação possui fluxos de login tradicional, Google OAuth 2.0 e Sign in with Apple. As credenciais devem existir somente em secrets protegidos ou variáveis de ambiente do servidor.

## Google OAuth

Configure no provedor:

- URL de retorno de produção: `https://shopvivaliz.com.br/auth/google-callback.php`
- URL local opcional: `http://localhost:8000/auth/google-callback.php`

Variáveis esperadas:

```env
GOOGLE_OAUTH_CLIENT_ID=${GOOGLE_OAUTH_CLIENT_ID}
GOOGLE_OAUTH_CLIENT_SECRET=${GOOGLE_OAUTH_CLIENT_SECRET}
```

## Sign in with Apple

Configure o App ID, Service ID, domínio e URL de retorno no Apple Developer.

Variáveis esperadas:

```env
APPLE_OAUTH_CLIENT_ID=${APPLE_OAUTH_CLIENT_ID}
APPLE_TEAM_ID=${APPLE_TEAM_ID}
APPLE_KEY_ID=${APPLE_KEY_ID}
APPLE_PRIVATE_KEY=${APPLE_PRIVATE_KEY}
```

A chave privada deve ser carregada pelo gerenciador de secrets com as quebras de linha preservadas. Não copie o conteúdo da chave para documentação, logs ou comandos versionados.

## Validação segura

1. Confirme apenas se as variáveis estão presentes; não imprima valores.
2. Teste o redirecionamento em navegador controlado.
3. Confirme `state`, nonce, expiração e URL de retorno.
4. Verifique a criação/vinculação da conta sem registrar tokens.
5. Registre somente código HTTP, etapa e identificador não sensível.

## Segurança

- CSRF por `state`.
- Nonce para evitar replay.
- Timeout do pedido OAuth.
- HTTPS obrigatório.
- Redirects restritos a URLs cadastradas.
- Credenciais somente em ambiente protegido.

A fonte canônica de nomes de secrets deve ser atualizada em `docs/knowledge/secrets-and-integrations-map.md` quando este fluxo mudar.
