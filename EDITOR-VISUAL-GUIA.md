# 🎨 Editor Visual - Guia Completo

## O que foi criado

Um **painel de administração visual** completo para gerenciar o layout do site sem mexer em código.

---

## 🚀 Como Acessar

1. **URL:** `http://localhost:8000/admin/visual-editor.php`
2. **Navegue até:** `/admin/visual-editor.php`

---

## ✨ Features Principais

### 1. **Banners Deslizantes** 🎭
- Carousel automático de banners na página inicial
- Transição a cada 5 segundos
- Clique nos dots para navegar manualmente
- Ative/desative banners no painel de controle

### 2. **Arraste Categorias** 🖱️
- **Arraste visual:** Clique e arraste categorias na área de preview
- **Controle remoto:** Use o painel lateral para ordenar
- **Visibilidade:** Checkbox para mostrar/esconder categorias
- **Sincronização:** Automática entre visual e painel

### 3. **Configuração de Produtos** ⚙️
- Itens por página (4-20)
- Auto-play do carrossel
- Intervalo de auto-play (1000ms+)

### 4. **Preview em Tempo Real** 👀
- Veja as mudanças enquanto edita
- Banners, categorias e configurações
- Sem reload necessário (durante edição)

---

## 📋 Como Usar

### Reposicionar Categorias

**Método 1: Arrastar na preview**
1. Na área esquerda (preview), veja as categorias
2. Clique e arraste uma categoria
3. Solte no local desejado
4. A ordem atualiza automaticamente no painel

**Método 2: Painel de controle**
1. No painel direito, veja a lista "Ordem e Visibilidade"
2. Cada categoria pode ser checkbox (visível/invisível)
3. A ordem é controlada pelo visual (drag)

### Gerenciar Banners

1. **Painel → Seção "Banners"**
2. Veja a lista de banners com checkboxes
3. ☑️ = Visível / ☐ = Oculto
4. Para editar imagens, edite diretamente em:
   - `config/layout-config.json` (campo `image`)
   - Use URL ou base64

### Configurar Produtos

1. **Painel → Seção "Produtos"**
2. **Itens por Página:** Digite número (4-20)
3. **Auto-play:** Toggle (on/off)
4. **Intervalo:** Tempo em milissegundos (1000 = 1 segundo)

---

## 💾 Salvando Alterações

### Método 1: Botão no Header
- Clique em **"💾 Salvar Alterações"** (canto superior direito)

### Método 2: Botão no Painel
- Clique em **"💾 Salvar Tudo"** (painel direito)

### O que acontece ao salvar?
1. Configuração é salva em `config/layout-config.json`
2. Página recarrega automaticamente
3. Mensagem ✅ confirma sucesso

---

## 📁 Arquivos Criados

### `admin/visual-editor.php`
- Painel visual principal
- HTML + CSS + JavaScript embarcado
- Drag-and-drop implementado
- Preview automático

### `includes/layout-loader.php`
- Funções helper para carregar configuração
- Exporta funções:
  - `sv_get_layout_config()` - Configuração completa
  - `sv_get_active_banners()` - Banners ativos
  - `sv_get_visible_categories()` - Categorias visíveis
  - `sv_get_categories_order()` - Ordem das categorias
  - `sv_get_products_config()` - Config de produtos

### `config/layout-config.json` (gerado automaticamente)
```json
{
  "banners": [
    {
      "id": "banner-1",
      "title": "Banner 1",
      "image": "URL_DA_IMAGEM",
      "link": "#",
      "active": true
    }
  ],
  "categories": {
    "order": ["utilidades", "ferramentas", ...],
    "visible": ["utilidades", "ferramentas", ...]
  },
  "products": {
    "itemsPerPage": 8,
    "autoPlay": true,
    "autoPlayInterval": 5000
  }
}
```

---

## 🎯 Casos de Uso

### Caso 1: Alterar ordem de categorias
1. Abra editor visual
2. Arraste categorias na preview
3. Clique "Salvar Alterações"
4. ✅ Ordem atualizada no site

### Caso 2: Esconder uma categoria
1. Vá ao painel → "Ordem e Visibilidade"
2. Desmarque o checkbox da categoria
3. Clique "Salvar Tudo"
4. ✅ Categoria desaparece do site

### Caso 3: Alterar número de produtos
1. Vá ao painel → "Produtos"
2. Altere "Itens por Página" para 12
3. Clique "Salvar Tudo"
4. ✅ Grid agora mostra 12 produtos

### Caso 4: Trocar imagem do banner
1. Edite `config/layout-config.json`
2. Altere campo `image` com nova URL
3. Recarregue a página
4. ✅ Novo banner aparece

---

## ⚙️ Integração com Index.php

Para usar as configurações no site principal, adicione ao `index.php`:

```php
<?php
require_once __DIR__ . '/includes/layout-loader.php';

// Obter configuração
$config = sv_get_layout_config();
$activeBanners = sv_get_active_banners();
$visibleCategories = sv_get_visible_categories();
$categoriesOrder = sv_get_categories_order();
$productsConfig = sv_get_products_config();
?>
```

---

## 🔄 Restaurar Padrão

**Botão:** "🔄 Restaurar Padrão" (painel direito, em vermelho)

⚠️ **Cuidado:** Isso apaga a configuração personalizada e volta às definições padrão!

---

## 🎨 Personalizações Avançadas

### Adicionar novo banner
1. Edite `config/layout-config.json`
2. Adicione objeto em `banners`:
```json
{
  "id": "banner-3",
  "title": "Novo Banner",
  "image": "URL_DA_IMAGEM",
  "link": "https://seu-link.com",
  "active": true
}
```
3. Recarregue o editor

### Adicionar nova categoria
1. Edite `index.php` → função `sv_home_category_icon()`
2. Adicione mapeamento de emoji
3. No editor, a nova categoria aparecerá automaticamente

---

## 📱 Responsividade

O editor funciona em:
- ✅ Desktop (1920px+)
- ✅ Tablet (768px+)
- ✅ Mobile (320px+)

O painel se adapta automaticamente ao tamanho da tela.

---

## 🆘 Troubleshooting

### Editor não carrega
- Verifique permissões da pasta `config/`
- Certifique-se que `/admin/` existe

### Alterações não salvam
- Verifique se `config/layout-config.json` tem permissão de escrita
- Abra console (F12) e procure por erros

### Categorias não atualizam
- Recarregue a página (`Ctrl+R` ou `Cmd+R`)
- Limpe cache do navegador (`Ctrl+Shift+Delete`)

### Banners não aparecem
- Verifique URL das imagens em `layout-config.json`
- Use URLs absolutas ou data: URIs

---

## 📊 Estrutura Visual

```
┌─────────────────────────────────────────────────────────────┐
│                    HEADER COM BOTÃO SALVAR                  │
├──────────────────────────────────────┬──────────────────────┤
│                                      │                      │
│      PREVIEW AREA                    │  CONTROL PANEL       │
│  (Banners + Categorias)              │  (Configurações)     │
│  (Drag-and-drop aqui)                │  (Checkboxes)        │
│                                      │  (Inputs)            │
│                                      │                      │
│                                      │  [Salvar] [Reset]    │
└──────────────────────────────────────┴──────────────────────┘
```

---

## 🚀 Próximos Passos

1. **Abra o editor:** `/admin/visual-editor.php`
2. **Experimente:** Arraste uma categoria
3. **Salve:** Clique em "💾 Salvar Alterações"
4. **Veja:** Retorne ao site e verifique as mudanças

---

**Editor criado em:** 2026-07-09  
**Status:** ✅ Pronto para uso  
**Suporte:** Verifique troubleshooting acima ou edite `config/layout-config.json` manualmente
