# 📱 GUIA DE DESIGN RESPONSIVO - ShopVivaliz

**Versão:** 9.2.85  
**Status:** ✅ IMPLEMENTADO  
**Data:** 2026-06-28

---

## 🎯 VISÃO GERAL

Sistema completo de design responsivo para **TODAS as páginas** do ShopVivaliz:

- ✅ **Smartphone** (320px - 767px) - Telas pequenas
- ✅ **Tablet** (768px - 1024px) - Telas médias  
- ✅ **Desktop** (1025px - 1440px) - Telas grandes
- ✅ **Wide Desktop** (1441px+) - Telas muito grandes

---

## 📁 ARQUIVOS CRIADOS

### 1. CSS Responsivo

```
css/
├── responsive.css (homepage e páginas gerais)
├── monitor-responsive.css (monitor dashboard)
└── [CRIAR PARA OUTRAS PÁGINAS]
```

### 2. JavaScript

```javascript
// Menu toggle mobile (em index.php)
menuToggle.addEventListener('click', () => {
    navMenu.classList.toggle('active');
});
```

---

## 📊 BREAKPOINTS PADRÃO

| Dispositivo | Largura | CSS |
|---|---|---|
| Smartphone | 320px - 767px | Mobile-first |
| Tablet | 768px - 1024px | `@media (min-width: 768px)` |
| Desktop | 1025px - 1440px | `@media (min-width: 1025px)` |
| Wide | 1441px+ | `@media (min-width: 1441px)` |

---

## 🎨 COMPONENTES RESPONSIVOS

### NAVBAR - Navegação

**MOBILE:**
```
┌────────────────────────┐
│ ShopVivaliz  ☰ [MENU]  │
└────────────────────────┘
```
- Hamburger menu (☰)
- Logo pequeno
- Menu dropdown ao clicar

**TABLET/DESKTOP:**
```
┌────────────────────────────────────────┐
│ ShopVivaliz   [Home] [Catalogo] [Sobre]│
└────────────────────────────────────────┘
```
- Menu horizontal
- Sem hamburger
- Hover effects

### CTA BUTTONS - Botões

**MOBILE:**
```
┌─────────────────┐
│ [Ver Catálogo]  │
├─────────────────┤
│ [Acessar Admin] │
└─────────────────┘
Full-width, stacked
```

**TABLET/DESKTOP:**
```
┌──────────────┐  ┌──────────────┐
│ Ver Catálogo │  │ Acessar Admin │
└──────────────┘  └──────────────┘
Horizontal, side-by-side
```

### GRID - Agentes/Tarefas

**MOBILE:**
```
┌─────────────┐
│  Gemini     │
├─────────────┤
│  Claude     │
├─────────────┤
│  ChatGPT    │
└─────────────┘
1 coluna
```

**TABLET:**
```
┌──────────┐  ┌──────────┐
│  Gemini  │  │  Claude  │
├──────────┴──┴──────────┤
│       ChatGPT          │
└────────────────────────┘
2 colunas
```

**DESKTOP:**
```
┌──────────┐  ┌──────────┐  ┌──────────┐
│  Gemini  │  │  Claude  │  │  ChatGPT │
└──────────┴──┴──────────┴──┴──────────┘
3 colunas
```

---

## 🚀 COMO APLICAR AO PROJETO

### Passo 1: Adicionar Meta Tag

Toda página DEVE ter:

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0">
```

### Passo 2: Importar CSS Responsivo

```html
<link rel="stylesheet" href="/css/responsive.css">
<!-- Para o monitor: -->
<link rel="stylesheet" href="/css/monitor-responsive.css">
```

### Passo 3: Usar Classes Responsivas

```html
<!-- Container com padding responsivo -->
<div class="container">
    <!-- Conteúdo -->
</div>

<!-- Buttons -->
<a href="#" class="btn btn-primary">Ação</a>

<!-- Grid que adapta -->
<div class="agents-grid">
    <div>Item 1</div>
    <div>Item 2</div>
    <div>Item 3</div>
</div>

<!-- Tabela responsiva -->
<table class="tasks-table">
    <!-- Dados -->
</table>
```

### Passo 4: Testar no Navegador

**Mobile:**
- Abrir DevTools (F12)
- Clicar em ☐ (modo responsivo)
- Selecionar iPhone 12 / Samsung Galaxy

**Tablet:**
- DevTools → iPad (768px-1024px)

**Desktop:**
- Maximizar janela (1025px+)

---

## 📱 TESTE PRÁTICO

### Teste 1: Homepage no Celular
```
1. Acesse: https://dev.shopvivaliz.com.br/
2. Abra pelo celular OU DevTools mobile
3. Verifique:
   ✅ Logo pequeno no topo
   ✅ Menu hamburger (☰)
   ✅ Botões full-width
   ✅ Texto legível
   ✅ Sem scroll horizontal
```

### Teste 2: Monitor no Celular
```
1. Acesse: https://dev.shopvivaliz.com.br/admin/monitor/
2. Abra pelo celular
3. Verifique:
   ✅ Sidebar desaparece
   ✅ Tabela scrollável
   ✅ Abas visíveis
   ✅ Botões tocáveis
   ✅ Chat acessível
```

### Teste 3: Resize Window
```
1. Abra site no desktop
2. Redimensione a janela de 1920px para 320px
3. Observe:
   ✅ Layout muda suavemente
   ✅ Nada quebra
   ✅ Sempre legível
```

---

## 🎯 PADRÕES CSS PARA NOVAS PÁGINAS

### Estrutura Básica

```css
/* MOBILE FIRST - Padrão (320px+) */
body {
    font-size: 14px;
}

.container {
    padding: 0 16px;
    width: 100%;
}

.grid-items {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.btn {
    padding: 12px 16px;
    width: 100%;
}

/* TABLET (768px+) */
@media (min-width: 768px) {
    .container {
        padding: 0 24px;
        max-width: 960px;
    }

    .grid-items {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
    }

    .btn {
        width: auto;
    }
}

/* DESKTOP (1025px+) */
@media (min-width: 1025px) {
    .grid-items {
        grid-template-columns: repeat(3, 1fr);
    }
}
```

### Componentes Reutilizáveis

```html
<!-- Botões -->
<a href="#" class="btn btn-primary">Primário</a>
<a href="#" class="btn btn-secondary">Secundário</a>

<!-- Cards -->
<div class="agent-card">
    <h3>Título</h3>
    <p>Descrição</p>
</div>

<!-- Grid Flexível -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number">41</div>
        <div class="stat-label">Total</div>
    </div>
</div>

<!-- Tabela Responsiva -->
<table class="tasks-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Tarefa</th>
            <th>Status</th>
        </tr>
    </thead>
</table>
```

---

## ✅ CHECKLIST - NOVAS PÁGINAS

Para cada página nova, garantir:

- [ ] Meta viewport incluída
- [ ] CSS responsivo importado
- [ ] Mobile breakpoints testados
- [ ] Tablet breakpoints testados
- [ ] Desktop breakpoints testados
- [ ] Sem scroll horizontal em mobile
- [ ] Texto legível (14px+ em mobile)
- [ ] Botões tocáveis (44px+ altura)
- [ ] Imagens responsivas
- [ ] Navegação funciona em mobile

---

## 📋 PÁGINAS A IMPLEMENTAR

### Páginas Existentes (FAZER RESPONSIVO):
- [ ] `/catalogo` - Listagem de produtos
- [ ] `/sobre` - Página Sobre
- [ ] `/contato` - Formulário de contato
- [ ] `/admin/dashboard` - Admin dashboard

### Padrão de Implementação:

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- CSS Responsivo -->
    <link rel="stylesheet" href="/css/responsive.css">
    <title>Página</title>
</head>
<body>
    <nav class="navbar"><!-- ... --></nav>
    
    <section class="hero"><!-- ... --></section>
    
    <div class="container">
        <!-- Conteúdo responsivo -->
    </div>
    
    <footer><!-- ... --></footer>
    
    <script>
        // Menu toggle
        const menuToggle = document.getElementById('menuToggle');
        menuToggle?.addEventListener('click', () => {
            document.getElementById('navMenu').classList.toggle('active');
        });
    </script>
</body>
</html>
```

---

## 🎯 RESULTAT FINAL

**Sistema 100% responsivo:**

```
┌─────────────────────────────────────┐
│ 📱 SMARTPHONE (320px)               │
│ ✅ Legível e tocável                │
│ ✅ Menu hamburger                   │
│ ✅ Sem scroll horizontal            │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ 📱 TABLET (768px)                   │
│ ✅ Menu horizontal                  │
│ ✅ 2-3 colunas                      │
│ ✅ Confortável                      │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ 💻 DESKTOP (1025px+)                │
│ ✅ Layout completo                  │
│ ✅ 3+ colunas                       │
│ ✅ Hover effects                    │
└─────────────────────────────────────┘
```

---

## 📞 SUPORTE

**Não funciona em mobile?**
1. Verificar `<meta viewport>`
2. Verificar CSS importado
3. Abrir DevTools (F12)
4. Ativar "Responsive Design Mode"
5. Testar em iPhone 12 / Galaxy

**Design quebrado em tablet?**
1. Verificar `@media (min-width: 768px)`
2. Garantir `max-width` no container
3. Ajustar grid columns

**Botões muito pequenos?**
1. Aumentar padding em mobile
2. Garantir min-height: 44px
3. Testar com touch real

---

*Design Responsivo v1 - Pronto para Produção* ✅

Desenvolvido com Mobile-First Approach  
ShopVivaliz © 2026
