# 🔍 AUDITORIA PROFUNDA DE QA SÊNIOR - SHOPVIVALIZ
**Data:** 2026-07-24  
**Auditor:** Engenheiro de QA Sênior + Dev Full-Stack  
**Status:** ⏳ EM PROGRESSO (Fase 1/4 Completa)  
**Nivel de Críticidade:** 🔴 ALTO

---

## 📋 ÍNDICE DE AUDITORIA

### ✅ FASE 1: BACK-END (PHP) & SEGURANÇA
- [1.1 Validação de Inputs](#11-validacao-de-inputs)
- [1.2 Tratamento de Erros](#12-tratamento-de-erros)
- [1.3 Sessões e Cookies](#13-sessoes-e-cookies)
- [1.4 Upload de Arquivos](#14-upload-de-arquivos)

### ⏳ FASE 2: BANCO DE DADOS (SQL/PDO/MySQLi)
### ⏳ FASE 3: NAVEGAÇÃO & FORMULÁRIOS
### ⏳ FASE 4: WEBHOOKS & INTEGRAÇÃO

---

# FASE 1: BACK-END (PHP) & SEGURANÇA

## 1.1 VALIDAÇÃO DE INPUTS

### ✅ PONTO POSITIVO: InputValidator Centralizado Existe

**Arquivo:** `includes/input-validator.php`

**Qualidade:** ⭐⭐⭐⭐⭐ EXCELENTE

**Implementação:**
- ✅ Classe `InputValidator` bem estruturada
- ✅ Métodos para: string, email, integer, float, boolean, URL, phone, enum, money
- ✅ Sanitização de null bytes e caracteres de controle (linhas 70-71)
- ✅ Validação com `filter_var()` para emails, URLs
- ✅ Suporte a min/max length validation
- ✅ Tratamento de erros centralizado

**Código de Referência:**
```php
// Linha 70-71: Sanitização de controle
$value = str_replace("\0", '', $value);
$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
```

---

### 🔴 PROBLEMA CRÍTICO: InputValidator NÃO ESTÁ SENDO USADO

**Severidade:** 🔴 CRÍTICO  
**Impacto:** 50+ arquivos PHP usam `$_POST`, `$_GET`, `$_REQUEST` SEM InputValidator

**Arquivos Afetados (50+):**
```
✗ auth/register.php
✗ auth/login.php  
✗ checkout-v2/index.php
✗ checkout-legacy/index.php
✗ olist/sync-products.php
✗ admin/editar-produto.php
✗ admin/cupons.php
... e 43 mais
```

**Exemplo 1: checkout-v2/index.php (LINHAS 92-101)**

```php
// ❌ PROBLEMA: Sem validação apropriada
$cliente = [
    'nome' => trim((string)($_POST['nome'] ?? '')),          // ❌ trim() mas sem filter_var()
    'email' => trim((string)($_POST['email'] ?? '')),        // ❌ trim() mas sem filter_var()
    'telefone' => trim((string)($_POST['telefone'] ?? '')),  // ❌ trim() mas sem filter_var()
    'endereco' => trim((string)($_POST['endereco'] ?? '')),  // ❌ trim() mas sem filter_var()
    ...
];
$itemsPayload = json_decode((string)($_POST['cart_payload'] ?? '[]'), true); // ❌ json_decode sem validação
$items = is_array($itemsPayload) ? array_values(...) : [];
```

**Risco:** Um atacante pode enviar dados malformados ou sql injection strings.

**Solução Recomendada:**
```php
// ✅ CORRETO: Usar InputValidator
require_once __DIR__ . '/../includes/input-validator.php';

$validator = validator();
$cliente = [
    'nome' => $validator->requireString('nome', 3, 255, 'Nome completo'),
    'email' => $validator->getEmail('email', true),
    'telefone' => $validator->getPhone('telefone'),
    'endereco' => $validator->requireString('endereco', 5, 255, 'Endereço'),
    ...
];

if ($validator->hasErrors()) {
    http_response_code(400);
    echo json_encode(['errors' => $validator->getErrors()]);
    exit;
}
```

---

### 🔴 PROBLEMA: Dados NÃO SANITIZADOS em Email (checkout-v2, linhas 152-160)

**Severidade:** 🔴 CRÍTICO (XSS em Email)  
**Arquivo:** `checkout-v2/index.php`

```php
// ❌ PROBLEMA: Inserindo diretamente em corpo de email SEM sanitização
$body .= "Nome: {$cliente['nome']}\n";     // ❌ Pode conter newlines maliciosos
$body .= "Email: {$cliente['email']}\n";   // ❌ Não validado
$body .= "Telefone: {$cliente['telefone']}\n"; // ❌ Pode conter caracteres maliciosos
```

**Risco:** Email Injection — atacante pode inserir headers HTTP para fazer spam/phishing

**Ataque Exemplo:**
```
nome: John\nBcc: spammer@evil.com
email: admin@example.com\nCc: attacker@evil.com
```

Resultado: Email é enviado para múltiplos destinatários!

**Solução:**
```php
// ✅ CORRETO: Sanitizar valores antes de usar
$body .= "Nome: " . str_replace(["\n", "\r"], "", $cliente['nome']) . "\n";
$body .= "Email: " . str_replace(["\n", "\r"], "", $cliente['email']) . "\n";
```

---

### ⚠️ PROBLEMA MÉDIO: Password Field Não Validado (auth/register.php, linha 82)

**Severidade:** ⚠️ MÉDIO  
**Arquivo:** `auth/register.php`

```php
// ⚠️ RISCO: Senha não sanitizada (apenas comprimento verificado)
$password = $_POST['password'] ?? '';  // ❌ Sem trim(), sem sanitização
```

**Risco:** A senha pode conter caracteres de controle ou unicode exótico

**Solução:**
```php
$password = trim($_POST['password'] ?? '');
// Validações já presentes (linhas 98-102)
if (strlen($password) < 8) {
    $error = 'Senha deve ter pelo menos 8 caracteres';
}
```

---

## 1.2 TRATAMENTO DE ERROS

### ✅ PONTO POSITIVO: Logs Estruturados Existem

**Arquivo:** `config/database.php` (linhas 33-40)

```php
if (DEBUG_MODE) {
    error_log('Database connected successfully');
}
// ... catch
log_error('Database connection failed', ['error' => $e->getMessage()]);
throw new Exception('Banco de dados indisponível. Contate o suporte.');
```

**Qualidade:** ⭐⭐⭐⭐ MUITO BOM

- ✅ Mensagem de erro genérica ao usuário
- ✅ Detalhes técnicos em logs (não expostos)
- ✅ Uso de `log_error()` centralizado

---

### 🔴 PROBLEMA: Caminhos de Arquivo Expostos em Logs

**Severidade:** 🔴 ALTO (Information Disclosure)  
**Arquivo:** `api/webhook-mercadopago.php` (linha 120)

```php
// ❌ PROBLEMA: Detalhes técnicos em log acessível
error_log('[MercadoPago] webhook rejected: invalid signature request=' . 
          substr($requestId, 0, 80) . ' sig_len=' . strlen($signature) . ' data_id=' . substr($dataId, 0, 80));
```

**Risco:** Se logs estiverem acessíveis via web, atacante aprende sobre estrutura interna

**Solução:** Mover logs para arquivo NÃO acessível publicamente

```php
// ✅ CORRETO: Log apenas o essencial, sem expor paths
error_log('[MercadoPago] webhook_rejected: invalid_signature');
```

---

### ⚠️ PROBLEMA MÉDIO: Exceções Não Capturadas em Alguns Lugares

**Severidade:** ⚠️ MÉDIO  
**Arquivo:** `checkout-v2/index.php` (linha 172)

```php
// ⚠️ RISCO: mail() sem try-catch
@mail($adminEmail, $subject, $body, "From: pedidos@shopvivaliz.com.br\n\nContent-Type: text/plain; charset=UTF-8");
```

**Risco:** Se mail falhar, nenhuma notificação ao usuário

**Solução:**
```php
try {
    $mailSent = mail($adminEmail, $subject, $body, [
        'From' => 'pedidos@shopvivaliz.com.br',
        'Content-Type' => 'text/plain; charset=UTF-8'
    ]);
    if (!$mailSent) {
        error_log('Mail delivery failed for order: ' . $pedidoId);
    }
} catch (Throwable $e) {
    error_log('Mail exception: ' . $e->getMessage());
}
```

---

## 1.3 SESSÕES E COOKIES

### ✅ PONTO POSITIVO: CSRF Protection Existe

**Arquivo:** `auth/login.php` (linha 22)

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('auth-login', $_POST['csrf_token'] ?? null)) {
    $error = 'Sua sessão expirou. Recarregue a página e tente novamente.';
}
```

**Qualidade:** ⭐⭐⭐⭐ MUITO BOM

- ✅ Tokens CSRF validados antes de processar POST
- ✅ Mensagem de erro clara
- ✅ Implementado em login, register, checkout-v2

---

### ✅ PONTO POSITIVO: Password Hashing Correto

**Arquivo:** `auth/register.php` (linha 123)

```php
$password_hash = password_hash($password, PASSWORD_BCRYPT);
```

**Qualidade:** ⭐⭐⭐⭐⭐ EXCELENTE

- ✅ Usando `password_hash()` com BCRYPT
- ✅ `password_verify()` em login (linha 43 auth/login.php)
- ✅ Sem storage de senha em plaintext

---

### ⚠️ PROBLEMA: Session Fixation Não Prevenido Completamente

**Severidade:** ⚠️ MÉDIO  
**Arquivo:** `auth/login.php` (linha 45-47)

```php
// ⚠️ RISCO: Sem session_regenerate_id()
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_email'] = $user['email'];
```

**Risco:** Session Fixation — atacante pode forçar usuário a usar SID conhecido

**Solução:**
```php
// ✅ CORRETO: Regenerar session ID após login
session_regenerate_id(true);  // true = deletar session velha
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_email'] = $user['email'];
```

---

### ⚠️ PROBLEMA: Cookies Sem Flags de Segurança

**Severidade:** ⚠️ MÉDIO  
**Arquivo:** `auth/login.php` (não aplicável direto ao login, mas afeta php.ini global)

Verificar se o PHP está configurado com:
```ini
session.cookie_httponly = On      # ✅ Protege contra XSS
session.cookie_secure = On        # ✅ HTTPS only
session.cookie_samesite = "Strict" # ✅ CSRF protection
```

**Recomendação:** Adicionar antes de session_start():
```php
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict'
]);
```

---

## 1.4 UPLOAD DE ARQUIVOS

### ⏳ ANÁLISE PENDENTE

Procurando por upload handling...

---

## 📊 RESUMO FASE 1

| Categoria | Status | Críticos | Médios | Bons |
|-----------|--------|----------|--------|------|
| **Inputs** | ❌ FALHA | 3 | 1 | 1 |
| **Erros** | ⚠️ ALERTA | 1 | 1 | 1 |
| **Sessões** | ⚠️ ALERTA | 0 | 2 | 2 |
| **Upload** | ⏳ TODO | ? | ? | ? |
| **TOTAL** | ❌ FALHA | **4** | **4** | **4** |

---

## 🎯 PROBLEMAS CRÍTICOS ENCONTRADOS - FASE 1

### CRÍTICO 1: InputValidator Não Implementado
- **Arquivo:** 50+ arquivos PHP
- **Impacto:** Sem validação centralizada
- **Status Fixing:** Precisa implementação massiva

### CRÍTICO 2: Email Injection em checkout-v2
- **Arquivo:** `checkout-v2/index.php` linhas 152-160
- **Impacto:** Spam/Phishing via email injection
- **Status Fixing:** ❌ NÃO CORRIGIDO

### CRÍTICO 3: JSON Decode Sem Validação
- **Arquivo:** `checkout-v2/index.php` linha 101
- **Impacto:** Dados malformados podem quebrar sistema
- **Status Fixing:** ❌ NÃO CORRIGIDO

### CRÍTICO 4: Information Disclosure em Logs
- **Arquivo:** `api/webhook-mercadopago.php` linha 120
- **Impacto:** Técnicos expõem IDs e estrutura interna
- **Status Fixing:** ❌ NÃO CORRIGIDO

---

# ⏳ PRÓXIMAS FASES (EM DESENVOLVIMENTO)

## FASE 2: BANCO DE DADOS (SQL/PDO/MySQLi)
- 2.1 Prepared Statements (ZERO SQL injection)
- 2.2 Gestão de Conexões
- 2.3 Transações (BEGIN/COMMIT/ROLLBACK)
- 2.4 Performance & Índices

## FASE 3: NAVEGAÇÃO & FORMULÁRIOS
- 3.1 Rotas amigáveis (.htaccess)
- 3.2 Links e redirecionamentos
- 3.3 Envio de formulários (duplo-clique)
- 3.4 Tokens CSRF

## FASE 4: WEBHOOKS & INTEGRAÇÃO
- 4.1 Validação de Payloads
- 4.2 Assinatura e Segurança
- 4.3 Idempotência
- 4.4 HTTP Status Codes

---

**Relatório Gerado:** 2026-07-24 23:45 UTC  
**Auditor:** Claude Code (QA Sênior)  
**Próximo:** FASE 2 - Banco de Dados
