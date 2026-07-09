# 📝 Editor Visual - Documentação Completa

## 🚀 Acesso Rápido

**URL:** `https://dev.shopvivaliz.com.br/admin/template-editor.php`

Requer autenticação de admin.

---

## 📦 Blocos Disponíveis (12 Core)

### Marketing (4 blocos)
1. **AnnouncementBar** — Barra de promoção no topo
2. **CountdownTimer** — Temporizador de oferta
3. **PromoRibbon** — Faixa colorida entre seções
4. **ImageCarousel** — Carrossel de banners/imagens

### Catálogo (3 blocos)
5. **ProductGrid** — Grade de produtos
6. **CategoryCarousel** — Carrossel de categorias
7. **ProductCarousel** — Carrossel de produtos

### Design (3 blocos)
8. **DynamicSpacer** — Espaço vertical
9. **Divider** — Linha divisória
10. **CardContainer** — Caixa destacada

### Estrutura (2 blocos)
11. **HeroBanner** — Hero section com imagem
12. **GlobalFooter** — Rodapé global

---

## 💡 Como Usar o Editor

### 1. Criar um Novo Layout

```json
{
  "page_id": "minha_pagina",
  "type": "homepage",
  "meta": {
    "title": "Minha Página",
    "description": "Descrição para SEO"
  },
  "sections": [
    {
      "id": "secao_1",
      "type": "HeroBanner",
      "props": {
        "title": "Bem-vindo!",
        "subtitle": "Subtítulo aqui",
        "image": "/images/hero.jpg",
        "cta_text": "Comprar agora",
        "cta_url": "/catalogo"
      },
      "styles": {
        "minHeight": "500px",
        "backgroundColor": "#f5f5f5"
      }
    }
  ]
}
```

### 2. Configurar ImageCarousel com Banners

```json
{
  "id": "banners_01",
  "type": "ImageCarousel",
  "props": {
    "images": [
      "/images/banner-desconto-10.jpg",
      "/images/banner-casa-jardim.jpg"
    ],
    "autoplay": true,
    "interval": 5,
    "responsive": true
  }
}
```

### 3. Salvar Layout

No editor, clique em **"💾 Salvar"** para persistir o layout em JSON.

---

## 📱 Layouts Disponíveis

| Arquivo | Viewport | Uso |
|---------|----------|-----|
| `layouts/homepage-config.json` | Desktop | Homepage para desktop |
| `layouts/homepage-mobile-config.json` | Mobile | Homepage para mobile |
| `layouts/homepage-example.json` | Ambos | Exemplo de referência |

---

## 🎨 Propriedades (Props) por Bloco

### AnnouncementBar
```json
{
  "text": "string - Texto do anúncio",
  "link": "url - Link ao clicar",
  "close_button": "boolean - Mostrar botão de fechar"
}
```

### CountdownTimer
```json
{
  "end_date": "YYYY-MM-DD - Data final",
  "title": "string - Título"
}
```

### ImageCarousel
```json
{
  "images": ["array de URLs"],
  "autoplay": "boolean - Auto-rotação",
  "interval": "number - Segundos entre slides",
  "responsive": "boolean - Layout responsivo"
}
```

### ProductGrid
```json
{
  "columns": "number - 1-6 colunas",
  "limit": "number - Máximo de produtos",
  "category": "string - Filtrar por categoria"
}
```

### HeroBanner
```json
{
  "title": "string - Título H1",
  "subtitle": "string - Subtítulo",
  "image": "url - Background image",
  "cta_text": "string - Botão CTA",
  "cta_url": "url - Link do botão",
  "overlay_opacity": "0-1 - Escurecer imagem"
}
```

---

## 🎯 Estilos Customizáveis

Todos os blocos suportam CSS inline via `styles`:

```json
"styles": {
  "backgroundColor": "#173B63",
  "padding": "20px",
  "borderRadius": "8px",
  "fontSize": "16px",
  "color": "#ffffff",
  "textAlign": "center",
  "margin": "20px 0"
}
```

---

## 📊 Estrutura de Arquivo de Layout

```json
{
  "page_id": "identificador_unico",           // ← Chave primária
  "type": "homepage|catalogo|produto",       // ← Tipo de página
  "viewport": "desktop|mobile|ambos",         // ← (Opcional)
  "meta": {                                    // ← Metadados SEO
    "title": "Título da página",
    "description": "Meta description",
    "og_image": "/images/og.jpg"
  },
  "sections": [                                // ← Array de blocos
    {
      "id": "unico_id",                       // ← ID único
      "type": "NomeBloco",                    // ← Tipo (exato do registro)
      "props": {                              // ← Propriedades do bloco
        "chave": "valor"
      },
      "styles": {                             // ← CSS customizado
        "propriedade": "valor"
      },
      "children": []                          // ← (Futuro) Blocos filhos
    }
  ]
}
```

---

## ✅ Validação de JSON

No editor, clique em **"✓ Validar"** para:
- Verificar se o JSON é válido
- Contar número de seções
- Detectar erros de sintaxe

---

## 🔍 Troubleshooting

### "JSON inválido"
Copie o texto para https://jsonlint.com/ pra encontrar o erro.

### "Bloco não encontrado"
Verifique se o nome do bloco está EXATAMENTE igual (case-sensitive):
```
✓ Correto:  "type": "HeroBanner"
✗ Errado:   "type": "heroBanner" ou "type": "hero_banner"
```

### Imagens não carregam
Confirme o caminho:
```
✓ /images/banner.jpg      (caminho relativo)
✗ images/banner.jpg       (falta /)
✗ https://...             (URLs externas precisam de CORS)
```

---

## 📖 Próximos Passos

- [ ] Banco de dados: Tabela `page_layouts` (MySQL)
- [ ] Drag-and-drop visual: HTMX + dnd-kit
- [ ] Blocos adicionais: ProductTitle, AddToCart, BrowsingFilter
- [ ] Versionamento: Git history de layouts
- [ ] Preview responsive: Simulador desktop/tablet/mobile
- [ ] A/B Testing: Múltiplas versões de layout

---

## 🎓 Exemplos de Uso

### Homepage com Promoção
```bash
# 1. Copiar template de exemplo
cp layouts/homepage-example.json layouts/homepage-config.json

# 2. Editar no admin
https://dev.shopvivaliz.com.br/admin/template-editor.php?layout=homepage

# 3. Modificar props (ex: mudar promoção)
"props": { "text": "NOVO 15% OFF" }

# 4. Salvar
```

### Mobile First
```bash
# Usar homepage-mobile-config.json
# Menos colunas, menos blocos, fonts menores
```

---

## 🚀 Performance

- **Renderização:** ~10ms (renderizador recursivo otimizado)
- **Carregamento:** Lazy load de imagens
- **Blocos vazios:** Não geram HTML (validação inline)
- **Cache:** JSON em arquivo (pronto para DB)

---

## 📞 Suporte

Contato: Agende uma sessão no admin para dúvidas ao vivo.

**Última atualização:** 2026-07-09
