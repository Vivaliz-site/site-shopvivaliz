# 🚫 Prevenção de Wildcards CSS — Guia Permanente

**Último incidente:** 2026-07-11 (10ª vez em 2 dias)  
**Status:** ✅ BLOQUEADO por pre-commit hook

---

## O Problema

Seletores CSS com wildcard (`[class*="..."]`) são **extremamente perigosos** neste projeto porque:

### Exemplo Quebrado
```css
/* ❌ ERRADO - Quebra tudo */
[class*="hero"] {
    background: gradient;
    padding: 20px;
    text-align: center;
}
```

**Por quê quebra?**
- `[class*="hero"]` casa com **QUALQUER classe contendo "hero"**
- Exemplo: `.hero-carousel`, `.hero-slide`, `.hero-content`, `.hero-trust` 
- O estilo é aplicado a **TODOS eles em cascata**
- Resultado: hero, carousel, slides, trust items — tudo quebrado

### Exemplo Correto
```css
/* ✅ CORRETO - Seletores exatos */
.hero {
    background: gradient;
    padding: 20px;
    text-align: center;
}

.hero-carousel {
    /* estilos específicos do carousel */
}

.hero-slide {
    /* estilos específicos do slide */
}
```

---

## 🛡️ Proteção Implementada

### 1. Pre-Commit Hook
Arquivo: `.git/hooks/pre-commit`  
Ação: Bloqueia qualquer commit que tente adicionar wildcards

**Padrões bloqueados:**
- `[class*=` → Wildcard de classe
- `[id*=` → Wildcard de ID
- `[style*=` → Wildcard de atributo style
- `[data-*=` → Wildcard de atributo data

### 2. Instruções para Agentes IA
**Copiado no CHANGELOG.md:**
> "NUNCA usar `[class*="..."]` em CSS deste projeto — o projeto usa nomes de  
> classe compostos (`hero-carousel`, `hero-slide` etc) que colidem com wildcards.  
> Sempre usar seletores exatos."

### 3. Git Guardian Configuração
Se um arquivo CSS conseguir passar pelo hook local, o Git Guardian na CI detectará e bloqueará.

---

## 📋 Checklist para Revisar PRs

Quando revisar uma PR que toca em CSS:

- [ ] Procurar por `[class*=`
- [ ] Procurar por `[id*=`
- [ ] Procurar por `[^.][class*=` (wildcards não prefixados por `.`)
- [ ] Procurar por `, [class` (wildcards em seletores compostos)

### Comando para verificar PR:
```bash
git diff main...SEU_BRANCH -- '*.css' | grep '\[.*\*='
```

Se retornar algo, **rejeite a PR**.

---

## 🔧 Se o Hook Falhar

### Cenário: "Commitei sem perceber o wildcard"

**Opção 1: Desfazer o commit (MELHOR)**
```bash
git reset --soft HEAD~1
# Remova o wildcard do arquivo
git add .
git commit -m "fix: remover wildcards CSS"
```

**Opção 2: Contornar o hook (EMERGÊNCIA APENAS)**
```bash
git commit --no-verify -m "..."
# ⚠️ Isso vai acionar Git Guardian na CI — será bloqueado lá
```

---

## 📊 Histórico de Incidentes

| Data | Causa | Sintoma | PR/Commit |
|------|-------|---------|-----------|
| 2026-07-09 | `[class*="hero"]` | Home com faixas empilhadas | #217 |
| 2026-07-10 | Reintrodução | Categorias quebradas | #226 (fix) |
| 2026-07-11 | Reintrodução (10x) | Skeleton/hero quebrado | Este documento |

---

## ✅ Validação Final

Para confirmar que o hook está funcionando:

```bash
# Tentar adicionar um wildcard
echo ".test { [class*='x'] }" >> css/test.css
git add css/test.css
git commit -m "test" 2>&1 | grep -i "wildcard"
# Deve bloquear ✓
```

---

## 📞 Referências Rápidas

- **CHANGELOG.md** — Lição original (2026-07-09)
- **CSS-WILDCARD-PREVENTION.md** — Este arquivo
- **Padrão seguro:** Sempre usar `.classe-exata`, nunca `[class*=...]`

**Última atualização:** 2026-07-11 23:50  
**Status:** ✅ Bloqueado permanentemente
