# Blog editorial — operação, ativação e rollback

## Objetivo

O módulo editorial mantém o blog disponível com fallback estático e usa MySQL quando a tabela `blog_articles` estiver pronta. Apenas artigos com `status = published` e data de publicação válida aparecem no site, sitemap, busca de conhecimento e contexto da Liz.

O agendador `api/blog/publish-scheduled.php` também mantém a fila futura abastecida automaticamente antes de publicar. O padrão operacional é `3 artigos por semana`, com `9` publicações futuras mantidas na fila, ajustáveis por `BLOG_AUTOMATION_QUEUE_DEPTH`.

A geração automática é determinística e classifica cada pauta pela intenção editorial (comparativo, guia de compra, manutenção, tutorial ou projeto). O quality gate rejeita o boilerplate legado e artigos automáticos com conteúdo insuficiente ou repetitivo. A automação não deve inventar preço, estoque, medida, especificação ou compatibilidade de produto.

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

## Reparo de conteúdo automático legado

O deploy da aplicação **não** executa reparo de dados silenciosamente. Quando uma versão do contrato editorial substituir artigos gerados automaticamente no passado, use `scripts/repair-blog-editorial-content.php` depois do novo release estar ativo.

1. Faça o dry-run. Este é também o modo padrão quando nenhuma opção de aplicação é informada:

   ```bash
   php scripts/repair-blog-editorial-content.php --dry-run
   ```

   Registre `agenda_targets`, `database_targets`, `changed`, `unchanged` e `missing_from_database`. O comando só considera slugs derivados da agenda automática; artigos editoriais manuais ficam fora do escopo.

2. Crie ou escolha um diretório de backup **fora da árvore pública da aplicação**, em armazenamento persistente e com acesso restrito. Não use `public/`, a raiz do release ou diretórios servidos pelo Apache.

3. Execute a aplicação somente depois de revisar o dry-run:

   ```bash
   php scripts/repair-blog-editorial-content.php --apply --backup-dir=/caminho/privado/backup-blog
   ```

   Antes de abrir a transação de atualização, o script salva os registros-alvo em JSON com permissão `0600`. Se não conseguir gravar ou restringir o backup, nenhuma atualização é feita.

4. Faça read-back do blog e confira pelo menos um artigo de cada intenção editorial, além de `/blog/`, sitemap, busca de conhecimento e contexto da Liz.

5. Execute o mesmo comando de aplicação uma segunda vez:

   ```bash
   php scripts/repair-blog-editorial-content.php --apply --backup-dir=/caminho/privado/backup-blog
   ```

   A segunda execução deve informar `changed: 0`. Qualquer alteração recorrente indica divergência e deve bloquear a conclusão.

O reparo preserva `id`, `slug`, `status`, datas de publicação/agendamento, comentários e tabelas não relacionadas. Apenas os campos editoriais dos slugs automáticos reconhecidos são atualizados.

### Restauração do reparo

Se o read-back revelar conteúdo inválido, não apague a tabela. Use o arquivo `blog-editorial-before-*.json` produzido antes da aplicação para restaurar os campos editoriais dos mesmos IDs/slugs, dentro de uma transação, preservando status, datas e comentários. Valide a restauração por leitura independente antes de remover qualquer backup.

## Evidências mínimas de QA

- todos os arquivos PHP passam em `php -l`;
- `php tests/blog-editorial-smoke.php` retorna `OK blog editorial smoke`;
- `php tests/blog-editorial-autopilot-smoke.php` retorna `OK blog editorial autopilot smoke`;
- `php tests/blog-editorial-repair-smoke.php` retorna `OK blog editorial repair smoke`;
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
- manter consultas preparadas e validação de status no servidor;
- não guardar os backups de reparo editorial dentro da árvore pública da aplicação.
