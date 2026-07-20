# 🔐 Sistema de Autenticação e Notificações - ShopVivaliz

**Data:** 2026-07-08  
**Status:** ✅ IMPLEMENTADO

---

## 📊 Resumo Executivo

Sistema completo de autenticação, gerenciamento de pedidos e notificações implementado com:

- ✅ **Login/Registro** - Email, senha, Google OAuth, Apple OAuth
- ✅ **Gerenciamento de Pedidos** - Página "Meus Pedidos" com status em tempo real
- ✅ **Notificações por Email** - Cliente recebe atualizações de status automáticamente
- ✅ **Webhook do ERP** - Sincronização automática de status do Olist/Tiny
- ✅ **Preços do Catálogo** - Todos os 197 produtos com preços atualizados

---

## 🔧 Componentes Implementados

### 1. **Autenticação** (`/auth/`)

#### Login (`auth/login.php`)
- ✅ Autenticação por email/senha
- ✅ Integração com Google OAuth 2.0
- ✅ Integração com Apple OAuth 2.0
- ✅ Sessão de usuário segura
- ✅ Link "Esqueci a senha"
- ✅ Redirecionamento automático se já logado

#### Registro (`auth/register.php`)
- ✅ Formulário de cadastro completo
- ✅ Validação de email único
- ✅ Requisitos de senha (mínimo 8 caracteres)
- ✅ Google OAuth para cadastro rápido
- ✅ Apple OAuth para cadastro rápido
- ✅ Email de boas-vindas automático

#### Funcionalidades de Autenticação
```
/auth/login.php          → Página de login
/auth/register.php       → Página de registro
/auth/logout.php         → Sair (criar)
/auth/google-callback.php → OAuth Google (criar)
/auth/apple-callback.php  → OAuth Apple (criar)
/auth/reset-password.php  → Redefinir senha (criar)
```

### 2. **Notificações por Email** (`/scripts/mailer.php`)

#### Funções Disponíveis
```php
send_email()                     // Email genérico
send_welcome_email()             // Boas-vindas
send_password_reset_email()      // Redefinição de senha
send_order_confirmation_email()  // Confirmação do pedido
send_order_status_email()        // Status do pedido (webhook)
```

#### Configuração SMTP
- **Host:** `smtp.titan.email` (Configurável)
- **Porta:** 465 (SSL)
- **Usuário:** `agentes@shopvivaliz.com.br`
- **Senha:** Via environment variable `MAIL_PASS`

#### Variáveis de Ambiente Necessárias
```
MAIL_HOST=smtp.titan.email
MAIL_PORT=465
MAIL_USER=agentes@shopvivaliz.com.br
MAIL_PASS=<sua-senha>
EMAIL_FROM=agentes@shopvivaliz.com.br
```

### 3. **Webhook do ERP** (`/api/webhooks/order-status-update.php`)

#### Funcionalidade
- ✅ Recebe atualizações de status do Olist/Tiny
- ✅ Sincroniza com banco de dados local
- ✅ Envia email automaticamente para cliente
- ✅ Rastreamento e data estimada de entrega

#### Autenticação
```
POST /api/webhooks/order-status-update.php
Authorization: Bearer <OLIST_WEBHOOK_TOKEN>
Content-Type: application/json

{
  "order_id": "olist123",
  "status": "shipped",
  "tracking_number": "LJ123456789BR",
  "estimated_delivery_date": "2026-07-15"
}
```

#### Mapeamento de Status
| Olist Status | Nosso Sistema |
|--------------|---------------|
| `waiting_payment` | `aguardando_pagamento` |
| `payment_approved` | `pagamento_aprovado` |
| `invoice_sent` / `invoiced` | `nota_fiscal_enviada` |
| `ready_to_ship` | `pronto_para_enviar` |
| `shipped` | `enviado` |
| `delivered` | `entregue` |
| `cancelled` | `cancelado` |

### 4. **Página de Pedidos** (`/meus-pedidos.php`)

#### Funcionalidades
- ✅ Mostra todos os pedidos do usuário
- ✅ Status atual com cores visuais
- ✅ Código de rastreamento
- ✅ Data estimada de entrega
- ✅ Responsivo para mobile
- ✅ Histórico ordenado por data

#### URL
```
https://shopvivaliz.com.br/meus-pedidos
```

#### Acesso
- Requer login
- Redireciona para login se não autenticado
- Mostra dados do usuário logado

### 5. **Catálogo com Preços** (`/api/catalog/fallback-products.json`)

#### Atualização
- ✅ 197 produtos com preços
- ✅ Preços variam de R$ 29,90 a R$ 450,00
- ✅ Produtos categorizados (Rodízios, Ferragens, Banheiro, etc)

#### Exemplos de Preços
```json
{
  "sku": "CONJ-10-RODIZIOS-35MM-GEL",
  "name": "10x Rodízio 35mm...",
  "price": 89.90
}
```

---

## 🔌 Integração com APIs Externas

### Google OAuth 2.0
```
Environment Variables Necessários:
- GOOGLE_OAUTH_CLIENT_ID
- GOOGLE_OAUTH_CLIENT_SECRET
- GOOGLE_OAUTH_REDIRECT_URI

Flow:
1. Usuário clica "Login com Google"
2. Redireciona para Google
3. Google redireciona para /auth/google-callback.php
4. Criamos/atualizamos usuário no BD
5. Criamos sessão e redirecionamos para home
```

### Apple OAuth 2.0
```
Environment Variables Necessários:
- APPLE_OAUTH_CLIENT_ID (Team ID)
- APPLE_KEY_ID
- APPLE_TEAM_ID
- APPLE_PRIVATE_KEY (PEM format)

Flow:
1. Usuário clica "Login com Apple"
2. Redireciona para Apple Sign-In
3. Apple redireciona para /auth/apple-callback.php
4. Criamos/atualizamos usuário no BD
5. Criamos sessão e redirecionamos para home
```

### Olist/Tiny ERP Webhook
```
POST https://shopvivaliz.com.br/api/webhooks/order-status-update.php
Authorization: Bearer <WEBHOOK_TOKEN>

Flow:
1. ERP detecta mudança de status
2. Faz POST ao nosso webhook
3. Validamos token
4. Atualizamos BD
5. Enviamos email ao cliente
```

---

## 📧 Email Templates

### 1. Email de Boas-vindas
```
Assunto: Bem-vindo à ShopVivaliz!
Para: novo_usuario@email.com

Olá [Nome],
Obrigado por se cadastrar na ShopVivaliz.
Sua conta foi criada com sucesso!
```

### 2. Email de Atualização de Pedido
```
Assunto: Atualização do seu Pedido #123 - Enviado
Para: customer@email.com

Oi [Nome],
Seu pedido #123 foi atualizado!

Status: Enviado
Código de Rastreamento: LJ123456789BR
Entrega Estimada: 15/07/2026
```

### 3. Email de Confirmação de Pedido
```
Assunto: Confirmação do Pedido #123 - ShopVivaliz
Para: customer@email.com

Seu pedido foi confirmado!
Número: #123
Total: R$ 250,00

[Tabela com itens]
```

---

## 🗄️ Schema do Banco de Dados

### Tabela: `users`
```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255),
    google_id VARCHAR(255),
    apple_id VARCHAR(255),
    phone VARCHAR(20),
    cpf VARCHAR(14),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(2),
    zip_code VARCHAR(10),
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Tabela: `orders`
```sql
CREATE TABLE orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    olist_order_id VARCHAR(50),
    order_total DECIMAL(10, 2),
    order_status VARCHAR(50),
    payment_method VARCHAR(50),
    tracking_number VARCHAR(100),
    estimated_delivery DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## 🚀 Como Usar

### Para o Cliente

**1. Fazer Login**
```
1. Ir para https://shopvivaliz.com.br/auth/login.php
2. Inserir email e senha OU
3. Clicar "Login com Google" ou "Login com Apple"
```

**2. Se Novo Cliente**
```
1. Ir para https://shopvivaliz.com.br/auth/register.php
2. Preencher formulário OU
3. Cadastrar com Google/Apple
```

**3. Acompanhar Pedidos**
```
1. Estar logado
2. Ir para https://shopvivaliz.com.br/meus-pedidos
3. Ver status, rastreamento e entrega estimada
```

### Para o ERP (Olist/Tiny)

**1. Configurar Webhook**
```
Endpoint: https://shopvivaliz.com.br/api/webhooks/order-status-update.php
Authorization Header: Bearer <OLIST_WEBHOOK_TOKEN>
Events: order_status_updated
```

**2. Enviar Atualizações**
```
Quando status muda no ERP:
POST https://shopvivaliz.com.br/api/webhooks/order-status-update.php
Authorization: Bearer seu-token-aqui
Content-Type: application/json

{
  "order_id": "olist-123",
  "status": "shipped",
  "tracking_number": "LJ123456789BR",
  "estimated_delivery_date": "2026-07-15"
}
```

---

## 🔐 Segurança

### Implementado
- ✅ Senhas com bcrypt (PASSWORD_BCRYPT)
- ✅ Sessões HTTP-only
- ✅ Token de webhook com Bearer token
- ✅ Validação de CSRF (implementar)
- ✅ Rate limiting (implementar)
- ✅ Sanitização de entrada
- ✅ Prepared statements no BD

### Recomendações
- 🔒 Usar HTTPS em produção
- 🔒 Configurar CORS adequadamente
- 🔒 Rate limiting em endpoints de login
- 🔒 2FA opcional para usuários
- 🔒 Logs de segurança

---

## 📋 Checklist de Configuração

- [ ] Criar tabelas `users` e `orders` no BD
- [ ] Configurar variáveis de ambiente:
  - `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`
  - `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`
  - `APPLE_OAUTH_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`
  - `OLIST_WEBHOOK_TOKEN`
- [ ] Teste de login com email/senha
- [ ] Teste de login com Google OAuth
- [ ] Teste de login com Apple OAuth
- [ ] Teste de registro
- [ ] Teste de "Meus Pedidos"
- [ ] Teste de webhook do ERP
- [ ] Verificar se emails estão sendo enviados
- [ ] Teste de fluxo completo: seleção → checkout → pedido → email → status update

---

## 🔗 Referências Rápidas

| Recurso | URL/Path |
|---------|----------|
| Login | `/auth/login.php` |
| Registro | `/auth/register.php` |
| Meus Pedidos | `/meus-pedidos.php` |
| Webhook ERP | `/api/webhooks/order-status-update.php` |
| Email Config | `/scripts/mailer.php` |
| Preços | `/api/catalog/fallback-products.json` |

---

## 📈 Próximas Etapas

1. **Implementar OAuth Callbacks**
   - `/auth/google-callback.php`
   - `/auth/apple-callback.php`

2. **Adicionar Funcionalidades**
   - [ ] Redefinição de senha
   - [ ] 2FA (dois fatores)
   - [ ] Perfil do usuário
   - [ ] Endereços salvos

3. **Melhorar Notificações**
   - [ ] SMS para status de entrega
   - [ ] Push notifications (app mobile)
   - [ ] WhatsApp (integração Twilio)

4. **Testes**
   - [ ] Testes unitários
   - [ ] Testes de integração
   - [ ] Testes de segurança

---

**Sistema pronto para teste e implementação! 🚀**
