# 📸 PLANO DE IMPORTAÇÃO DE IMAGENS OLIST

**Status:** PRONTO PARA EXECUÇÃO  
**Prioridade:** ALTA  
**Task ID:** task-olist-images-import  
**Posição na fila:** 🔴 **NO TOPO (próxima a ser executada)**

---

## 🎯 OBJETIVO

Importar **TODAS as imagens de produtos da Olist** para o servidor local, otimizá-las e ativar no catálogo do ecommerce.

---

## 📋 CRONOGRAMA DE EXECUÇÃO

### Próximos Passos (EM TEMPO REAL)

```
20:17  → Próximo ciclo de agentes dispara
         ↓
20:17  → Agentes descobrem task-olist-images-import
         ↓
20:17  → Gemini (arquitetura) analisa requisitos
20:17  → Claude (implementação) começa download
20:17  → ChatGPT (validação) monitora qualidade
         ↓
20:19  → [PROCESSANDO - fase de download]
         ↓
20:20  → [PROCESSANDO - fase de otimização]
         ↓
20:22  → [PROCESSANDO - fase de armazenamento]
         ↓
22:25  → ✅ CONCLUÍDO: Imagens importadas e ativas!
```

---

## 🔄 FLUXO DETALHADO DE EXECUÇÃO

### FASE 1: Conexão com Olist API (5 min)
```
1. Validar credenciais Olist/Tiny ERP
2. Autenticar na API (OAuth)
3. Obter token de acesso
4. Testar conexão
```

### FASE 2: Listar Produtos (10 min)
```
GET /api/v3/products
Retorna:
  - product_id
  - sku
  - name
  - images_url
  - total_images
```

### FASE 3: Download de Imagens (30+ min)
```
Para cada produto:
  1. Obter URLs das imagens
  2. Download via HTTP
  3. Armazenar em /uploads/products/{product_id}/
  4. Manter nome original + timestamp
  
Exemplo:
  /uploads/products/12345/imagem-1-original.jpg
  /uploads/products/12345/imagem-2-original.jpg
```

### FASE 4: Processamento e Otimização (20+ min)
```
Para cada imagem:
  1. Validar formato (JPG, PNG, WebP)
  2. Redimensionar para tamanhos:
     - thumb: 150x150px
     - medium: 400x400px
     - large: 800x800px
  3. Converter para WebP com fallback
  4. Compressão com Imagemagick
  5. Gerar miniaturas
```

### FASE 5: Armazenamento no Banco (10 min)
```
INSERT INTO olist_product_images
  - product_id
  - local_url (referência armazenada)
  - original_url (para referência)
  - format (jpg, webp, png)
  - size_kb
  - dimensions (width x height)
  - created_at
  - updated_at
  - md5_hash (para deduplicação)
```

### FASE 6: Ativação no Catálogo (5 min)
```
UPDATE products
  SET images_local = true,
      images_count = COUNT(*)
  WHERE product_id IN (importados)
```

### FASE 7: Validação e Testes (5 min)
```
1. Verificar se todas as imagens existem
2. Testar carregamento no browser
3. Validar lazy loading
4. Verificar velocidade de carregamento
```

---

## 📊 MÉTRICAS ESPERADAS

### Quantidades
```
Produtos com imagens: ~500-1000
Total de imagens: ~2000-3000
Tamanho total: ~500MB-1GB (antes da otimização)
Tamanho após otimização: ~100-200MB
```

### Performance
```
Tempo total estimado: 45-60 minutos
Imagens/segundo: ~1-2
Sucesso rate esperado: 95%+
Retry automático para falhas
```

### Espaço em Disco
```
Antes: 0 MB (imagens na Olist)
Depois: ~150 MB (otimizadas)
Cache: ~50 MB
Total: ~200 MB
```

---

## ✅ O QUE SERÁ ENTREGUE

### Arquivos Criados
```
/uploads/products/
  ├── 10001/
  │   ├── thumb/
  │   │   └── imagem-1.webp
  │   ├── medium/
  │   │   └── imagem-1.webp
  │   └── large/
  │       └── imagem-1.webp
  ├── 10002/
  │   └── ... (similar)
  ...
```

### Banco de Dados Atualizado
```
tabela olist_product_images:
  - 2000+ registros novos
  - URLs locais ativas
  - Otimizações registradas
  - Hashes para deduplicação
```

### Frontend Atualizado
```
- Catálogo mostrando imagens locais
- Lazy loading ativo
- Zoom/galeria funcional
- Performance melhorada
```

---

## 🔐 SEGURANÇA

### Credenciais (NUNCA expostas)
```
✓ OAuth tokens em variáveis de ambiente
✓ Nenhuma credencial em logs
✓ Nenhuma credencial em commits
✓ URLs privadas da Olist não armazenadas
```

### Validação
```
✓ Verificar tipo MIME das imagens
✓ Sanitizar nomes de arquivo
✓ Validar dimensões
✓ Checksum MD5 para integridade
```

---

## 🚀 PRÓXIMOS CICLOS

Após importação inicial:

1. **Sync automático** - A cada 6 horas
2. **Novo produtos** - Detecção automática
3. **Remocao** - Produtos deletados sincronizam
4. **Atualizacoes** - Novas imagens importadas

---

## 📞 MONITORAMENTO

Para ver progresso:

```bash
# Ver logs em tempo real
tail -f logs/execution/task-olist-images-import.log

# Ver imagens importadas
ls -la uploads/products/ | wc -l

# Verificar banco de dados
SELECT COUNT(*) FROM olist_product_images;

# Ver catálogo atualizado
curl https://dev.shopvivaliz.com.br/api/products
```

---

## ⚠️ POSSÍVEIS PROBLEMAS E SOLUÇÕES

| Problema | Solução |
|----------|---------|
| Timeout na Olist | Retry automático, dividir em lotes |
| Espaço em disco | Cleanup de cache, compressão aumentada |
| Falha de rede | Retry com backoff exponencial |
| Imagem corrompida | Skip e log, usar imagem placeholder |
| Rate limit Olist | Respeitar limites, distribuir requisições |

---

## 🎯 RESUMO

**Task:** task-olist-images-import  
**Status:** PRONTO  
**Posição:** 🔴 PRÓXIMA NA FILA  
**ETA Execução:** 20:17 (próximo ciclo)  
**ETA Conclusão:** ~21:15  
**Resultado:** 2000+ imagens importadas, otimizadas e ativas  

---

## ✅ CHECKLIST DE CONCLUSÃO

- [ ] Conexão com Olist estabelecida
- [ ] 2000+ imagens baixadas
- [ ] Imagens otimizadas (WebP)
- [ ] Banco de dados atualizado
- [ ] Catálogo mostrando imagens locais
- [ ] Teste de carregamento passando
- [ ] Validação de qualidade OK
- [ ] Documentação atualizada
- [ ] Commit com sucesso

---

*Documento atualizado: 2026-06-27 20:17*  
*Agentes iniciando task-olist-images-import no próximo ciclo...*
