# 📊 Roadmap de Implementação - Editor Visual

## ✅ FASE 1 - CONCLUÍDA (12 Blocos Core)

### Infraestrutura
- ✅ BlockInterface - interface padrão
- ✅ BlockRegistry - registro central
- ✅ DynamicRenderer - renderizador JSON→HTML
- ✅ BlockAutoloader - auto-loader de classes
- ✅ LayoutManager - gerenciador de banco (classe pronta)

### Blocos Implementados (15 total)
```
🎯 Marketing
✅ HeroBanner
✅ AnnouncementBar
✅ CountdownTimer
✅ PromoRibbon
✅ ImageCarousel (responsivo, lazy load)

📦 Catálogo
✅ ProductGrid
✅ CategoryCarousel
✅ ProductCarousel

🎨 Design
✅ DynamicSpacer
✅ Divider
✅ CardContainer

🛍️ Produto (NOVO)
✅ ProductTitle
✅ AddToCartButton
✅ ProductReviews

🏛️ Estrutura
✅ GlobalFooter
```

### API Endpoints (NOVO)
```
POST /api/admin/layouts-save.php
  - Salvar layout em JSON (BD pronto)
  - Publicar/Despublicar

GET /api/admin/layouts-list.php
  - Listar todos layouts
  - Metadados (sections, updated)
```

### Editor Melhorado
- ✅ Interface JSON side-by-side
- ✅ Validação em tempo real
- ✅ Preview de estrutura
- ✅ Bloco registry visual

---

## 🔄 FASE 2 - EM PROGRESSO

### 2.1 Banco de Dados MySQL
**Arquivo:** `database/schema-layouts.sql`

Status: **Criado (pronto para executar)**

```sql
-- Executar no MySQL:
mysql -u user -p database < database/schema-layouts.sql
```

Tabelas:
- `page_layouts` — layouts + metadados + publicação
- `page_layouts_history` — histórico de versões

### 2.2 Integração de LayoutManager com BD
**Classe:** `Core\LayoutManager`

Status: **Criada (100% funcional)**

Métodos:
- `save($pageId, $config)` — salvar/atualizar
- `getByPageId($pageId)` — carregar
- `getAll()` — listar
- `publish($pageId)` — publicar
- `duplicate($source, $new)` — duplicar
- `delete($pageId)` — deletar
- `getHistory($pageId)` — ver histórico
- `revertToVersion($pageId, $version)` — reverter
- `export($pageId)` — exportar JSON
- `import($pageId, $json)` — importar JSON

### 2.3 APIs Finalizadas
**Status:** Estrutura pronta, falta conectar BD

```
POST /api/admin/layouts-save.php
  Input: { page_id, config, page_type, viewport, publish }
  Output: { ok, message, published }

GET /api/admin/layouts-list.php
  Output: { ok, count, layouts[] }

GET /api/admin/layouts-get.php?page_id=xxx
  Output: { ok, layout }

DELETE /api/admin/layouts-delete.php?page_id=xxx
  Output: { ok, deleted }

POST /api/admin/layouts-publish.php
  Input: { page_id, publish }
  Output: { ok }
```

---

## 📋 FASE 3 - PRÓXIMA (Drag-and-Drop Visual)

### 3.1 Interface Drag-and-Drop
**Tech Stack:** HTMX + dnd-kit + JavaScript

**Arquivo:** `admin/template-editor-dnd.php` (ainda não criado)

Componentes:
```html
<!-- Painel lateral com blocos disponíveis -->
<div class="block-library">
  <!-- Draggable para cada bloco do BlockRegistry -->
  <div class="block-item" draggable="true" data-type="HeroBanner">
    🖼️ Hero Banner
  </div>
</div>

<!-- Canvas do editor -->
<div class="editor-canvas" id="canvas">
  <!-- Blocos dropáveis aqui -->
</div>

<!-- Painel de propriedades -->
<div class="properties-panel">
  <!-- Inputs dinâmicos baseado no bloco selecionado -->
</div>
```

### 3.2 JavaScript da Interação
```javascript
// dnd-kit para drag-and-drop
// Validação em tempo real
// Sincronização com JSON
// Undo/Redo em Session
```

---

## 🧪 FASE 4 - A/B Testing

### 4.1 Suporte a Múltiplas Versões
**Arquivo:** Estender `page_layouts` com campo `variant`

```json
{
  "page_id": "homepage",
  "variant": "v1",  // ← NEW
  "config": { ... }
}
```

**Tabelas novas:**
- `page_layouts_variants` — variações A/B
- `page_layouts_metrics` — conversão por variante

### 4.2 Editor de Versões
- Criar nova variação (clone)
- Comparar lado-a-lado
- Publicar % de tráfego (10%, 50%, 100%)
- Métri de conversão real-time

---

## 📝 FASE 5 - Histórico Git

### 5.1 Git Version Control
**Abordagem:** Commit automático de layouts ao salvar

```bash
# Criar branch automático por editor
git switch -c editor/homepage-v123

# Ao salvar
git add layouts/
git commit -m "Editor update: [user] changed X sections"

# Botão de histórico mostra git log
```

### 5.2 Recursos
- Reverter via git (não precisa DB)
- Blame: quem mudou o quê
- Diff: visualizar mudanças
- Stash: salvar rascunhos

---

## 📊 Status Geral

| Fase | Feature | Status | % | ETA |
|------|---------|--------|---|-----|
| 1 | Core + 15 blocos | ✅ Completo | 100% | ✓ |
| 1 | Editor JSON | ✅ Completo | 100% | ✓ |
| 2 | Banco MySQL | 🟡 Schema OK | 80% | 1h |
| 2 | LayoutManager | ✅ Código OK | 100% | ✓ |
| 2 | APIs | 🟡 Estrutura OK | 60% | 2h |
| 3 | Drag-and-drop | 🔴 Não iniciado | 0% | 1 dia |
| 4 | A/B Testing | 🔴 Não iniciado | 0% | 2 dias |
| 5 | Git history | 🔴 Não iniciado | 0% | 1 dia |

---

## 🚀 Próximos Passos Imediatos

### HOJE (próxima hora):
1. Criar tabelas MySQL:
   ```bash
   mysql < database/schema-layouts.sql
   ```

2. Conectar APIs ao LayoutManager:
   - Editar `layouts-save.php` para usar `LayoutManager`
   - Editar `layouts-list.php` para usar `LayoutManager`

3. Testar salvamento no BD:
   - Admin → Editor → Salvar
   - Verificar `page_layouts` tabela

### AMANHÃ:
4. Implementar interface drag-and-drop
5. Integrar com blocos visuais

### SEMANA QUE VEM:
6. A/B Testing
7. Git history

---

## 🎯 Checklist de Finalização

- [ ] Banco MySQL criado e conectado
- [ ] APIs salvando no BD
- [ ] Editor carregando layouts do BD
- [ ] Publicação funcionando
- [ ] Drag-and-drop visual pronto
- [ ] A/B Testing ativo
- [ ] Git history integrado
- [ ] Testes end-to-end
- [ ] Documentação atualizada

---

## 📞 Próxima Ação

**Você quer que eu:**
A) Conecte o LayoutManager ao MySQL agora?
B) Implemente o drag-and-drop primeiro?
C) Continue com A/B Testing?

Recomendação: **A** (BD) → **B** (UI) → **C** (Features)

