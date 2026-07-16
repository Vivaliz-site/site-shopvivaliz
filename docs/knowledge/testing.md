# Testes e Validação

## Testes mínimos por alteração

- Lint de todos os arquivos PHP modificados.
- Validação de sintaxe dos arquivos JavaScript.
- Teste das rotas públicas afetadas.
- Teste dos endpoints com método correto e incorreto.
- Verificação de respostas de erro previsíveis.
- Teste mobile para catálogo, produto, carrinho e checkout.

## Fluxo de compra

1. Abrir catálogo.
2. Confirmar preço e imagem.
3. Abrir produto.
4. Adicionar ao carrinho.
5. Alterar quantidade.
6. Calcular frete.
7. Selecionar opção de entrega.
8. Avançar ao checkout.
9. Validar campos obrigatórios.
10. Criar pedido sem confiar em valores manipuláveis do cliente.

## Health checks

Health check deve validar conteúdo e não apenas status HTTP. Para Squad Chat, exigir `ok=true`, `endpoint=squad-chat` e `providers` presente.

## Pós-deploy

- Executar smoke tests no domínio publicado.
- Confirmar que assets novos não retornam 404.
- Confirmar ausência de erros 500 nos logs.
- Verificar cache e versão dos arquivos.
- Registrar evidência da validação.

## Falhas

Nunca mascarar teste com `|| true` em etapa crítica. Testes não executados ou workflows sem status devem ser reportados como não verificados.
