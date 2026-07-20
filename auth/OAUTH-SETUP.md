# 🔐 Configuração de Login com Google e Apple

## Status Atual
✅ **Implementação concluída**
⏳ **Aguardando credenciais de OAuth**

## 📋 O Que Foi Implementado

### Arquivos Criados/Modificados
- ✅ `/auth/login.php` - Página de login com botões Google e Apple
- ✅ `/auth/google-callback.php` - Callback de autenticação do Google
- ✅ `/auth/apple-callback.php` - Callback de autenticação da Apple
- ✅ `/includes/social-auth.php` - Biblioteca de autenticação social
- ✅ Database schema com colunas `google_id`, `apple_id`, `avatar_url`, `email_verified_at`

### Funcionalidades
- ✅ Login tradicional (email + senha)
- ✅ Login com Google OAuth 2.0
- ✅ Login com Apple Sign In
- ✅ Auto-criação de conta no primeiro login
- ✅ Vinculação de provedores a contas existentes
- ✅ Verificação de email
- ✅ Avatar do usuário (Google)
- ✅ Segurança: CSRF tokens, verificação de state, nonce validation

---

## 🔧 Configuração Google OAuth 2.0

### Passo 1: Criar Projeto no Google Cloud Console

1. Acesse [Google Cloud Console](https://console.cloud.google.com/)
2. Clique em **Selecionar um projeto** (topo da página)
3. Clique em **NOVO PROJETO**
4. Digite o nome: `ShopVivaliz`
5. Clique em **CRIAR**

### Passo 2: Habilitar Google+ API

1. No menu de navegação, vá para **APIs e Serviços > Biblioteca**
2. Procure por **Google+ API**
3. Clique em **ATIVAR**

### Passo 3: Criar Credenciais OAuth

1. Vá para **APIs e Serviços > Credenciais**
2. Clique em **CRIAR CREDENCIAIS > ID do Cliente OAuth 2.0**
3. Selecione **Aplicativo da web**
4. Preencha o formulário:
   - **Nome**: `ShopVivaliz Web`
   - **URIs autorizados de redirecionamento**: 
     ```
     https://shopvivaliz.com.br/auth/google-callback.php
     http://localhost:8000/auth/google-callback.php  (opcional, para testes local)
     ```
5. Clique em **CRIAR**
6. Copie o **ID do cliente** e **Segredo do cliente**

### Passo 4: Adicionar ao .env

Edite `/root/site-shopvivaliz/.env` (no Oracle Cloud):

```env
GOOGLE_OAUTH_CLIENT_ID=YOUR_CLIENT_ID_HERE
GOOGLE_OAUTH_CLIENT_SECRET=YOUR_CLIENT_SECRET_HERE
```

---

## 🍎 Configuração Apple Sign In

### Passo 1: Developer Account Setup

1. Acesse [Apple Developer Account](https://developer.apple.com/account/)
2. Faça login com sua conta Apple (crie uma se necessário)
3. Vá para **Certificates, Identifiers & Profiles**

### Passo 2: Criar App ID

1. Clique em **Identifiers**
2. Clique no botão **+**
3. Selecione **App IDs**
4. Clique em **Continue**
5. Configure:
   - **Platform**: iOS (ou selecione todas as plataformas)
   - **Description**: `ShopVivaliz`
   - **Bundle ID**: `com.shopvivaliz` (ou similar)
6. Na seção **Capabilities**, procure por **Sign In with Apple**
7. Ative a opção
8. Clique em **Continue** e depois **Register**

### Passo 3: Configurar Sign in with Apple

1. Volte para **Identifiers**
2. Selecione seu App ID recém-criado
3. Role até **Sign In with Apple**
4. Clique em **Configure**
5. Clique em **Create new Web Authentication Configuration**
6. Preencha:
   - **Primary App ID**: Seu App ID (já preenchido)
   - **Web Domain**: `shopvivaliz.com.br`
   - **Return URLs**: 
     ```
     https://shopvivaliz.com.br/auth/apple-callback.php
     ```
7. Clique em **Save**

### Passo 4: Criar Private Key

1. Vá para **Keys** (menu esquerdo)
2. Clique no botão **+**
3. Nomeie: `ShopVivaliz Key`
4. Ative **Sign in with Apple**
5. Clique em **Continue**
6. Clique em **Register**
7. **Baixe o arquivo .p8** (guarde-o seguro!)
8. Copie o **Key ID** exibido na tela

### Passo 5: Obter Team ID

1. No menu principal, clique em **Membership**
2. Procure por **Team ID** na seção **Membership Information**
3. Copie o valor

### Passo 6: Preparar a Chave Privada

1. Abra o arquivo `.p8` baixado com um editor de texto
2. Copie **TODO O CONTEÚDO** (incluindo `-----BEGIN PRIVATE KEY-----` e `-----END PRIVATE KEY-----`)
3. Certifique-se de que as quebras de linha sejam preservadas

### Passo 7: Adicionar ao .env

Edite `/root/site-shopvivaliz/.env` (no Oracle Cloud):

```env
APPLE_OAUTH_CLIENT_ID=YOUR_SERVICE_ID_HERE
APPLE_TEAM_ID=YOUR_TEAM_ID_HERE
APPLE_KEY_ID=YOUR_KEY_ID_HERE
APPLE_PRIVATE_KEY=<conteúdo-completo-da-chave-privada-do-arquivo-.p8>
```

⚠️ **Importante**: A chave privada deve incluir as quebras de linha originais do arquivo `.p8`. Use o script `setup-oauth-env.py` para facilitar esta configuração.

---

## ✅ Validação de Setup

Após adicionar as credenciais ao `.env`:

### 1. Recarregar Variáveis de Ambiente
```bash
# No Oracle Cloud
cd /home/ubuntu/site-shopvivaliz
source .env
php -r "echo getenv('GOOGLE_OAUTH_CLIENT_ID');"
```

### 2. Testar Login

1. Acesse https://shopvivaliz.com.br/auth/login.php
2. Clique em "Google" ou "Apple"
3. Complete o fluxo de autenticação
4. Você deve ser redirecionado para a home com sessão ativa

### 3. Verificar Banco de Dados

```sql
-- No Oracle Cloud
mysql -u shopvivaliz -p shopvivaliz -e "
  SELECT id, email, google_id, apple_id, email_verified_at FROM users LIMIT 5;
"
```

---

## 🚨 Troubleshooting

### Erro: "Google não está configurado"
- **Causa**: Variáveis `GOOGLE_OAUTH_CLIENT_ID` ou `GOOGLE_OAUTH_CLIENT_SECRET` ausentes
- **Solução**: Adicione as variáveis ao `.env` e reinicie o servidor

### Erro: "Sessão do login expirou"
- **Causa**: Timeout de 30 minutos ou navegação entre domínios
- **Solução**: Tente fazer login novamente

### Erro: "Apple não retornou identificador"
- **Causa**: Resposta inválida do Apple ID
- **Solução**: Verifique se a chave privada está correta

### Email não aparece (Apple)
- **Causa**: Primeira vez usando o app - Apple esconde email
- **Solução**: 
  1. Acesse [appleid.apple.com](https://appleid.apple.com/)
  2. Vá para **Segurança > Aplicativos e sites**
  3. Localize o app
  4. Clique em **Editar**
  5. Clique em **Remover acesso**
  6. Tente login novamente - desta vez Apple compartilhará o email

---

## 📊 Variáveis de Ambiente Necessárias

```env
# Google OAuth 2.0
GOOGLE_OAUTH_CLIENT_ID=<seu_client_id>
GOOGLE_OAUTH_CLIENT_SECRET=<seu_client_secret>

# Apple Sign In
APPLE_OAUTH_CLIENT_ID=<seu_service_id>
APPLE_TEAM_ID=<seu_team_id>
APPLE_KEY_ID=<seu_key_id>
APPLE_PRIVATE_KEY=<chave_privada>
```

---

## 🔒 Segurança

- ✅ CSRF protection via state tokens
- ✅ Session-based NONCE validation
- ✅ 30 minutos de timeout para OAuth requests
- ✅ Certificados SSL/TLS obrigatórios
- ✅ Redirects only para caminhos locais (open redirect prevention)
- ✅ Passwords hasheados com bcrypt
- ✅ Email verification support

---

## 📞 Suporte

Se encontrar problemas:

1. Verifique os logs: `/logs/` ou `/admin/monitor/`
2. Teste as credenciais no console de desenvolvedor
3. Limpe cookies/cache do navegador
4. Tente em incógnito/modo privado
5. Contate o suporte ShopVivaliz

---

**Última atualização**: 2026-07-12  
**Status**: ✅ Pronto para produção
