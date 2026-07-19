# 🚀 SHOPVIVALIZ.COM.BR - CONFIGURAÇÃO PRODUÇÃO
**Data:** 2026-07-19  
**Status:** Parcialmente Concluído

---

## ✅ COMPLETO (100%)

### 1. **DNS Migration via Cloudflare API**
- ✅ `www.shopvivaliz.com.br` → CNAME `shopvivaliz.com.br` (Proxied)
- ✅ Raiz `shopvivaliz.com.br` → A `137.131.156.17` VM Oracle (Proxied)
- ✅ Cloudflare credentials salvos em `.env`
- **Arquivo:** `.env` (local + servidor)

### 2. **Google Analytics**
- ✅ Conta "Shopvivaliz" já existe
- ✅ Dados sendo coletados (17 usuários, 138 eventos)
- **Próximo:** Verificar se tracking code está no `<head>` do site

---

## ⚠️ DETALHES INFRA QUE PODEM ESTAR ESQUECIDOS

### **Analytics Tracking Code** (Crítico - sem isso GA não coleta)
**Status:** ❓ Verificar  
**Onde:** `<head>` da página principal (index.php ou layout.php)

```html
<!-- Google Analytics Code (GA-4) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXX'); <!-- Substituir G-XXXXX pelo ID real -->
</script>
```

**Como encontrar ID:** Google Analytics → Account Settings → Property → Data Streams → Web → Google tag ID

---

### **robots.txt**
**Status:** ❓ Verificar  
**Localização:** `/public_html/robots.txt` (raiz)

```
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/private/
Disallow: /tmp/

Sitemap: https://shopvivaliz.com.br/sitemap.xml
```

---

### **SSL/HTTPS Certificate**
**Status:** ✅ Cloudflare está proxiando (SSL automático)  
**Verificar:**
```bash
curl -I https://shopvivaliz.com.br  # Deve ter "200 OK"
```

---

### **Open Graph / Meta Tags (Compartilhamento Redes Sociais)**
**Onde:** `<head>` de cada página

```html
<meta property="og:title" content="ShopVivaliz - Loja Online">
<meta property="og:description" content="Roupas de qualidade com melhor preço">
<meta property="og:image" content="https://shopvivaliz.com.br/images/logo.png">
<meta property="og:url" content="https://shopvivaliz.com.br">
<meta property="og:type" content="website">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="ShopVivaliz">
<meta name="twitter:description" content="Roupas de qualidade com melhor preço">
<meta name="twitter:image" content="https://shopvivaliz.com.br/images/logo.png">
```

---

### **Schema.org / JSON-LD (Dados Estruturados)**
**Onde:** `<head>` ou antes de `</body>`

**Para Loja:**
```json
<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "Organization",
  "name": "ShopVivaliz",
  "url": "https://shopvivaliz.com.br",
  "logo": "https://shopvivaliz.com.br/images/logo.png",
  "contactPoint": {
    "@type": "ContactPoint",
    "contactType": "Customer Service",
    "telephone": "+55-11-XXXXX-XXXX",
    "email": "contato@shopvivaliz.com.br"
  }
}
</script>
```

**Para Produto (em página de produto):**
```json
<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": "Nome do Produto",
  "image": "https://shopvivaliz.com.br/images/produto.jpg",
  "description": "Descrição",
  "brand": {
    "@type": "Brand",
    "name": "ShopVivaliz"
  },
  "offers": {
    "@type": "Offer",
    "url": "https://shopvivaliz.com.br/produto/123",
    "priceCurrency": "BRL",
    "price": "99.99",
    "availability": "https://schema.org/InStock"
  },
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.5",
    "reviewCount": "88"
  }
}
</script>
```

---

### **Cloudflare Rules (Otimizações)**
**URL:** Cloudflare Dashboard → Rules  
**Sugestões:**
- Cache Rules: cache `/sitemap.xml` por 24h
- Page Rules: Cache Everything para `/produtos/*`
- WAF: Ativar proteção contra bots

---

### **Verificação de DNS Propagação**
```bash
# Verificar todos os nameservers
nslookup shopvivaliz.com.br
# ou
dig shopvivaliz.com.br @8.8.8.8
```

---

## ⏳ FALTA CONFIGURAR (EM ORDEM)

### 2. **Google Search Console**
**URL:** https://search.google.com/search-console

**Passos:**
1. Adicionar propriedade: `shopvivaliz.com.br`
2. Verificar propriedade (via DNS ou HTML tag)
3. Submeter `sitemap.xml`
4. Monitorar crawl errors

**Arquivo de configuração:** Nenhum (tudo via GSC)

---

### 3. **Google Merchant Center**
**URL:** https://merchantcenter.google.com

**Passos:**
1. Criar nova merchant account
2. Adicionar website: `shopvivaliz.com.br`
3. Criar feed de produtos (CSV/XML)
4. Sincronizar com Tiny/Olist (se integrado)
5. Verificar política de dados estruturados

**Campos obrigatórios por produto:**
- ID
- Nome
- Descrição
- URL produto
- Preço
- Disponibilidade
- Marca
- Categoria Google

---

### 4. **Sitemap.xml**
**Localização:** `https://shopvivaliz.com.br/sitemap.xml`

**Gerar automaticamente:**
```bash
# PHP Script (adicionar em script gerador)
<?php
$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

// Adicionar URLs estáticas
$urls = [
    'https://shopvivaliz.com.br/',
    'https://shopvivaliz.com.br/sobre',
    'https://shopvivaliz.com.br/contato',
    'https://shopvivaliz.com.br/politica-privacidade',
];

foreach ($urls as $url) {
    $url_element = $xml->addChild('url');
    $url_element->addChild('loc', $url);
    $url_element->addChild('lastmod', date('Y-m-d'));
    $url_element->addChild('changefreq', 'weekly');
    $url_element->addChild('priority', '0.8');
}

// Adicionar produtos do banco
$products = queryProducts(); // Sua query
foreach ($products as $product) {
    $url_element = $xml->addChild('url');
    $url_element->addChild('loc', $product['url']);
    $url_element->addChild('lastmod', $product['updated_at']);
    $url_element->addChild('changefreq', 'daily');
    $url_element->addChild('priority', '1.0');
}

header('Content-Type: application/xml');
echo $xml->asXML();
?>
```

---

### 5. **Google Ads (ÚLTIMO)**
**Account:** 238-412-1823  
**Status:** Rascunho salvo

**Configurar:**
1. URL de destino: `https://shopvivaliz.com.br`
2. Objetivo: Vendas (Sales)
3. Orçamento diário: **R$ 15,00**
4. Palavras-chave iniciais: [venda de roupas]
5. Público-alvo: Brasil
6. Ativar rastreamento de conversões

---

## 📋 CHECKLIST FINAL

**Antes de LIVE (produção):**
- [ ] Sitemap.xml gerado e acessível
- [ ] GSC: propriedade registrada e verificada
- [ ] GSC: sitemap submetido
- [ ] Merchant Center: feed de produtos sincronizado
- [ ] Analytics: tracking code no `<head>` (verificar)
- [ ] Google Ads: campanha criada com R$15/dia
- [ ] Testar acesso: https://shopvivaliz.com.br
- [ ] Validar SSL: https (certificado válido)
- [ ] DNS propagado (48h máximo)

---

## 🔗 LINKS IMPORTANTES

| Ferramenta | URL |
|-----------|-----|
| Google Search Console | https://search.google.com/search-console |
| Google Merchant Center | https://merchantcenter.google.com |
| Google Analytics | https://analytics.google.com |
| Google Ads | https://ads.google.com (Account: 238-412-1823) |
| Cloudflare | https://dash.cloudflare.com (Domain: shopvivaliz.com.br) |

---

## 📞 CONTATOS ÚTEIS

- **Google Support:** https://support.google.com
- **Cloudflare Support:** https://support.cloudflare.com
- **ShopVivaliz Admin:** https://shopvivaliz.com.br/admin

---

**Próximo Passo:** Configurar GSC → Merchant Center → Sitemap → Google Ads

