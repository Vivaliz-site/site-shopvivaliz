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

