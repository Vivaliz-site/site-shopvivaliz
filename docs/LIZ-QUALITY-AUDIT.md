# Auditoria profunda da Liz

## Escopo

Esta auditoria cobre grounding comercial, autenticação, proteção contra
prompt injection, XSS e abuso, handoff humano, memória conversacional,
observabilidade, conhecimento editorial, acessibilidade e testes.

## Controles implementados nesta branch

- Catálogo: busca usa includes/catalog-runtime.php fora do modo de teste,
  com fallback explícito e fonte declarada.
- Pedidos e rastreamento: consulta somente com sessão autenticada, user_id
  e referência do próprio pedido.
- Frete: sem cotação oficial, a Liz não estima valor ou prazo.
- Segurança: rejeição determinística de tentativas de revelar instruções,
  chaves ou segredos.
- Handoff: pedido de atendente e reclamações retornam resumo estruturado,
  canal e motivo.
- Memória: intenção, orçamento, autenticação, exigência de autenticação,
  histórico considerado e detecção de injeção são retornados como estado.
- Observabilidade: métricas JSONL sem conteúdo bruto ou PII.
- Conhecimento: artigos publicados são versionados e enviados como contexto
  separado da mensagem do usuário.
- Roteamento interno: chamada knowledge usa loopback fixo e Host conhecido,
  sem confiar no HTTP_HOST recebido.

## Limitações que permanecem

- A cotação de frete ainda depende do checkout; não há uma API de frete
  consultada pelo endpoint da Liz nesta branch.
- Handoff entrega um payload estruturado para o frontend; integração com CRM
  ou abertura automática de ticket depende do canal operacional escolhido.
- A consulta de pedidos depende do esquema real da tabela orders e deve ser
  validada com dados de teste autenticados.
- Teste real de navegador/mobile requer ambiente com Playwright ou browser
  conectado; lint e HTTP 200 não substituem esse teste.

## Critério de validação

Uma melhoria só deve ser considerada pronta quando:

1. php -l passa nos arquivos alterados.
2. Os testes unitários de Liz passam.
3. Fixtures de injeção, frete, pedido sem autenticação, handoff e reclamação
   passam.
4. O endpoint retorna fontes de grounding sem revelar dados sensíveis.
5. O frontend renderiza resposta com textContent, sem HTML não confiável.
6. O smoke test de produção comprova saúde sem modificar dados.
