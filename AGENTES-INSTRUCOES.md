# 🤖 INSTRUÇÕES OBRIGATÓRIAS PARA AGENTES IA

**Gemini | Claude | ChatGPT**

---

## 📌 REGRA #1: DESIGN RESPONSIVO OBRIGATÓRIO

### ⚠️ CRÍTICO - LEIA COM ATENÇÃO

**TODA página HTML deve possuir AMBAS as versões:**

✅ **Desktop** (1025px+)  
✅ **Smartphone** (320px-767px)

---

## 🎯 COMO FAZER

### 1️⃣ ESTRUTURA HTML PADRÃO

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/responsive.css">
    <title>Página</title>
</head>
<body>
    <!-- Conteúdo -->
</body>
</html>
```

**OBRIGATÓRIO:**
- `<meta name="viewport">` ✅
- Importar `/css/responsive.css` ✅

### 2️⃣ CSS COM MEDIA QUERIES

```css
/* MOBILE FIRST - Padrão (320px+) */
.container {
    padding: 0 16px;
    font-size: 14px;
}

.button {
    width: 100%;
    padding: 12px 16px;
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

    .button {
        width: auto;
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

### 3️⃣ TESTAR EM AMBOS OS TAMANHOS

Antes de marcar tarefa como **COMPLETA**, OBRIGATORIAMENTE testar e validar visualmente via browser:

```bash
# 1. SMARTPHONE (DevTools / Browser Subagent)
F12 → Responsive Design Mode → iPhone 12 (ou viewport 375x812)
- Verificar sem scroll horizontal
- Botões tocáveis
- Texto legível
- Tirar screenshot do resultado mobile para comprovação

# 2. DESKTOP (Janela maximizada / Browser Subagent)
- Layout correto
- Hover effects funcionam
- Espaçamento adequado
- Tirar screenshot do resultado desktop para comprovação

# 3. VALIDAÇÃO AUTOMATIZADA
- Utilizar ferramenta de browser do agente (ex: playwright) para abrir a página e registrar evidência visual.
```

---

## 🚫 O QUE NÃO FAZER

❌ Criar página só para desktop  
❌ Ignorar `<meta viewport>`  
❌ Usar `width: 100vw` (causa scroll)  
❌ Fonte menor que 14px em mobile  
❌ Botões menores que 44px  
❌ Layout fixo sem breakpoints  

---

## 📋 CHECKLIST ANTES DE FINALIZAR TAREFA

- [ ] Página tem `<meta viewport>`?
- [ ] CSS responsivo importado?
- [ ] Testado em smartphone (320px)?
- [ ] Testado em tablet (768px)?
- [ ] Testado em desktop (1025px)?
- [ ] Sem scroll horizontal em mobile?
- [ ] Buttons min 44px em mobile?
- [ ] Fonts legíveis em mobile (14px+)?
- [ ] Grid/tabelas adaptam?
- [ ] Menu funciona em mobile?
- [ ] **Validou visualmente abrindo no browser (gerou screenshot de evidência)?**

**Nenhum "SIM" ✅? NÃO marque como completo!**

---

## 📁 REFERÊNCIA RÁPIDA

| Arquivo | Descrição |
|---|---|
| `/css/responsive.css` | CSS base para todas as páginas |
| `/css/monitor-responsive.css` | CSS para monitor dashboard |
| `DESIGN-RESPONSIVO-GUIA.md` | Guia completo e padrões |
| `index.php` | Exemplo de página responsiva |

---

## 🎯 EXEMPLOS CORRETOS

### ✅ Página Responsiva Correta

```html
<!-- CORRETO -->
<div class="container">
    <h1>Título</h1>
    <div class="agents-grid">
        <div class="agent-card">Gemini</div>
        <div class="agent-card">Claude</div>
        <div class="agent-card">ChatGPT</div>
    </div>
</div>

<!-- CSS -->
.agents-grid {
    display: flex;
    flex-direction: column; /* Mobile = 1 coluna */
}

@media (min-width: 768px) {
    .agents-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr); /* Tablet = 2 */
    }
}

@media (min-width: 1025px) {
    .agents-grid {
        grid-template-columns: repeat(3, 1fr); /* Desktop = 3 */
    }
}
```

### ❌ Página Não-Responsiva (REJEITADA)

```html
<!-- ERRADO -->
<div style="width: 1200px; margin: 0 auto;">
    <!-- Não funciona em mobile! -->
</div>

<!-- ERRADO -->
<div class="container">
    <div style="display: grid; grid-template-columns: repeat(3, 1fr);">
        <!-- Fica quebrado em mobile! -->
    </div>
</div>
```

---

## 🔄 WORKFLOW OBRIGATÓRIO

```
1. CRIAR página HTML
2. ADICIONAR <meta viewport>
3. IMPORTAR /css/responsive.css
4. ESCREVER CSS com @media queries
5. TESTAR smartphone (F12 mode)
   ✅ Sem scroll horizontal?
   ✅ Texto legível?
   ✅ Botões tocáveis?
6. TESTAR tablet (768px)
   ✅ Layout correto?
7. TESTAR desktop (1025px)
   ✅ Layout completo?
8. MARCAR COMPLETO ✅
```

**Pular algum passo = TAREFA REJEITADA**

---

## 📞 DÚVIDAS FREQUENTES

**P: "Posso usar frameworks como Bootstrap?"**  
R: Só se incluir `<meta viewport>`. Mas preferimos CSS puro (mais leve).

**P: "Quantas colunas em cada tamanho?"**  
R: Mobile=1, Tablet=2-3, Desktop=3+. Depende do componente.

**P: "Preciso testar em TODOS os celulares?"**  
R: Não. Testar em iPhone (Safari) e Samsung (Chrome). Representam 95% do uso.

**P: "E se ficar muito pequeno em mobile?"**  
R: Aumentar `padding`, `font-size`, `gap`. Espaço é ouro em mobile.

---

## ✅ RESULTADO ESPERADO

Após seguir estas instruções:

```
┌──────────────────────────────────┐
│  SMARTPHONE (320px)              │
│  ✅ Legível e tocável             │
│  ✅ Sem quebras                   │
│  ✅ Menu funciona                 │
└──────────────────────────────────┘

┌──────────────────────────────────┐
│  DESKTOP (1920px)                │
│  ✅ Layout profissional           │
│  ✅ Hover effects                 │
│  ✅ Espaçamento perfeito          │
└──────────────────────────────────┘
```

---

## 🚨 LEMBRETE FINAL

**TODA página criada deve passar OBRIGATORIAMENTE EM:**

1. ✅ Teste smartphone
2. ✅ Teste tablet
3. ✅ Teste desktop

**Sem isso, tarefa é REJEITADA.**

---

*Instruções Obrigatórias v1 - Aplicável a TODAS as tarefas*

Desenvolvido por Trio IA  
ShopVivaliz © 2026
