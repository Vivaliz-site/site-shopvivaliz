# Validação da Central de Conhecimento e Liz com Conhecimento

Este checklist valida o PR da Central de Conhecimento antes de qualquer merge em `main`.

## 1. Central de Conhecimento

Validar as rotas públicas:

- [ ] `/blog` carrega sem erro 500 ou 404.
- [ ] `/blog?q=rodizio` retorna artigos relevantes.
- [ ] `/blog?categoria=Organização` filtra artigos por categoria.
- [ ] `/blog/como-escolher-ferramentas-para-casa` abre artigo individual.
- [ ] `/blog/como-escolher-rodizio-ideal` abre artigo individual.
- [ ] `/blog/comparativo-tipos-de-fixadores` abre artigo individual.
- [ ] Artigos individuais exibem título, resumo, autoria, categoria, tempo de leitura, conteúdo, FAQ e produtos relacionados.
- [ ] Links de artigos relacionados não geram 404.

## 2. SEO técnico

- [ ] Cada artigo possui `title` único.
- [ ] Cada artigo possui `meta description` coerente.
- [ ] Canonical aponta para a URL final do artigo.
- [ ] Open Graph está preenchido.
- [ ] Twitter Card está preenchido.
- [ ] JSON-LD `Article` é renderizado.
- [ ] JSON-LD `FAQPage` é renderizado quando houver FAQ.
- [ ] `/sitemap.xml` inclui as URLs do blog.

## 3. API pública de conhecimento

Validar:

```bash
curl -s "https://shopvivaliz.com.br/api/knowledge/search?q=rodizio"
```

Resultado esperado:

- [ ] resposta JSON válida;
- [ ] `ok=true`;
- [ ] lista de artigos relevantes;
- [ ] nenhum erro PHP visível;
- [ ] nenhum dado sensível exposto.

## 4. Preview da Liz

Validar:

```bash
curl -s "https://shopvivaliz.com.br/api/liz/knowledge-preview?q=rodizio"
```

Resultado esperado:

- [ ] resposta JSON válida;
- [ ] artigos encontrados quando a busca tiver correspondência;
- [ ] bloco de contexto editorial gerado;
- [ ] nenhum prompt interno, chave ou segredo exposto.

## 5. Liz com conhecimento por endpoint opt-in

Validar:

```bash
curl -s -X POST "https://shopvivaliz.com.br/api/liz/intelligent-knowledge" \
  -H "Content-Type: application/json" \
  -d '{"message":"como escolher rodizio?","history":[]}'
```

Resultado esperado:

- [ ] resposta JSON válida;
- [ ] `ok=true` quando provedor de IA estiver disponível;
- [ ] `knowledge_mode="enabled"`;
- [ ] `knowledge_found` numérico;
- [ ] `knowledge_articles` com artigos relevantes quando houver correspondência;
- [ ] resposta da Liz usa contexto sem inventar preço, estoque ou prazo.

## 6. Widget da Liz

Validar fluxo padrão:

- [ ] Abrir uma página sem parâmetros.
- [ ] A Liz continua usando o endpoint padrão.
- [ ] `data-knowledge="disabled"` aparece no elemento raiz do widget.

Validar fluxo por query string:

- [ ] Abrir uma página com `?lizKnowledge=1`.
- [ ] Fazer uma pergunta sobre rodízio, fixadores ou organização.
- [ ] `data-knowledge="enabled"`.
- [ ] `data-knowledge-source="query"`.
- [ ] Após resposta, `data-knowledge-found` é preenchido.

Validar fluxo por localStorage:

```js
localStorage.setItem('shopvivaliz_liz_knowledge', '1')
```

Depois recarregar a página.

- [ ] `data-knowledge="enabled"`.
- [ ] `data-knowledge-source="localStorage"`.

Para voltar ao padrão:

```js
localStorage.removeItem('shopvivaliz_liz_knowledge')
```

## 7. Critérios de aprovação

A entrega pode seguir para merge somente se:

- [ ] CI obrigatório passar.
- [ ] Playwright não reportar falha crítica.
- [ ] `/blog` e pelo menos 3 artigos forem validados visualmente.
- [ ] API de conhecimento responder JSON válido.
- [ ] Endpoint opt-in da Liz funcionar ou falhar de forma segura quando IA estiver indisponível.
- [ ] Produção não for alterada diretamente antes do merge.

## 8. Próxima etapa após aprovação

Após validação, a próxima etapa é ativar `knowledgeEnabled` por configuração global ou apontar o frontend para o endpoint com conhecimento como padrão, mantendo rollback simples para `/api/liz-intelligent.php`.
