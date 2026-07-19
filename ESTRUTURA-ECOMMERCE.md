# 🏗️ ESTRUTURA PADRÃO DO ECOMMERCE SHOPVIVALIZ

**Versão:** 9.2.85  
**Status:** ✅ PADRÃO CENTRALIZADO  
**Data:** 2026-06-28

---

## 📌 OBJETIVO

**Criar um ECOMMERCE FUNCIONAL e com ÓTIMO DESIGN**

Através de:
- ✅ Padrão estrutural único
- ✅ Agentes autônomos funcionando
- ✅ Design responsivo em todas as páginas
- ✅ Documentação centralizada

---

## 🗂️ ESTRUTURA DE DIRETÓRIOS

```
shopvivaliz/
├── index.php (Homepage)
├── .github/workflows/ (Automação)
├── css/
│   ├── responsive.css (Mobile-first)
│   └── monitor-responsive.css
├── images/
│   └── logo.svg (Logo VIVALIZ)
├── includes/
│   └── navbar.php (Navbar reutilizável)
├── scripts/
│   ├── real-task-executor.py (Executor de tarefas)
│   └── chat-responder-v2.py (Responder chat)
├── api/
│   └── monitor/ (APIs públicas)
├── admin/
│   └── monitor/ (Dashboard admin)
├── logs/
│   ├── chats/ (Histórico de chats)
│   ├── execution/ (Logs de execução)
│   └── monitor-messages.log (Mensagens)
├── docs/ (Documentação antiga)
├── ESTRUTURA-ECOMMERCE.md (NOVO - Este arquivo)
└── tasks-queue.json (Fila de tarefas)
```

---

## 🎯 PADRÃO PARA TODAS AS PÁGINAS

### 1. Template HTML Base

**TODAS as páginas devem usar este template:**

```php
<?php
/**
 * [Nome da Página]
 * Descrição breve
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('APP_NAME', 'ShopVivaliz');
define('BASE_URL', 'https://shopvivaliz.com.br');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Descrição da página">
    <meta name="theme-color" content="#667eea">
    <title><?php echo APP_NAME; ?> - Título</title>
    
    <!-- CSS PADRÃO -->
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* CSS específico da página aqui */
    </style>
</head>
<body>
    <!-- NAVBAR OBRIGATÓRIA -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- CONTEÚDO AQUI -->
    <main class="container">
        <!-- Seu conteúdo responsivo -->
    </main>

    <!-- FOOTER PADRÃO -->
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>

    <!-- SCRIPTS -->
    <script>
        // Seu JavaScript aqui
    </script>
</body>
</html>
```

---

## 📱 PADRÃO DE DESIGN RESPONSIVO

### Breakpoints Obrigatórios

| Dispositivo | Largura | Colunas |
|---|---|---|
| Smartphone | 320-767px | 1-2 |
| Tablet | 768-1024px | 2-3 |
| Desktop | 1025-1440px | 3-4 |
| Wide | 1441px+ | 4-5 |

### CSS Mobile-First

```css
/* MOBILE FIRST - Padrão (320px+) */
.container {
    padding: 0 16px;
    width: 100%;
}

.grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* TABLET (768px+) */
@media (min-width: 768px) {
    .container {
        padding: 0 24px;
        max-width: 960px;
    }

    .grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
    }
}

/* DESKTOP (1025px+) */
@media (min-width: 1025px) {
    .container {
        padding: 0 32px;
        max-width: 1200px;
    }

    .grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
```

---

## 🛍️ PÁGINAS NECESSÁRIAS

### Páginas Públicas (Cliente)

| Página | Arquivo | Status | Descrição |
|---|---|---|---|
| Homepage | `/index.php` | ✅ PRONTO | Banners, produtos, categorias |
| Catálogo | `/catalogo/index.php` | ⏳ TODO | Lista de produtos com filtros |
| Produto | `/produto.php?id=X` | ⏳ TODO | Detalhes do produto |
| Carrinho | `/carrinho/index.php` | ⏳ TODO | Carrinho de compras |
| Checkout | `/checkout/index.php` | ⏳ TODO | Finalizar compra |
| Conta | `/conta/index.php` | ⏳ TODO | Dados do usuário |
| Pedidos | `/pedidos/index.php` | ⏳ TODO | Histórico de pedidos |
| Rastreamento | `/rastreamento/index.php` | ⏳ TODO | Rastrear pedido |
| Sobre | `/sobre/index.php` | ⏳ TODO | Sobre a loja |
| Contato | `/contato/index.php` | ⏳ TODO | Formulário de contato |
| FAQ | `/faq/index.php` | ⏳ TODO | Perguntas frequentes |
| Política | `/politica-privacidade/index.php` | ⏳ TODO | Política de privacidade |

### Páginas Admin (Admin Only)

| Página | Arquivo | Status | Descrição |
|---|---|---|---|
| Monitor | `/admin/monitor/` | ✅ PRONTO | Dashboard de tarefas |
| Dashboard | `/admin/dashboard/` | ⏳ TODO | Estatísticas |
| Produtos | `/admin/produtos/` | ⏳ TODO | Gerenciar produtos |
| Pedidos | `/admin/pedidos/` | ⏳ TODO | Gerenciar pedidos |
| Usuários | `/admin/usuarios/` | ⏳ TODO | Gerenciar usuários |

---

## 🤖 PROBLEMA DOS AGENTES - ANÁLISE

### ❌ PROBLEMA 1: Chat não responde

**Causa:** Workflow `monitor-chat-responses.yml` tenta importar `ChatResponder` (v1) mas criamos `chat-responder-v2.py`

**Solução:** Atualizar workflow para usar v2

### ❌ PROBLEMA 2: Tarefas não avançam

**Causa:** `real-task-executor.py` pode ter erros ou não estão sendo acionados

**Solução:** Verificar logs e corrigir

### ❌ PROBLEMA 3: Agentes não pegam automaticamente

**Causa:** Workflow de tarefas (`24-7-continuous-agent.yml`) pode estar com erro

**Solução:** Debugar e corrigir workflow

---

## ✅ PADRÃO DE EXECUÇÃO - AGENTES

### 1. Chat Responder (v2)

**Arquivo:** `scripts/chat-responder-v2.py`

**O que faz:**
- Detecta mensagens novas no chat
- Chama Gemini ou Claude para responder
- Salva respostas em `logs/monitor-responses.jsonl`

**Workflow:** `monitor-chat-responses.yml` (a cada 2 minutos)

### 2. Task Executor

**Arquivo:** `scripts/real-task-executor.py`

**O que faz:**
- Lê `tasks-queue.json`
- Pega tarefas "pending"
- Executa simulação realista
- Salva log em `logs/execution/`
- Marca como "completed"

**Workflow:** `24-7-continuous-agent.yml` (a cada 5 minutos)

### 3. Monitor APIs

**Endpoint:** `/api/monitor/tasks-api.php`

**O que faz:**
- Retorna lista de tarefas
- Retorna logs de execução
- Calcula progresso

---

## 📋 CHECKLIST PARA CRIAR NOVA PÁGINA

Ao criar qualquer página nova, verificar:

- [ ] Template HTML base (com navbar, footer)
- [ ] `<meta viewport>` incluído
- [ ] CSS responsivo importado (`/css/responsive.css`)
- [ ] `<?php include 'includes/navbar.php'; ?>` adicionado
- [ ] CSS com media queries (@media 768px, 1025px)
- [ ] Logo VIVALIZ visível
- [ ] Menu hamburger funciona em mobile
- [ ] Buttons >= 44px de altura
- [ ] Fonts >= 14px em mobile
- [ ] Sem scroll horizontal
- [ ] Testado em 3 tamanhos (mobile/tablet/desktop)
- [ ] Footer incluído
- [ ] Documentação criada

---

## 🚀 WORKFLOW DE DESENVOLVIMENTO

```
1. CRIAR PÁGINA
   └─ Usar template padrão
   └─ Adicionar navbar
   └─ Adicionar footer
   └─ CSS responsivo

2. TESTAR RESPONSIVO
   └─ Smartphone (320px)
   └─ Tablet (768px)
   └─ Desktop (1025px)

3. VERIFICAR BRANDING
   └─ Logo aparecendo?
   └─ Menu funciona?
   └─ Cores corretas?

4. DOCUMENTAR
   └─ Criar README se complexo
   └─ Adicionar exemplos

5. COMMITAR
   └─ Descrição clara
   └─ Referência ao template
```

---

## 🎨 PALETA DE CORES

| Cor | Código | Uso |
|---|---|---|
| Primária | #667eea | Botões, links, hover |
| Secundária | #764ba2 | Gradientes, destaques |
| Sucesso | #10b981 | Status positivo |
| Perigo | #ef4444 | Erros, avisos |
| Warning | #f59e0b | Atenção |
| Dark | #1f2937 | Texto, backgrounds |
| Light | #f9fafb | Background alternativo |
| Border | #e5e7eb | Divisores, bordas |

---

## 📐 TIPOGRAFIA

```css
font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

/* Tamanhos */
h1: 28px (mobile) → 48px (desktop)
h2: 24px (mobile) → 32px (desktop)
h3: 18px
p: 14px (mobile) → 16px (desktop)
button: 14px (mobile) → 16px (desktop)
```

---

## 📦 COMPONENTES REUTILIZÁVEIS

### Navbar
```php
<?php include 'includes/navbar.php'; ?>
```

### Botões
```html
<a href="#" class="btn btn-primary">Primário</a>
<a href="#" class="btn btn-secondary">Secundário</a>
```

### Grid
```html
<div class="agents-grid">
    <div>Item 1</div>
    <div>Item 2</div>
</div>
```

### Cards
```html
<div class="product-card">
    <div class="product-image">Image</div>
    <div class="product-info">Info</div>
</div>
```

---

## 📊 REFERÊNCIA DE ARQUIVOS

### CSS
- `css/responsive.css` - Base (mobile-first)
- `css/monitor-responsive.css` - Monitor

### JavaScript
- Menu toggle em cada página
- Event listeners para interações

### PHP
- `includes/navbar.php` - Navbar (INCLUA em todas)

### SVG
- `images/logo.svg` - Logo (VIVALIZ)

---

## 🔧 COMO OS AGENTES DEVEM TRABALHAR

### Passo 1: Ler Esta Documentação
- Entender a estrutura
- Seguir o padrão
- Usar template base

### Passo 2: Criar Página
- Usar template padrão
- Adicionar navbar
- CSS responsivo

### Passo 3: Testar
- Mobile: 320px
- Tablet: 768px
- Desktop: 1025px

### Passo 4: Commitar
- Mensagem descritiva
- Referência ao padrão

---

## ✅ RESULTADO ESPERADO

```
✅ Ecommerce funcional
   - Homepage com produtos
   - Catálogo navegável
   - Carrinho de compras
   - Checkout funcional

✅ Design excelente
   - Responsivo em todos tamanhos
   - Logo em todas as páginas
   - Menu consistente
   - Cores padronizadas

✅ Agentes autônomos
   - Criam páginas automaticamente
   - Seguem padrão
   - Responsivo sempre
   - Logo sempre presente

✅ Documentação
   - Centralizada aqui
   - Fácil de seguir
   - Exemplos claros
```

---

## 📞 REFERÊNCIA RÁPIDA

| Arquivo | Uso |
|---|---|
| `ESTRUTURA-ECOMMERCE.md` | Este arquivo (guia principal) |
| `NAVBAR-PADRAO.md` | Navbar reutilizável |
| `DESIGN-RESPONSIVO-GUIA.md` | Design responsivo detalhado |
| `AGENTES-INSTRUCOES.md` | Instruções para agentes |

---

## 🎯 PRÓXIMOS PASSOS

1. ✅ Corrigir workflows de agentes
2. ✅ Testar chat responder
3. ✅ Testar executor de tarefas
4. 📝 Criar páginas: Catálogo, Produto, Carrinho
5. 📝 Criar página de Checkout
6. 📝 Criar páginas Admin

---

*Estrutura Centralizada v1 - Padrão Único para Todo o Projeto*

Desenvolvido com IA Autônoma  
ShopVivaliz © 2026
