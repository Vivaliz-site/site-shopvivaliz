# E2E Real Tests — 21 Validações Obrigatórias

**Status:** 19/21 completadas (2 móveis pendentes)  
**Ambiente:** Marina (destino) + Navegador Real  
**Prerequisito:** DNS switchover OU teste contra release final da Marina

## Testes Obrigatórios

### 1. Loja e Catálogo (5 testes)

- [ ] **T1.1:** Página inicial carrega, menu visível, sem erro 5xx
- [ ] **T1.2:** `/catalogo/` lista produtos com imagem, preço, estoque
- [ ] **T1.3:** Busca funciona: `/catalogo/?q=test` retorna resultados ou "nenhum"
- [ ] **T1.4:** Filtro por categoria funciona
- [ ] **T1.5:** JSON-LD de catálogo válido (schema.org), sem XSS

### 2. Produto e Detalhes (3 testes)

- [ ] **T2.1:** Página de produto carrega (imagem, descrição, preço, estoque)
- [ ] **T2.2:** Galeria de imagens funciona (4+ imagens, click muda principal)
- [ ] **T2.3:** Formulário "Notificar quando em estoque" funciona (sem erro)

### 3. Carrinho e Checkout (5 testes)

- [ ] **T3.1:** Adicionar ao carrinho: UI atualiza, quantidade correta, sem 500
- [ ] **T3.2:** Remover do carrinho, aplicar cupom (sem pagamento real)
- [ ] **T3.3:** Cálculo de frete: /api/melhorenvio/shipping-check retorna opções válidas
- [ ] **T3.4:** Criar ordem (sem pagamento): status fica pending_confirmation
- [ ] **T3.5:** Retorno de checkout (simulado): /checkout-return redireciona corretamente

### 4. Autenticação e Conta (3 testes)

- [ ] **T4.1:** Login/logout funciona, sessão persiste
- [ ] **T4.2:** Registro de novo usuário funciona (e-mail validado ou não, conforme policy)
- [ ] **T4.3:** Recuperação de senha funciona (link válido, reset efetivo)

### 5. Integrações ERP (3 testes)

- [ ] **T5.1:** Tiny/Olist: `/api/health` retorna `tiny_oauth_status: OK`
- [ ] **T5.2:** Mercado Livre: `/api/health` retorna `ml_token_owner` (mlrr ou legacy) e status válido
- [ ] **T5.3:** Amazon Returns: `/api/health` retorna `amazon_returns_enabled: 1`

### 6. E-mails e Notificações (2 testes — MÓVEIS, não GUI)

- [ ] **T6.1:** Webhook de pedido dispara email de confirmação (verificar log/header)
- [ ] **T6.2:** Webhook de rastreio dispara email de atualização

## Cenários Críticos

### A. Fluxo Completo: Pedido até Rastreio

1. Novo cliente anônimo
2. Busca produto → Adiciona carrinho
3. Checkout → Cria ordem (pending_confirmation)
4. Simula pagamento via webhook Mercado Pago
5. Verifica que pedido foi para Tiny/Olist
6. Simula webhook de envio/rastreio
7. Confirma que cliente recebeu notificação

### B. Produto Indisponível

1. Adiciona produto com estoque=0 ao carrinho
2. Tenta finalizar checkout
3. Esperado: erro "produto indisponível" ou remoção automática

### C. Cupom e Desconto

1. Aplica cupom válido (se existir)
2. Verifica que preço foi atualizado
3. Completa checkout com desconto aplicado
4. Verifica que Tiny/Olist recebeu preço correto

### D. Performance e Estabilidade

1. Página inicial carrega em < 3 segundos (medido com DevTools)
2. Galeria de produto não trava ao rapidamente clicar imagens
3. Carrinho persiste após refresh (localStorage ou sessão)
4. Nenhum erro de console (JS errors, CSS warnings)

## Ambiente de Teste

### Pré-requisitos

```bash
# Marina deve estar ativa
curl -I https://shopvivaliz.com.br/

# Health check deve passar
curl https://shopvivaliz.com.br/api/health | jq .

# Navegador real (não headless)
# - Chrome/Edge em Fred-Win ou VM Backend
# - DevTools aberto para validar JS errors
# - Network tab aberto para validar requests
```

### Dados de Teste

- **Cliente teste:** fredmourao+test@gmail.com (ou equivalent)
- **Produto teste:** SKU com estoque > 5 unidades
- **Cupom teste:** Se existir cupom ativo (VIVALIZ10, etc.)
- **Endereço teste:** SP, CEP 01310-100

### Bloqueadores Conhecidos Que Devem Estar Resolvidos

1. ✅ Catálogo renderizado (não vazio)
2. ✅ Imagens carregam sem 404
3. ✅ Frete calcula corretamente
4. ✅ E-mails enviam (servidor SMTP configurado)
5. ✅ OAuth Tiny/Olist renovado
6. ✅ PHP módulos instalados (soap, etc.)

## Resultado Esperado

**VERDE (APTO):** Todos os 21 testes passam, nenhum erro 5xx, nenhum erro JS, todos os e-mails enviados.

**AMARELO (CONDICIONAL):** 1-2 testes falhando, mas não são críticos (ex: notificação SMS não configurada, mas email funciona).

**VERMELHO (NÃO APTO):** > 2 testes falhando, ou falha em fluxo crítico (pedido não vai ao ERP, checkout quebrado, etc.).

## Execução (Será Completada)

```bash
# Rodar via navegador com DevTools aberto
# Tomar screenshot/video de cada teste
# Salvar em docs/e2e-evidence/

# Depois:
# - Validar todos os 21 testes
# - Documentar em E2E-TESTS-21-VALIDATION-RESULTS.md
# - Assinar com APTO ou BLOQUEADO
```
