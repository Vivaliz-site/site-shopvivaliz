# 🎯 PLANO DE TESTES PROFUNDOS - ShopVivaliz
**Data:** 2026-06-28  
**Objetivo:** Testar CADA ROTINA do projeto de forma profunda

---

## ⏳ FASE 1: SINCRONIZAR CATÁLOGO (AGORA)

### Status Atual
- ❌ Catálogo: 3 produtos (faltam 195)
- ✅ Chave Olist: No GitHub Secrets
- ❌ Imagens: 51/198 sincronizadas

### Ação Necessária
**Você deve:**
1. Criar arquivo `.env` local com chave Olist
2. OU confirmar que secrets estão no GitHub Actions
3. Executar sincronização real

**Depois eu:**
1. Sincronizo 198 produtos com imagens
2. Testo catálogo carregando
3. Valido cada imagem

---

## 📋 FASE 2: TESTAR CADA ROTINA (Após sincronização)

### A. ROTINAS DO SITE PÚBLICO

#### 1. Página Pública do Site
- [ ] Página inicial carrega sem 404
- [ ] CSS responsive carrega (mobile/tablet/desktop)
- [ ] Navbar funciona
- [ ] Todos links trabalham

**Como testar:**
```
1. Abrir https://dev.shopvivaliz.com.br/
2. Verificar visual
3. Testar em 3 devices (desktop, tablet, mobile)
4. Clicar em links de navegação
```

#### 2. Exibição de Produtos
- [ ] Catálogo mostra 198 produtos
- [ ] Produtos em grid/lista
- [ ] Paginação funciona
- [ ] Filtros por categoria funcionam
- [ ] Busca funciona

**Como testar:**
```
1. Abrir /catalogo/
2. Contar produtos (deve ser 198)
3. Testar paginação (próxima/anterior)
4. Filtrar por categoria
5. Buscar por nome
```

#### 3. Exibição de Imagens
- [ ] Cada produto tem imagem
- [ ] Imagens carregam rápido (cache)
- [ ] Sem imagens quebradas (404)
- [ ] Imagens responsivas
- [ ] Fallback se imagem falhar

**Como testar:**
```
1. Abrir /catalogo/
2. Verificar todas imagens carregam
3. DevTools → Network → verificar imagens
4. Inspecionar fonte da imagem
5. Testar em diferentes resoluções
```

#### 4. Página de Produto
- [ ] Carrega detalhe do produto
- [ ] Mostra nome, preço, descrição
- [ ] Mostra estoque
- [ ] Campo quantidade funciona
- [ ] Botão "Adicionar ao Carrinho" funciona

**Como testar:**
```
1. Clicar em um produto
2. Verificar dados aparecem
3. Mudar quantidade
4. Clicar em "Adicionar ao Carrinho"
5. Verificar item no carrinho
```

#### 5. Carrinho
- [ ] Mostra itens adicionados
- [ ] Quantidade editável
- [ ] Remover item funciona
- [ ] Total calcula corretamente
- [ ] Link para checkout funciona

**Como testar:**
```
1. Adicionar 2-3 produtos diferentes
2. Mudar quantidade de um
3. Remover um
4. Verificar totais
5. Clicar "Ir para Checkout"
```

#### 6. Checkout
- [ ] Formulário carrega
- [ ] Todos 8 campos presente (nome, email, etc)
- [ ] Validação funciona
- [ ] Botão "Finalizar Compra" funciona
- [ ] Pedido é criado com sucesso

**Como testar:**
```
1. Carrinho → Checkout
2. Preencher formulário
3. Tentar enviar vazio (deve validar)
4. Preencher todos dados
5. Clicar "Finalizar Compra"
6. Verificar se pedido foi criado (ID gerado)
```

#### 7. Páginas de Erro
- [ ] 404 quando produto não existe
- [ ] Mensagem clara
- [ ] Link para voltar funciona

**Como testar:**
```
1. Acessar /produto.php?id=999999
2. Deve retornar 404 ou mensagem de erro
3. Clicar em voltar/link de retorno
```

---

### B. ROTINAS ADMINISTRATIVAS

#### 1. Painel Admin
- [ ] /admin/ carrega
- [ ] Menu funciona
- [ ] Todas opções acessíveis
- [ ] Sem erro 404

**Como testar:**
```
1. Abrir https://dev.shopvivaliz.com.br/admin/
2. Verificar menu
3. Clicar em cada opção
```

#### 2. Gestão de Produtos
- [ ] Pode listar produtos
- [ ] Pode editar produto
- [ ] Pode excluir produto
- [ ] Validação funciona

**Como testar:**
```
1. Admin → Produtos
2. Editar um produto (mudar preço)
3. Verificar se salvou
4. Tentar excluir um
```

#### 3. Gestão de Imagens
- [ ] Upload de imagem funciona
- [ ] Imagem é salva
- [ ] Vinculação ao produto funciona

**Como testar:**
```
1. Admin → Imagens
2. Upload de novo arquivo
3. Vincular a produto
4. Verificar se aparece no catálogo
```

#### 4. Diagnóstico do Sistema
- [ ] Mostra status de domínio
- [ ] Mostra status de banco
- [ ] Mostra status de API Olist
- [ ] Mostra status de imagens

**Como testar:**
```
1. Admin → Diagnóstico
2. Executar verificações
3. Verificar se todos status aparecem
```

---

### C. ROTINAS OLIST/IMAGENS

#### 1. Sincronização de Produtos Olist
- [ ] Busca 198 produtos da API
- [ ] Salva no banco
- [ ] Atualiza preço, estoque, descrição
- [ ] Log de sincronização criado

**Como testar:**
```
1. Executar: python3 sync-olist-real.py
2. Verificar se 198 produtos foram salvos
3. Verificar banco: SELECT COUNT(*) FROM produtos
4. Verificar log em logs/
```

#### 2. Sincronização de Imagens Olist
- [ ] Busca imagem de cada produto
- [ ] Salva URL no banco
- [ ] primary_image_url atualizado
- [ ] images_count atualizado

**Como testar:**
```
1. Executar: /olist/sync-product-images.php?mode=olist_all
2. Verificar banco: SELECT COUNT(DISTINCT product_id) FROM images
3. Verificar se imagens aparecem no catálogo
```

#### 3. Diagnóstico de Imagens
- [ ] Identifica produtos sem imagem
- [ ] Verifica se URLs são válidas
- [ ] Identifica imagens quebradas
- [ ] Gera relatório

**Como testar:**
```
1. Abrir: /admin/olist-product-image-import.php
2. Clicar em "Diagnóstico"
3. Verificar relatório
4. Deve listar produtos sem imagem
```

#### 4. Importação de Imagens
- [ ] Endpoint funciona
- [ ] Importa imagens em lote
- [ ] Atualiza banco
- [ ] Relatório criado

**Como testar:**
```
1. /admin/olist-product-image-import.php
2. Clicar em "Importar Imagens"
3. Aguardar conclusão
4. Verificar se 198 têm imagens
```

---

### D. ROTINAS SHOPEE

#### 1. Auditoria Shopee
- [ ] Lista anúncios Shopee
- [ ] Verifica dados obrigatórios
- [ ] Identifica erros
- [ ] Gera relatório

**Status:** ⏸️ Pausada (não testada ainda)

#### 2. SEO de Títulos
- [ ] Otimiza títulos
- [ ] Adiciona marca, modelo, medida
- [ ] Evita spam

**Status:** ⏸️ Pausada

#### 3. Conteúdo e Atributos
- [ ] Completa descrição
- [ ] Adiciona atributos obrigatórios
- [ ] Valida campos

**Status:** ⏸️ Pausada

---

### E. ROTINAS AUTÔNOMAS

#### 1. Squad Chat Health
- [ ] Endpoint /api/agent/squad-chat.php?health=1 responde
- [ ] Agentes status OK
- [ ] Chat funciona

**Como testar:**
```
curl https://dev.shopvivaliz.com.br/api/agent/squad-chat.php?health=1
Deve retornar JSON com status OK
```

#### 2. Ciclo Autônomo
- [ ] Workflows disparam no horário
- [ ] Tarefas são processadas
- [ ] Commits são criados
- [ ] Relatórios gerados

**Como testar:**
```
1. GitHub Actions → Ver workflows
2. Verificar se executaram
3. Verificar commits automáticos
4. Verificar logs/
```

---

### F. ROTINAS DE DIAGNÓSTICO

#### 1. Diagnóstico de Domínio
- [ ] dev.shopvivaliz.com.br acessível
- [ ] Sem erro DNS
- [ ] Sem erro 404
- [ ] Sem erro 500

#### 2. Diagnóstico de Deploy
- [ ] Arquivos estão no servidor correto
- [ ] Endpoints novos existem
- [ ] Sem deploy incompleto

#### 3. Diagnóstico de Banco
- [ ] Tabelas existem
- [ ] Dados consistentes
- [ ] Migrations aplicadas

#### 4. Diagnóstico de API Olist
- [ ] Token válido
- [ ] Conexão OK
- [ ] Produtos retornando
- [ ] Imagens em payload

#### 5. Diagnóstico Frontend
- [ ] Template mostra imagens corretas
- [ ] Cache funciona
- [ ] Fallback de imagem funciona

#### 6. Diagnóstico de Cache
- [ ] Cache invalidação funciona
- [ ] Produtos com imagem no banco aparecem
- [ ] Sem imagens "fantasma" (no banco mas não visível)

---

## 📊 MATRIZ DE TESTES

| Rotina | Status | Crítico | Testado |
|--------|--------|---------|---------|
| Página Pública | ✅ | SIM | ❌ |
| Exibição Produtos | 🟡 | SIM | ❌ |
| Exibição Imagens | ❌ | SIM | ❌ |
| Página Produto | ✅ | SIM | ❌ |
| Carrinho | ✅ | SIM | ❌ |
| Checkout | ✅ | SIM | ❌ |
| Admin | ✅ | MÉDIO | ❌ |
| Sync Olist | ❌ | CRÍTICO | ❌ |
| Sync Imagens | 🟡 | CRÍTICO | ❌ |
| Diagnóstico | 🟡 | MÉDIO | ❌ |
| Squad Chat | ✅ | MÉDIO | ❌ |

---

## 🚀 PRÓXIMOS PASSOS

### HOJE:
1. [ ] Você configura `.env` com chave Olist OU confirma secrets
2. [ ] Eu executo sincronização real (198 produtos)
3. [ ] Testamos catálogo carregando com imagens

### AMANHÃ:
1. [ ] Testo cada rotina pública (página, produtos, carrinho, checkout)
2. [ ] Testo cada rotina admin
3. [ ] Testo diagnósticos
4. [ ] Testo agentes autônomos

### SEMANA:
1. [ ] Ativar rotinas Shopee
2. [ ] Validar ciclo autônomo completo
3. [ ] Relatório final de todos testes

---

## ✅ CRITÉRIO DE SUCESSO

Projeto está **100% pronto quando:**
- ✅ Todos 198 produtos aparecem no catálogo
- ✅ Todas imagens carregam
- ✅ Fluxo completo funciona (catálogo → checkout)
- ✅ Admin funciona
- ✅ Diagnósticos passam
- ✅ Agentes processam de verdade

**Você tem:** Chave Olist no Secrets  
**Eu preciso:** Que você forneça/confirme a chave para sincronizar agora

---

**Próximo passo: Você envia a chave Olist (ou confirma está no Secrets).**
Aí sincronizo 198 produtos E começamos testes profundos! 🚀
