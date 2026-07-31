# Editor CSS para Admin

Sistema de edição de CSS via Admin para todas as páginas do site.

## 🎯 O que é

Um editor CSS acessível via Admin que permite editar estilos customizados para:
- **Global**: CSS que afeta todas as páginas
- **Por página**: CSS específico de cada página (home, catálogo, produto, etc)

## 🚀 Como integrar

### 1. Adicionar ao `<head>` de cada página

Em cada arquivo PHP (index.php, catalogo.php, produto.php, etc), adicione esta linha no `<head>`:

```php
<?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
```

**Exemplo:**

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Página</title>
    
    <!-- CSS padrão -->
    <link rel="stylesheet" href="/css/style.css">
    
    <!-- CSS customizado do Admin -->
    <?php require_once __DIR__ . '/includes/load-custom-css.php'; ?>
</head>
<body>
    ...
</body>
</html>
```

### 2. Acessar o Editor

- URL: `/admin/css-editor.php`
- Requer login admin
- Página inicial: `Admin > CSS Editor`

## 📁 Estrutura de arquivos

```
storage/css-custom/
├── global.css          ← CSS para todas as páginas
├── index.css           ← CSS específico da home
├── catalogo.css        ← CSS específico do catálogo
├── produto.css         ← CSS específico de produto
├── carrinho.css        ← CSS específico do carrinho
├── checkout.css        ← CSS específico do checkout
├── login.css           ← CSS específico do login
└── admin.css           ← CSS específico do admin
```

## ✨ Funcionalidades

- ✅ Editor de texto com syntax highlighting
- ✅ Salvar CSS customizado
- ✅ Resetar CSS para padrão
- ✅ Gerenciar por página ou global
- ✅ Visualizar arquivo ativo
- ✅ Proteção de autenticação

## 💡 Exemplos de uso

### Global (afeta todas as páginas)

```css
/* storage/css-custom/global.css */
body {
    font-family: 'Arial', sans-serif;
}

a {
    color: #00a699;
}
```

### Página específica (apenas home)

```css
/* storage/css-custom/index.css */
.hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 60px 20px;
}

.banner {
    display: none; /* Ocultar banner na home */
}
```

### Página de produto

```css
/* storage/css-custom/produto.css */
.product-image {
    border: 2px solid #ddd;
    border-radius: 8px;
}

.product-price {
    font-size: 24px;
    color: #28a745;
    font-weight: bold;
}
```

## 🔒 Segurança

- Acesso restrito a usuários autenticados como admin
- Validação de sessão
- Proteção contra manipulação de arquivo

## 🐛 Troubleshooting

**CSS não está aplicando:**

1. Verificar se arquivo `/includes/load-custom-css.php` foi incluído
2. Verificar se arquivo CSS existe em `storage/css-custom/`
3. Limpar cache do navegador (Ctrl+Shift+Delete)
4. Limpar cache Cloudflare se aplicável

**Erro de permissão:**

- Verificar se pasta `storage/css-custom/` existe
- Verificar permissões: `chmod 755 storage/css-custom/`

## 📝 Páginas disponíveis

| Página | Arquivo | Descrição |
|--------|---------|-----------|
| Global | `global.css` | CSS para todas as páginas |
| Home | `index.css` | Página inicial |
| Catálogo | `catalogo.css` | Listagem de produtos |
| Produto | `produto.css` | Detalhe do produto |
| Carrinho | `carrinho.css` | Página do carrinho |
| Checkout | `checkout.css` | Página de checkout |
| Login | `login.css` | Página de login |
| Admin | `admin.css` | Painel administrativo |

## ⚡ Performance

- CSS é carregado inline no `<head>` para evitar requisições extras
- Apenas CSS de páginas ativas são carregados
- Cache de navegador otimizado

---

**Criado em:** 20/07/2026  
**Localização:** `/admin/css-editor.php`
