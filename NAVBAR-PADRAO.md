# 🎨 NAVBAR PADRÃO COM LOGO - Guia de Uso

**Status:** ✅ IMPLEMENTADO  
**Logo:** VIVALIZ (Carrinho + Checkmark)  
**Aplicável:** TODAS as páginas do projeto

---

## 📌 REGRA OBRIGATÓRIA

**TODA página HTML deve incluir a NAVBAR com LOGO do ShopVivaliz**

---

## 🚀 COMO USAR

### Opção 1: USAR INCLUDE PHP (RECOMENDADO)

Crie sua página normalmente e adicione isto LOGO APÓS `<body>`:

```php
<?php include 'includes/navbar.php'; ?>

<!-- Seu conteúdo aqui -->
```

**Exemplo de página completa:**

```php
<?php
// Seu código PHP aqui
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/responsive.css">
    <title>Página</title>
</head>
<body>
    <!-- ADICIONE ISTO -->
    <?php include 'includes/navbar.php'; ?>
    <!-- FIM DO INCLUDE -->

    <!-- Seu conteúdo aqui -->
    <section class="container">
        <h1>Bem-vindo</h1>
    </section>

    <footer>
        <!-- Footer -->
    </footer>
</body>
</html>
```

---

## 📁 ARQUIVOS

| Arquivo | Descrição |
|---|---|
| `includes/navbar.php` | Navbar com logo (INCLUA em todas as páginas) |
| `images/logo.svg` | Logo do ShopVivaliz (SVG vetorizado) |
| `/css/responsive.css` | CSS da navbar (já importado) |

---

## ✨ CARACTERÍSTICAS DA NAVBAR

✅ **Logo VIVALIZ** no topo  
✅ **Menu responsivo** (hamburger em mobile)  
✅ **Links principais:**
- Home
- Catálogo
- Sobre
- Contato
- Carrinho (com ícone 🛒)

✅ **Totalmente responsiva** (320px - 2560px)  
✅ **Mobile-friendly** (hamburger menu)  
✅ **Clique na logo** volta para home

---

## 🎯 PÁGINAS QUE PRECISAM DA NAVBAR

- [x] Homepage (`/index.php`) ✅ JÁ TEM
- [ ] Catálogo (`/catalogo/index.php`)
- [ ] Sobre (`/sobre/index.php`)
- [ ] Contato (`/contato/index.php`)
- [ ] Carrinho (`/carrinho/index.php`)
- [ ] Checkout (`/checkout/index.php`)
- [ ] Conta do usuário (`/conta/index.php`)
- [ ] Rastreamento (`/rastreamento/index.php`)
- [ ] FAQ (`/faq/index.php`)
- [ ] Política de Privacidade (`/politica-privacidade/index.php`)

---

## 📝 TEMPLATE PADRÃO PARA NOVAS PÁGINAS

Use este template para criar TODAS as novas páginas:

```php
<?php
/**
 * [Nome da Página]
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_NAME', 'ShopVivaliz');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Descrição da página">
    <title>Nome da Página - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- NAVBAR COM LOGO (OBRIGATÓRIO) -->
    <?php include 'includes/navbar.php'; ?>

    <!-- CONTEÚDO DA PÁGINA -->
    <section class="container">
        <!-- Seu conteúdo aqui -->
    </section>

    <!-- FOOTER -->
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>
```

---

## 🎨 PERSONALIZAÇÕES

### Alterar links do menu

Edite `includes/navbar.php` e modifique:

```php
<a href="/">Home</a>
<a href="/catalogo">Catálogo</a>
<a href="/sobre">Sobre</a>
<a href="/contato">Contato</a>
<a href="/carrinho">🛒 Carrinho</a>
```

### Alterar altura da logo

Na navbar, altere `height: 50px`:

```php
<img src="/images/logo.svg" alt="ShopVivaliz" style="height: 50px; width: auto;">
```

Valores recomendados:
- Mobile: 40px
- Desktop: 50px

---

## 🔍 VERIFICAÇÃO

Toda página deve ter:

- [x] `<meta viewport>`
- [x] `<?php include 'includes/navbar.php'; ?>`
- [x] CSS responsivo importado
- [x] Logo visível no topo
- [x] Menu funciona em mobile
- [x] Links funcionam

**Sem algum desses? Página está incompleta!**

---

## 📱 TESTE NO BROWSER

```
1. Abra página no desktop
2. Navegação com logo aparece no topo ✅
3. Clique na logo - volta para home ✅
4. Redimensione para 320px (mobile)
5. Menu vira hamburger (☰) ✅
6. Clique no hamburger - menu abre ✅
7. Clique em um link - menu fecha ✅
```

---

## ⚠️ REGRA CRÍTICA

**NENHUMA página do ShopVivaliz pode ir para produção SEM a NAVBAR com LOGO!**

Se esqueceu de adicionar: `<?php include 'includes/navbar.php'; ?>`

A página será REJEITADA! 🚫

---

## 🤖 INSTRUÇÃO PARA AGENTES

**Todos os agentes (Gemini, Claude, ChatGPT):**

Ao criar qualquer página HTML/PHP nova, OBRIGATORIAMENTE:

1. ✅ Adicionar `<meta viewport>`
2. ✅ Importar `/css/responsive.css`
3. ✅ **ADICIONAR:** `<?php include 'includes/navbar.php'; ?>`
4. ✅ Testar navbar em mobile e desktop
5. ✅ Verificar logo aparecendo
6. ✅ Testar menu hamburger

**Sem navbar com logo = TAREFA REJEITADA** 🚫

---

*Navbar Padrão v1 - Aplicável a TODAS as páginas*

Desenvolvido com branding do ShopVivaliz  
Logo VIVALIZ © 2026
