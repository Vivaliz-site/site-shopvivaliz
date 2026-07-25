# Regressão: menu mobile não pode cobrir a página

## Cenário
1. Abrir a home em viewport mobile (390x844 ou equivalente).
2. Confirmar que o menu inicia fechado.
3. Abrir o menu pelo botão hambúrguer.
4. Confirmar que o painel tem altura limitada ao conteúdo e permanece dentro da viewport.
5. Fechar tocando fora, pressionando Escape e navegando por um link.
6. Voltar para a página pelo histórico do navegador e confirmar que o menu continua fechado.

## Critérios
- Nenhuma camada branca deve aparecer automaticamente sobre a página.
- O menu deve usar no máximo 68dvh/440px e permitir rolagem interna.
- O atributo `hidden` deve controlar o estado fechado, independentemente de CSS global legado.
