# Blog editorial — operação, ativação e rollback

## Objetivo

O módulo editorial mantém o blog disponível com fallback estático e usa MySQL quando a tabela `blog_articles` estiver pronta. Apenas artigos com `status = published` e data de publicação válida aparecem no site, sitemap, busca de conhecimento e contexto da Liz.

O agendador `api/blog/publish-scheduled.php` também mantém a fila futura abastecida automaticamente antes de publicar. O padrão operacional é `3 artigos por semana`, com `9` publicações futuras mantidas na fila, ajustáveis por `BLOG_AUTOMATION_QUEUE_DEPTH`.

## Pré-requisitos

- backup recente do banco;
- acesso ao ambiente de aplicação;
- segredo `BLOG_PUBLISH_TOKEN` configurado no runtime e no GitHub Actions;
- PHP com `mysqli` e `mbstring`;
- branch implantada com os arquivos do PR.

## Ativação

1. Executar a migração idempotente:

   ```bash
   php scripts/migrate-blog-articles.php
   ```

2. Confirmar que a tabela foi criada e que os slugs estáticos foram importados sem duplicidade.
3. Configurar `BLOG_PUBLISH_TOKEN` no runtime e como secret do GitHub.
4. Opcionalmente configurar `BLOG_PUBLISH_URL`; quando ausente, o workflow usa a URL padrão de produção.
5. Acessar `/admin/blog.php` com conta administrativa.
6. Abrir um artigo em rascunho e validar o preview em `/admin/blog-preview.php?id=<id>`.
7. Executar manualmente o workflow `Blog Publish Scheduled` e verificar resposta HTTP 200.
8. Validar `/blog`, um artigo publicado, `/sitemap.php` e `/api/knowledge/search`.

## Evidências mínimas de QA

- todos os arquivos PHP passam em `php -l`;
- `php tests/blog-editorial-smoke.php` retorna `OK blog editorial smoke`;
- workflows YAML passam no yamllint;
- rascunho não aparece em `/blog`, sitemap, busca ou Liz;
- artigo agendado para o futuro não aparece publicamente;
- preview exige sessão administrativa e envia `noindex,nofollow,noarchive`;
- artigo publicado aparece no site e pode ser encontrado pela Liz;
- indisponibilidade do banco mantém o conteúdo estático acessível.

## Rollback da aplicação

1. Reverter o commit/PR do blog ou apontar o release anterior no mecanismo de deploy.
2. Não remover imediatamente a tabela. O código anterior ignora `blog_articles`, portanto mantê-la é o rollback mais seguro.
3. Desabilitar o workflow `Blog Publish Scheduled` ou remover temporariamente o secret `BLOG_PUBLISH_TOKEN`.
4. Validar que `/blog` voltou a usar `blog/content.php`.

## Rollback de dados

A migração é aditiva. Para preservar conteúdo editorial, prefira não apagar a tabela. Caso seja necessário remover a estrutura, exporte primeiro:

```bash
mysqldump --single-transaction "$DB_NAME" blog_articles > blog_articles-backup.sql
```

Depois da confirmação do backup, a remoção manual deve ser feita somente em janela aprovada:

```sql
DROP TABLE blog_articles;
```

## Segurança

- nunca registrar ou imprimir `BLOG_PUBLISH_TOKEN`;
- rotacionar o token após qualquer suspeita de exposição;
- não transformar o preview em rota pública;
- não publicar HTML bruto fornecido pelo editor; o preview e o artigo devem escapar o conteúdo estruturado;
- manter consultas preparadas e validação de status no servidor.
