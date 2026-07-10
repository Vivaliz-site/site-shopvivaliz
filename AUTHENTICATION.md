# Sistema de Autenticação - ShopVivaliz

> Documentação do sistema de login, cadastro e gerenciamento de conta

## Visão Geral

O ShopVivaliz implementa um sistema completo de autenticação baseado em:
- **PHP nativo** com `$_SESSION`
- **Banco de dados** MySQL com tabela `users`
- **Segurança** usando `password_hash()` (bcrypt) e validação de força de senha
- **Persistência** de sessão com HTTPS/cookies seguros

## Arquivos Implementados

### Core Authentication
- **`config/auth-helpers.php`** — Funções reutilizáveis de autenticação
- **`config/database.php`** — Conexão e schema da tabela `users`
- **`database/users-schema.sql`** — Documentação do schema

### Pages
- **`login/index.php`** — Formulário de login com validação client/server
- **`cadastro/index.php`** — Formulário de registro com validação de força de senha
- **`minha-conta/index.php`** — Dashboard do usuário logado
- **`includes/navbar.php`** — Navbar atualizada com botões de auth

### APIs
- **`api/auth/check.php`** — Verificar se está logado (JSON)
- **`api/logout/index.php`** — Fazer logout via POST

### Estilos
- **`assets/css/auth-style.css`** — Estilos completos para auth pages
- **`assets/css/navbar-auth-buttons.css`** — Estilos dos botões de auth na navbar

### Rotas
- **`.htaccess`** — Rotas URL rewritten para /login, /cadastro, /minha-conta

## Funções Disponíveis (auth-helpers.php)

### Verificação de Login
```php
is_logged_in(): bool
// Retorna true se usuário está logado
```

### Obter Usuário Atual
```php
get_current_user(): ?array
// Retorna array com dados do usuário: id, email, name, phone, cpf, created_at
// Retorna null se não está logado
```

### Requer Login
```php
require_login(): void
// Redireciona para /login se não está logado
// Salva URL atual para redirecionar após login
```

### Autenticação
```php
authenticate_user(string $email, string $password): array
// Autentica usuário por email/senha
// Retorna: ['success' => bool, 'message' => string, 'code' => string, 'user' => ?array]
```

### Registro
```php
register_user(string $name, string $email, string $password, string $password_confirm): array
// Registra novo usuário
// Validações: nome >= 3 chars, email válido, senha forte, senhas iguais
// Auto-login após registro bem-sucedido
// Retorna: ['success' => bool, 'message' => string, 'code' => string, 'user_id' => ?int]
```

### Senhas
```php
hash_password(string $password): string
// Faz hash de senha com bcrypt (cost=12)

verify_password(string $password, string $hash): bool
// Verifica senha contra hash
```

### Validações
```php
is_valid_email(string $email): bool
// Valida formato de email

is_strong_password(string $password): bool
// Verifica: >= 8 chars, 1 maiúscula, 1 minúscula, 1 número
```

### Logout
```php
logout_user(): void
// Faz logout e destrói sessão
// Redireciona para home
```

### Atualizar Perfil
```php
update_user_profile(int $user_id, array $data): array
// Atualiza nome, telefone, CPF
// Validações: nome >= 3 chars, CPF = 11 dígitos
// Retorna: ['success' => bool, 'message' => string]
```

### Logs
```php
log_activity(int $user_id, string $action, string $details = ''): void
// Registra atividade do usuário (login, logout, register, profile_update)
// Salva IP address
```

## Fluxo de Login

1. Usuário acessa `/login`
2. Submete formulário com email e senha
3. `authenticate_user()` valida:
   - Email e senha não vazios
   - Email válido
   - Usuário existe no banco
   - Senha correta (verifica contra hash)
4. Se OK: 
   - Cria sessão com `user_id`, `user_email`, `user_name`
   - Registra log de login
   - Redireciona para `/minha-conta` ou URL de redirect
5. Se erro: Mostra mensagem e permite nova tentativa

## Fluxo de Registro

1. Usuário acessa `/cadastro`
2. Submete formulário com nome, email, senha, confirmar senha
3. `register_user()` valida:
   - Todos os campos preenchidos
   - Nome >= 3 caracteres
   - Email válido e único
   - Senha forte (>= 8 chars, maiúscula, minúscula, número)
   - Senhas iguais
4. Se OK:
   - Faz hash de senha com bcrypt
   - Insere usuário no banco
   - Auto-login (cria sessão)
   - Registra log de registro
   - Redireciona para `/minha-conta` após 2 segundos
5. Se erro: Mostra mensagem e permite corrigir

## Segurança

### Autenticação
- Senhas armazenadas com `password_hash()` (bcrypt, cost=12)
- Timing attack prevention: delay de 1s em credenciais inválidas
- Não expõe se email existe/não existe

### Sessão
- SESSION_NAME definido em `constants.php`
- Cookies: SECURE, HTTPONLY, SAMESITE=Strict
- HTTPS obrigatório em produção (COOKIE_SECURE=true)

### Validação
- Client-side: HTML5 + JavaScript para UX
- Server-side: Validações rigorosas antes de atualizar banco
- Email validation com filter_var
- Força de senha com regex

### Acesso a Dados
- `require_login()` na página de perfil (`minha-conta/index.php`)
- `get_current_user()` busca sempre do banco (não confia em SESSION)
- Logout destrói sessão completamente

## Uso em Páginas

### Proteger página (requer login)
```php
<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth-helpers.php';

require_login(); // Redireciona se não logado

$user = get_current_user();
?>
```

### Verificar se está logado (sem redirecionar)
```php
<?php
if (is_logged_in()) {
    $user = get_current_user();
    echo "Olá, " . $user['name'];
} else {
    echo "Não está logado. <a href='/login'>Fazer login</a>";
}
?>
```

### Mostrar buttons diferentes na navbar
```php
<?php
if (is_logged_in()) {
    $user = get_current_user();
    echo "<a href='/minha-conta'>" . $user['name'] . "</a>";
    echo "<form method='POST' action='/api/logout'><button>Sair</button></form>";
} else {
    echo "<a href='/login'>Entrar</a>";
    echo "<a href='/cadastro'>Criar Conta</a>";
}
?>
```

## APIs JSON

### Check Auth Status
```
GET /api/auth/check

Response (200):
{
  "success": true,
  "logged_in": true,
  "user": {
    "id": 1,
    "name": "João Silva",
    "email": "joao@example.com",
    "phone": "(11) 99999-9999",
    "cpf": "12345678901",
    "created_at": "2026-07-09T10:30:00"
  }
}

Response (logged out):
{
  "success": true,
  "logged_in": false,
  "user": null
}
```

### Logout
```
POST /api/logout

Response (200):
{
  "success": true,
  "message": "Logout realizado com sucesso"
}
```

## Tabela users

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    cpf VARCHAR(14) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_cpf (cpf)
);
```

## Validações de Força de Senha

A senha deve ter:
- **Mínimo 8 caracteres**
- **Pelo menos 1 maiúscula** (A-Z)
- **Pelo menos 1 minúscula** (a-z)
- **Pelo menos 1 número** (0-9)

Exemplo de senhas:
- ✅ Senha123
- ✅ MyPass2024
- ❌ password123 (sem maiúscula)
- ❌ Password1 (muito fraca, apenas 1 número)
- ❌ Pass (muito curta)

## Indicador Visual de Força

Na página de cadastro, há indicador visual:
- **Fraca**: < 33% força (vermelho)
- **Média**: 33-66% força (amarelo)
- **Forte**: > 66% força (verde)

Calcula força baseado em:
- Comprimento (>= 12 chars = +1)
- Maiúsculas
- Minúsculas
- Números
- Caracteres especiais (!@#$%^&*)

## Próximos Passos (Fase 2)

1. **Email de Confirmação**
   - Gerar token UUID
   - Enviar email com link de ativação
   - Marcar user como ativo

2. **Recuperação de Senha**
   - Formulário /recuperar-senha
   - Email com link de reset
   - Validar token e atualizar senha

3. **Autenticação Social**
   - Login com Google/Facebook
   - Integrar com OAuth2

4. **MFA (Multi-Factor Authentication)**
   - Autenticação por SMS
   - TOTP com Google Authenticator

## Troubleshooting

### "Email já cadastrado"
O email fornecido já existe no banco. Use outro email ou faça login.

### "Senha fraca"
A senha não atende aos requisitos. Precisa ter:
- Mínimo 8 caracteres
- 1 maiúscula, 1 minúscula, 1 número

### "Senhas não correspondem"
Confirme que ambas as senhas são idênticas.

### "Email ou senha incorretos"
Email ou senha estão errados. Verifique e tente novamente.
(Sistema não revela se email existe por questão de segurança)

### Sessão expirada
Se ficar inativo por muito tempo, a sessão expira. Faça login novamente.

## Performance

- Queries otimizadas com indexes em `email` e `cpf`
- Password hash com bcrypt (cost=12) é seguro e adequado
- Session storage no servidor (não em banco, por performance)
- Activity logs em tabela separada para auditoria

## Conformidade

- ✅ LGPD: Dados de usuário protegidos
- ✅ HTTPS: Certificado SSL obrigatório
- ✅ WCAG: Forms acessíveis com labels e validações
- ✅ CSRF: Session-based (protegido por SESSION ID)

## Suporte

Para dúvidas sobre o sistema de autenticação:
1. Consulte as funções em `config/auth-helpers.php`
2. Veja exemplos nos arquivos das pages (login, cadastro, minha-conta)
3. Verifique logs de erro em `/logs/`
