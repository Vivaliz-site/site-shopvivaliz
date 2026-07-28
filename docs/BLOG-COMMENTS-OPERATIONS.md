# Blog — comentários públicos e respostas da Liz

## Objetivo

Permitir que visitantes publiquem perguntas e comentários nos artigos e recebam uma resposta pública da Liz, com privacidade, moderação e possibilidade de intervenção administrativa.

## Ativação

1. Fazer backup do banco.
2. Implantar a branch/release com os arquivos deste módulo.
3. Executar:

```bash
php scripts/migrate-blog-comments.php
```

4. Configurar `BLOG_COMMENT_HASH_SALT` com segredo exclusivo.
5. Configurar `BLOG_COMMENT_ENCRYPTION_KEY` para armazenar e-mail cifrado. Quando ausente, apenas o hash do e-mail é persistido.
6. Garantir pelo menos um provedor da Liz: `GEMINI_API_KEY`, `OPENAI_API_KEY` ou `ANTHROPIC_API_KEY`.
7. Testar um comentário em artigo publicado.
8. Validar `/admin/blog-comments.php` com conta administrativa.

## Fluxo público

- O formulário usa POST, CSRF e honeypot.
- Nome, e-mail e mensagem são validados e limitados.
- O e-mail nunca é exibido nem enviado ao prompt da Liz.
- Comentários de baixo risco são publicados imediatamente.
- Conteúdo suspeito fica pendente ou é marcado como spam.
- A Liz responde depois da gravação do comentário.
- Falha da IA não apaga nem impede o comentário de ser salvo.
- O visitante retorna para `#comentarios` com mensagem de status.

## Moderação

Em `/admin/blog-comments.php` é possível:

- publicar;
- devolver para pendente;
- ocultar;
- rejeitar;
- marcar como spam;
- registrar motivo;
- adicionar resposta manual da Equipe ShopVivaliz.

Toda mudança relevante é registrada em `blog_comment_audit`.

## Privacidade e retenção

- O e-mail público não aparece no HTML, logs ou prompt.
- O IP é armazenado somente como hash HMAC.
- O user-agent é armazenado somente como hash.
- Recomenda-se eliminar hashes de IP e e-mails cifrados após 90 dias, mantendo o conteúdo público quando houver base legítima para isso.
- Solicitações de remoção devem localizar o registro pelo e-mail informado, comparar o hash e remover ou anonimizar o comentário.

## Resposta da Liz

A resposta deve:

- usar título, categoria e resumo do artigo;
- responder em português do Brasil;
- não inventar preço, estoque, frete, prazo, garantia ou política;
- não solicitar dados pessoais em público;
- encaminhar para catálogo ou atendimento quando o dado não estiver confirmado;
- aparecer identificada como assistente virtual;
- informar quando a resposta foi gerada por IA.

## Antispam

O endpoint aplica:

- limite de cinco envios por IP em dez minutos;
- honeypot invisível;
- limite de 2.000 caracteres;
- remoção de HTML;
- bloqueio de conteúdo ativo;
- detecção de links excessivos e padrões comuns de spam;
- fila de moderação para conteúdo sensível.

## QA mínimo

- `php -l` em todos os arquivos modificados;
- executar a migração duas vezes e confirmar idempotência;
- comentário válido aparece no artigo correto;
- e-mail não aparece no HTML;
- resposta da Liz fica vinculada ao comentário;
- falha dos três provedores não perde o comentário;
- `<script>` e HTML são escapados;
- honeypot não grava comentário real;
- sexto envio em dez minutos é limitado;
- administrador consegue publicar, ocultar, rejeitar e responder;
- layout funciona em desktop e mobile.

## Rollback

1. Desativar temporariamente o formulário removendo ou ocultando a seção pública.
2. Para desligar apenas a IA, remover as chaves dos provedores; comentários continuam sendo salvos e recebem fallback seguro.
3. Reverter o PR sem apagar imediatamente as tabelas.
4. Fazer backup antes de qualquer remoção:

```bash
mysqldump --single-transaction "$DB_NAME" blog_comments blog_comment_replies blog_comment_audit > blog-comments-backup.sql
```

5. Somente após aprovação e confirmação do backup, remover as tabelas em ordem inversa das dependências.