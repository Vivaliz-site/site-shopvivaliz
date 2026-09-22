# AUDIT_BROWSER_E2E_REAL_V1 — E2E real obrigatório no navegador

Esta regra é obrigatória em toda Auditoria Extrema, validação funcional de release e certificação `APTO` de qualquer fluxo que possua interface web.

## Regra principal

Se existe UI para a operação, o agente deve executar o fluxo **de ponta a ponta no navegador real**, contra o mesmo ambiente e release que está sendo certificado.

Não é permitido substituir esse teste por `curl`, chamada direta de API, SQL, script, unit/integration test, inspeção de código, healthcheck, screenshot estático ou execução headless isolada.

API, SQL, logs e scripts podem preparar fixtures ou confirmar o efeito por um canal independente, mas a ação do usuário que altera ou valida o fluxo deve acontecer pela UI real.

## Responsabilidade do agente

O próprio agente é responsável por realizar o teste no navegador. Não é aceitável encerrar pedindo ao usuário que abra a tela, clique ou envie screenshot para completar uma validação que o agente possui meios técnicos de executar.

Validação manual do usuário pode complementar a evidência, mas nunca substitui o E2E do agente.

Para ShopVivaliz, o navegador de agente deve executar na VM de navegação definida em `docs/knowledge/agent-rules.md` e na política de acesso vigente. Não usar navegador dos hosts Windows como atalho operacional.

## O que significa ponta a ponta

O teste deve começar na entrada real do usuário e atravessar todas as etapas materiais até a pós-condição final. Conforme o fluxo, isso inclui:

1. abrir a URL publicada no browser;
2. autenticar pela UI quando aplicável;
3. navegar pelas telas reais, sem saltar diretamente para endpoint interno;
4. clicar nos controles reais;
5. preencher formulários e seleções pela UI;
6. submeter a ação pela UI;
7. observar estados intermediários, redirects, loaders, mensagens e erros;
8. confirmar o resultado final na própria UI;
9. recarregar a página;
10. sair da tela e retornar ao registro/fluxo;
11. confirmar que o estado persistiu;
12. quando houver efeito externo ou persistência crítica, confirmar também por evidência independente no backend/API/banco/provider.

Um teste que executa somente a última API chamada do fluxo não é E2E de browser.

## Browser real e automação

Playwright, Selenium ou ferramenta equivalente podem dirigir o navegador, desde que controlem um navegador real contra o ambiente publicado e percorram a UI/DOM e a rede reais do fluxo.

Execução exclusivamente headless, mockada, com service worker/network intercept substituindo o backend, fixture que pula a UI, ou screenshot gerado sem interação completa **não certifica** o fluxo quando existe UI real disponível.

Para certificação visual/funcional, prefira a sessão gráfica real da VM, reutilizando o perfil autenticado aprovado quando necessário.

## Evidência mínima obrigatória

Para cada fluxo crítico testado, registrar:

- SHA/release/build e ambiente;
- host/VM e navegador utilizados;
- URL inicial e URL/estado final;
- timestamp;
- identidade não sensível da entidade de teste;
- sequência de ações executadas pela UI;
- screenshot, vídeo ou trace suficiente para provar os marcos principais;
- requests/responses relevantes observados na rede;
- `console.error`, `pageerror`, `requestfailed` e respostas `5xx`;
- estado antes/depois;
- confirmação após reload e revisita;
- persistência/efeito externo reconciliado quando aplicável.

Ausência de erro não basta; a pós-condição correta precisa ser comprovada.

## Gate fatal

Qualquer uma das situações abaixo impede `APTO` até correção e reexecução:

- fluxo crítico com UI não executado no browser real;
- agente substituiu clique/form/submissão por API/CLI;
- teste executado somente localmente quando o alvo é produção/staging publicado;
- browser testado em SHA/release diferente do certificado;
- mutação sem reload/revisita e confirmação de persistência;
- `5xx`, `pageerror`, `requestfailed` ou `console.error` inesperado;
- tela de erro, blank state ou fallback silencioso;
- efeito externo aceito mas não confirmado/reconciliado;
- única evidência é screenshot estático ou healthcheck;
- agente delegou ao usuário a execução do E2E que deveria executar.

Se o navegador real, autenticação ou ambiente publicado estiver indisponível, o resultado é `NÃO VALIDADO` / `INCONCLUSIVO`, nunca `APTO`.

## Ações financeiras ou destrutivas

A exigência de browser E2E não autoriza cobrança real, exclusão destrutiva ou alteração fora do escopo.

Quando o último passo for irreversível ou financeiro, use fixture segura, sandbox, modo de pagamento não financeiro, dry-run oficialmente suportado ou outra estratégia aprovada que preserve o caminho real pela UI até o limite seguro. Registre explicitamente qual efeito foi evitado e como o restante do fluxo foi reconciliado.

## Pós-deploy

Quando houver deploy, o E2E obrigatório deve ser repetido contra o release efetivamente ativo. E2E executado antes do deploy não certifica a produção publicada.

**Marker de governança:** `AUDIT_BROWSER_E2E_REAL_V1`
