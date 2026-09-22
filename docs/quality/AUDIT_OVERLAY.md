# Overlay de Auditoria do Projeto

Este arquivo complementa o protocolo universal. Ele não substitui `AGENTS.md`, README, ADRs, arquitetura, regras de negócio ou runbooks existentes.

## Regra de uso
Antes de iniciar uma auditoria extrema, o agente deve reconstruir as regras específicas deste repositório a partir das fontes autoritativas existentes e transformar essas regras em **invariantes verificáveis**. Não é permitido auditar apenas contra boas práticas genéricas.

## Fontes obrigatórias quando existirem
1. `AGENTS.md` e arquivos referenciados por ele;
2. README e documentação de arquitetura;
3. ADRs e decisões vigentes do proprietário;
4. migrations/schema e contratos públicos;
5. runbooks operacionais/deploy;
6. testes que expressem regras históricas;
7. configuração e comportamento real do ambiente.

## Classes de domínio a considerar
Se presentes no projeto, trate como área crítica: dinheiro/preço/reembolso; prazos e datas-limite; efeitos externos; autenticação/autorização; isolamento multi-tenant; dados pessoais/sensíveis; automações periódicas; filas/workers; integrações de terceiros; reconciliação; backups/restores; deploy e proveniência da versão.

## Matriz de invariantes específica
A auditoria deve criar no relatório uma tabela `Invariante | Fonte da regra | Garantia técnica | Teste/evidência | Resultado`. Regras conflitantes devem ser resolvidas pela hierarquia de autoridade já definida no projeto; se não houver hierarquia clara, registre o conflito como achado.

## Extensões obrigatórias do projeto
Além dos invariantes de domínio, o overlay deve identificar:
- quais P4 são `IMPROVEMENT_REQUIRED` versus `IMPROVEMENT_OPTIONAL`;
- quais automações/serviços/processos podem concorrer ou duplicar responsabilidade;
- quais falhas críticas dependem de alerta/watchdog/dead-letter e como serão exercitadas;
- quais jobs/workers/webhooks precisam ser observados após deploy;
- qual estratégia segura de rollback/restore é aplicável;
- quais classes do `AUDIT_ESCAPE_REGISTER.md` se aplicam ao projeto;
- quais classes de `AUDIT_ERROR_TAXONOMY_V1` são materiais e quais são `N/A` com justificativa;
- quais boundaries, negativos e combinações de ambiente alteram o risco;
- quais reconciliações quantitativas/órfãos precisam ser provados;
- quais baselines operacionais devem ser comparados;
- qual evidence artifact/manifest será produzido;
- como o self-test dos gates será exercitado quando aplicável;
- mapa de arquitetura/runtime e dependências cross-repo;
- baseline/budget de CI, deploy e rollback;
- quais passos podem sair do runner de produção;
- mapa `path/componente → serviço/restart/provisionamento`;
- owners de dados/filas/contratos e fitness functions arquiteturais.

Esses itens passam a integrar o Gate Final quando materiais ao domínio.

## Browser E2E real obrigatório no ShopVivaliz

Para qualquer fluxo web com UI, aplicar obrigatoriamente `AUDIT_BROWSER_E2E_REAL_V1`:

- o **próprio agente** deve executar o fluxo completo no navegador real da VM de navegação;
- `curl`, API direta, SQL, script, unit/integration test, healthcheck ou screenshot estático são apenas apoio e **não substituem** o E2E pelo browser;
- não é permitido pular telas chamando diretamente o endpoint que a UI chamaria;
- o teste deve começar na entrada real, navegar/clicar/preencher/submeter pela UI e terminar na pós-condição visível;
- após qualquer mutação, recarregar, sair da tela, retornar e confirmar persistência;
- registrar rede, erros de console/page, estado antes/depois e evidência visual suficiente;
- quando houver autenticação, reutilizar a sessão gráfica autenticada aprovada da VM; não transferir a responsabilidade ao usuário;
- browser indisponível, autenticação impossível ou fluxo crítico não exercitado pela UI = `NÃO VALIDADO` e bloqueia `APTO`.

No storefront, quando material ao escopo, o E2E deve percorrer pelo browser pelo menos `catálogo → produto → carrinho → cotação de frete → checkout → confirmação/persistência`. Em superfícies administrativas, deve percorrer `login → navegação até a função → ação real → feedback → reload/revisita → persistência/efeito`.

## Regras estruturais adicionadas por auditorias anteriores

### ESC-2026-001 (2026-09-21) — endpoints em diretórios bloqueados pelo .htaccess
Toda auditoria extrema deve:
1. Verificar qual URL o frontend admin usa para cada componente (não assumir que segue a estrutura de diretórios).
2. Para endpoints em diretórios bloqueados pelo `.htaccess` (ex: `claude/`), confirmar se existe exceção ativa e se o frontend aponta para o caminho real.
3. Validar o health check (`?health=1`) de cada endpoint de agente/IA, verificando `env_loaded: true` em produção.
4. Verificar o `dirname(__DIR__, N)` em endpoints que carregam `.env` — o N deve corresponder ao número de diretórios de profundidade do arquivo em relação à raiz do release.

## Mudança deste overlay
Quando uma auditoria revelar uma regra estrutural e duradoura que não está adequadamente documentada em outra fonte autoritativa, atualize este overlay no mesmo fluxo de PR. Não copie detalhes temporários, secrets ou estado operacional volátil.
