# Checklist de Migração de Domínio - shopvivaliz.com.br

**Data da Migração:** 2026-07-19  
**Domínio Anterior:** [VERIFICAR]  
**Domínio Novo:** shopvivaliz.com.br  

---

## 1️⃣ DNS & INFRAESTRUTURA

- [ ] **DNS atualizado**
  - [ ] A records apontam para IP correto (137.131.156.17)
  - [ ] CNAME records corretos
  - [ ] MX records configurados (email)
  - [ ] TXT records SPF, DKIM, DMARC

- [ ] **SSL/HTTPS**
  - [ ] Certificado Let's Encrypt válido
  - [ ] Renovação automática ativa (cron)
  - [ ] Redirect HTTP → HTTPS funciona

- [ ] **Server (VM Oracle)**
  - [ ] Apache virtualhost configurado
  - [ ] .htaccess com regras corretas
  - [ ] Logs sem erros 404

---

## 2️⃣ REDIRECTS & URLs

- [ ] **Redirects 301 do domínio antigo**
  - [ ] Todos os redirects configurados no .htaccess
  - [ ] Testes: `curl -I old-domain.com` → 301 → novo
  
- [ ] **URLs internas**
  - [ ] Links hard-coded apontam para novo domínio
  - [ ] Buscar por URLs antigas no código

---

## 3️⃣ GOOGLE SERVICES

### Google Search Console (GSC)
- [ ] **Novo domínio adicionado**
  - [ ] Verificação de propriedade completa
  - [ ] Sitemap enviado (sitemap.xml)
  - [ ] URLs crawladas com sucesso

- [ ] **Domínio antigo**
  - [ ] Configuração de mudança de endereço feita
  - [ ] Redirecionamentos monitorados

### Google Analytics
- [ ] **GA4 configurado**
  - [ ] Property nova criada para novo domínio
  - [ ] Tracking code no `<head>` (GOOGLE_ANALYTICS_ID)
  - [ ] Eventos rastreando corretamente

- [ ] **Histórico de dados**
  - [ ] Criar view de comparação antes/depois migração

### Google Merchant Center
- [ ] **Feed de produtos atualizado**
  - [ ] URLs dos produtos com novo domínio
  - [ ] Imagens carregam corretamente
  - [ ] Testes de feed passando

### Google Ads
- [ ] **Landing pages**
  - [ ] URLs de destino com novo domínio
  - [ ] Quality Score checado
  - [ ] Campanhas rodando sem erro

---

## 4️⃣ CLOUDFLARE & CDN

- [ ] **Cloudflare DNS**
  - [ ] Records criados/atualizados
  - [ ] SSL policy ativa (Full ou Full Strict)
  - [ ] Cache rules atualizadas

- [ ] **Cache**
  - [ ] Purge do cache após migração
  - [ ] TTL apropriado para arquivos estáticos

---

## 5️⃣ EMAIL & COMUNICAÇÃO

- [ ] **Email configurado**
  - [ ] MX records apontando certo
  - [ ] SMTP funcionando
  - [ ] Testes de envio OK

- [ ] **Notificações**
  - [ ] Clientes notificados sobre novo domínio
  - [ ] Auto-reply atualizado se houver

---

## 6️⃣ ROBOTS & SITEMAP

- [ ] **robots.txt**
  - [ ] Atualizado para novo domínio
  - [ ] Sem bloquear /admin, /logs, etc acidentalmente

- [ ] **sitemap.xml**
  - [ ] Gerado com novo domínio
  - [ ] URLs absolutas corretas
  - [ ] Enviado ao GSC

---

## 7️⃣ BANCO DE DADOS & CONFIGURAÇÃO

- [ ] **.env atualizado**
  - [ ] DOMAIN=shopvivaliz.com.br
  - [ ] URLs base corretas
  - [ ] API endpoints atualizados

- [ ] **Banco de dados**
  - [ ] URLs hardcoded em DB atualizadas
  - [ ] Backups feitos PRÉ e PÓS migração

---

## 8️⃣ INTEGRAÇÕES EXTERNAS

### Mercado Pago
- [ ] **Webhook URL**
  - [ ] Atualizado para novo domínio
  - [ ] Testes de pagamento OK

### Tiny ERP
- [ ] **Callback URLs**
  - [ ] Atualizadas no dashboard Tiny
  - [ ] Sincronização funcionando

### Olist
- [ ] **URLs de callback**
  - [ ] Atualizadas
  - [ ] Pedidos sincronizando

### Melhor Envio
- [ ] **URLs de rastreamento**
  - [ ] Atualizado no painel

---

## 9️⃣ TESTES DE FUNCIONALIDADE

- [ ] **Página inicial**
  - [ ] Carrega sem erros
  - [ ] Imagens aparecem
  - [ ] CSS/JS corretos

- [ ] **Catálogo de produtos**
  - [ ] Produtos listam
  - [ ] Filtros funcionam
  - [ ] Busca funciona

- [ ] **Carrinho & Checkout**
  - [ ] Adicionar ao carrinho OK
  - [ ] Checkout carrega
  - [ ] Pagamento processa

- [ ] **Pedidos**
  - [ ] Criar pedido funciona
  - [ ] Email de confirmação envia
  - [ ] Admin vê pedidos

- [ ] **Performance**
  - [ ] Página carrega < 3s
  - [ ] Mobile responsivo
  - [ ] Sem 404 em console

---

## 🔟 SEGURANÇA

- [ ] **HTTPS ativo**
  - [ ] Sem avisos de certificado
  - [ ] Mixed content checado

- [ ] **Secrets/Credenciais**
  - [ ] .env privado atualizado
  - [ ] Nenhuma credencial em repo público

---

## ✅ MONITORAMENTO PÓS-MIGRAÇÃO

**Proximos 7 dias:**
- [ ] Monitorar GSC por crawl errors
- [ ] Monitorar GA4 por picos/quedas
- [ ] Testar 1x por dia funcionalidades críticas
- [ ] Verificar logs do servidor
- [ ] Validar backups automáticos

---

## 📋 RESUMO

**Completado:**
- DNS apontando correto
- SSL/HTTPS ativo
- Google Analytics rastreando
- Pagamentos processando

**Pendente:**
- [ ] [PREENCHER]
- [ ] [PREENCHER]
- [ ] [PREENCHER]

**Data de Conclusão Esperada:** 2026-07-26

