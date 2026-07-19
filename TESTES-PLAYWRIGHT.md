# 🧪 Testes Automatizados com Playwright - ShopVivaliz

**Data:** 2026-07-08  
**Status:** ✅ TESTES CRIADOS E CONFIGURADOS

---

## 📋 Resumo

Suite completa de testes automatizados criada para validar:

- ✅ **Catálogo e Preços** - Verificar se preços aparecem nos produtos
- ✅ **Autenticação** - Login, Registro, OAuth Google/Apple
- ✅ **Fluxo de Compra** - Seleção, carrinho, checkout
- ✅ **Página de Pedidos** - "Meus Pedidos" com status
- ✅ **Webhook de Status** - Sincronização com ERP
- ✅ **Notificações** - Email de status de pedido

---

## 🚀 Como Executar

### 1. Instalar dependências
```bash
cd c:\site-shopvivaliz
npm install
npx playwright install
```

### 2. Rodar todos os testes
```bash
npx playwright test
```

### 3. Rodar teste específico
```bash
npx playwright test tests/precos-catalogo.spec.ts
npx playwright test tests/autenticacao.spec.ts
npx playwright test tests/fluxo-compra.spec.ts
npx playwright test tests/meus-pedidos.spec.ts
npx playwright test tests/webhook-notificacoes.spec.ts
```

### 4. Modo debug
```bash
npx playwright test --debug
```

### 5. Ver relatório HTML
```bash
npx playwright show-report
```

---

## 📝 Testes Implementados

### 1. **Preços do Catálogo** (`tests/precos-catalogo.spec.ts`)

```
✓ Deve exibir preços nos produtos da homepage
✓ Deve exibir preços válidos (maior que zero)
✓ Produtos especiais devem ter preços corretos
```

**Validações:**
- Procura por "Preço sob consulta" (não deve existir)
- Verifica padrão `R$ XX,XX`
- Valida que valores > 0

---

### 2. **Autenticação** (`tests/autenticacao.spec.ts`)

```
✓ Página de login deve estar acessível
✓ Página de registro deve estar acessível
✓ Registro com dados inválidos deve mostrar erro
✓ Validação de senha deve exigir mínimo 8 caracteres
✓ Botões de Google e Apple OAuth devem estar presentes
✓ Links de redirecionamento devem funcionar
```

**Validações:**
- Elementos de formulário presentes
- Validação de inputs
- Redirecionamentos corretos

---

### 3. **Fluxo de Compra** (`tests/fluxo-compra.spec.ts`)

```
✓ Deve visualizar produto e preço na homepage
✓ Clique em produto deve abrir página de detalhes
✓ Deve aparecer botão "Comprar agora" no produto
✓ Carrinho deve estar acessível
✓ Checkout deve existir
```

**Validações:**
- Produtos com preço visível
- Links de navegação funcionando
- Botões de ação presentes

---

### 4. **Página de Pedidos** (`tests/meus-pedidos.spec.ts`)

```
✓ Página de pedidos deve redirecionar para login se não autenticado
✓ Página de pedidos deve existir
✓ Header deve mostrar nome do usuário quando logado
✓ Lista de pedidos deve ter estrutura correta
✓ Status do pedido deve ter cores visuais
✓ Código de rastreamento deve ser exibido quando disponível
```

**Validações:**
- Autenticação funcionando
- Elementos de UI presentes
- Status com cores
- Rastreamento exibido

---

### 5. **Webhook e Notificações** (`tests/webhook-notificacoes.spec.ts`)

```
✓ Webhook sem token deve retornar 401
✓ Webhook com token inválido deve retornar 403
✓ Webhook com dados inválidos deve retornar 400
✓ Webhook deve aceitar payload válido do Olist
✓ Webhook deve mapear status do Olist corretamente
✓ Endpoint do webhook deve estar documentado
✓ Mailer.php deve exportar funções de email
```

**Validações:**
- Segurança (autenticação)
- Tratamento de erros
- Mapeamento de status (Olist → Local)
- Estrutura de payload

---

## 🔧 Configuração

### Arquivo: `playwright.config.ts`

```typescript
{
  testDir: './tests',
  workers: 1,
  fullyParallel: false,
  reporter: ['html', 'list'],
  use: {
    baseURL: 'https://shopvivaliz.com.br',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  timeout: 5 * 60 * 1000,
}
```

### Variáveis de Ambiente (Opcional)

```bash
# Para testar webhook com token real
export OLIST_WEBHOOK_TOKEN=seu-token-aqui

# Para testes com autenticação
export TEST_USER_EMAIL=test@example.com
export TEST_USER_PASSWORD=password123
```

---

## 📊 Relatório de Testes

### Estrutura de Resultados

```
test-results/
├── index.html              # Relatório visual
├── index.json              # Dados JSON
└── [test-name].png         # Screenshots (se falhar)
```

### Acessar Relatório
```bash
npx playwright show-report
```

---

## 🎯 Cobertura de Testes

| Área | Testes | Status |
|------|--------|--------|
| Preços | 3 | ✅ Criado |
| Autenticação | 6 | ✅ Criado |
| Compra | 5 | ✅ Criado |
| Pedidos | 6 | ✅ Criado |
| Webhook | 7 | ✅ Criado |
| **Total** | **27** | **✅ Criado** |

---

## 🚨 Tratamento de Falhas

### Se testes falharem:

**1. Erro: "Connection refused"**
```
Causa: Site não está respondendo
Solução: Verificar se servidor está rodando
```

**2. Erro: "Element not found"**
```
Causa: Elemento HTML não existe ou tem seletor diferente
Solução: Atualizar seletor CSS/XPath
```

**3. Erro: "Navigation timeout"**
```
Causa: Página demorou muito para carregar
Solução: Aumentar timeout em playwright.config.ts
```

**4. Erro: "Unauthorized (401/403)"**
```
Causa: Webhook token inválido
Solução: Configurar OLIST_WEBHOOK_TOKEN correto
```

---

## 📈 Próximas Melhorias

- [ ] Testes com dados reais (criar usuário de teste)
- [ ] Testes de performance (load testing)
- [ ] Testes de acessibilidade (WCAG compliance)
- [ ] Testes de segurança (XSS, SQL injection)
- [ ] Integração com CI/CD (GitHub Actions)
- [ ] Testes de email (verificar se foi enviado)
- [ ] Testes de SMS/WhatsApp
- [ ] Testes de pagamento (mock)

---

## 🔗 Comandos Úteis

```bash
# Rodar um teste específico
npx playwright test tests/precos-catalogo.spec.ts

# Rodar com verbose output
npx playwright test --verbose

# Rodar em browser headed (ver navegador)
npx playwright test --headed

# Rodar um teste específico em debug
npx playwright test tests/autenticacao.spec.ts --debug

# Gerar relatório HTML
npx playwright test --reporter=html

# Limpar cache
npx playwright test --clean

# Atualizar snapshots
npx playwright test --update-snapshots
```

---

## 📚 Referências

- [Playwright Documentation](https://playwright.dev)
- [Playwright Test Framework](https://playwright.dev/docs/intro)
- [Locators Guide](https://playwright.dev/docs/locators)
- [Best Practices](https://playwright.dev/docs/best-practices)

---

## ✅ Checklist de Implementação

- [x] Criar arquivo de testes de preços
- [x] Criar arquivo de testes de autenticação
- [x] Criar arquivo de testes de compra
- [x] Criar arquivo de testes de pedidos
- [x] Criar arquivo de testes de webhook
- [x] Configurar playwright.config.ts
- [x] Documentação completa
- [ ] Executar testes em CI/CD
- [ ] Adicionar cobertura de código
- [ ] Integrações com ferramentas de monitoramento

---

**Suite de testes pronta para validação! 🧪**
