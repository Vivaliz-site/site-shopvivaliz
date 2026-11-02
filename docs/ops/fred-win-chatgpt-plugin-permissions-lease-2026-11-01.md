# Autorizacao de manutencao GUI do ChatGPT no Fred-Win (2026-11-01)

## Finalidade e evidencia
O proprietario solicitou que a permissao GLOBAL de execucao dos plugins do ChatGPT seja **Permitir todas as acoes / Nunca perguntar**. A pagina de administracao `admin.openai.com` ja mostra 70 ferramentas ShopVivaliz habilitadas, mas isso nao altera as permissoes de execucao no ChatGPT. Leitura oficial dos plugins ShopVivaliz Remote Control, RDC 1, RDC 2 e GitHub constatou `Allow low-risk actions` global com `Use my default` em cada plugin.

A acao oficial de atualizacao de permissao do ChatGPT retornou `404 Action not found`, sem aplicar qualquer mudanca. O Chrome autenticado no laptop `LAPTOP-NIG4IFUU` esta aberto, mas `desktop_click` do MCP central recusou `runtime_lock_required`. Esse controle NAO deve ser contornado por PowerShell, RDC direto, SSH, outra identidade, workflow ou endpoints privados.

## Grant solicitado (PR de seguranca exclusivo)
- Recurso: `mcp:fred-win:plugin-permissions`.
- Principal: `shopvivaliz-central-mcp` no controlador `always-free-arm-1787907847-26`.
- Host exclusivo: `Fred-Win` (laptop `LAPTOP-NIG4IFUU`).
- Capacidade: `desktop_plugin_permission_maintenance`, operacao unica `configure_chatgpt_plugin_permissions`.
- Escopo comercial/operacional: abrir/reutilizar o Chrome autenticado do operador e acessar SOMENTE as configuracoes oficiais de plugins/permissoes para definir o padrao global para `full_access`, se disponibilizado pela plataforma. Sem login em contas diferentes, sem tocar em conversas, Amazon Seller Central, chaves, scripts de seguranca, MFA, rede, root, gastos ou PRs.
- Acoes tecnicas a incluir no lease: `desktop_open`, `desktop_click`, `desktop_type`. A interface de desktop e ampla; por isso a autorizacao e de USO UNICO, janela curta de 180 segundos, provas de tela, auditoria e liberacao do lock ao final.
- Prazo de validade da autorizacao: `2026-11-08T00:00:00Z`. O lease efetivo dura no maximo 180 segundos e nao pode sobrepor outro lease vivo.
- A aprovacao PR **nao configura a OpenAI**, nao garante que `full_access` esteja disponibilizado para o workspace e nao supera recusas de seguranca. A operacao so ocorre pela UI oficial depois do grant e lease validos.

## Sequencia obrigatoria (fail-closed)
1. Agente cria PR Ready for review, com `security/owner-approvals/<PR>.json`, testes negativos e este plano. Solicitar revisor nominal `shopvivaliz-human`. Nenhum agente pode autenticar como essa conta.
2. Somente `shopvivaliz-human` registra review `APPROVED` no HEAD final e realiza merge pessoal no `main` ou habilita auto-merge nativo pessoal conforme gates aplicaveis. Nao fazer merge/agente no grant.
3. No backend ativo, um operador autorizado chama o emissor especifico com identidade nao-humana permitida. Validar SHA e objeto da PR, prova do review exclusiva, merge, grant imutavel presente no `main`, prazo, host e escopo exatos via verificador canonico `scripts/validate-owner-pr-grant.py`. Qualquer erro, negar.
4. Adquirir lock canonico `runtime_lock.acquire_runtime_lock` como `maintenance` com owner `shopvivaliz-plugin-permissions:<PR>`, acoes de desktop exatas, TTL=180, fencing incremental e exclusividade. Nao apagar/recriar lock existente. Gravar comprovante de emissao uma vez so em diretorio protegido.
5. Usar `desktop_*` exclusivamente com lease/fencing retornados, sobre `Fred-Win`, na UI oficial de Configuracoes de Plugins do ChatGPT. Ler pagina, aplicar e salvar se o modo estiver disponivel. Nenhuma outra acao no desktop fica coberta pelo PR.
6. Validar persistencia por `get_app_permissions` para ShopVivaliz, RDC 1/2 e GitHub: estado global `Allow all actions`, nunca apenas o painel admin das ferramentas. Apos leitura, liberar lock e documentar audit IDs.
7. Se a UI negar o modo, se o provider mantiver 404 ou a ferramenta recusar, registrar `BLOCKED_EXTERNAL` e o procedimento oficial necessario sem modificar estados por outra rota.

## Integridade, isolamento e rollback
- O script emissor so deve estar ativo apos merge aprovado, em fonte limpa e no backend correto, sem senha/token em logs. Token Github de agente nao equivale a identidade humana; o verificador consulta a API oficial.
- O grant e uma autorizacao humana, mas a PR nao emite um lease por si. Emissao e liberacao ocorrem pelo mecanismo canonico com fencing; lock ocupado deve ser respeitado ate expiracao/liberacao legitima.
- Ausencia de `GITHUB_TOKEN`/identity protegida, grant alterado, SHA errado, PR ainda aberta, review nao humano, merge por agente, principal errado, prazo expirado ou scope diferente -> negar sem efeito.
- Rollback: expirar/revogar este grant em PR de seguranca posterior, desativar/emissor se necessario, liberar lease legitimamente e deixar modo global anterior se houver falha antes do salvamento. Nao automatizar alteracao de permissao no servidor sem API oficial.

## Vinculo operacional
Demanda Amazon promocao: Vivaliz-site/amazon-returns-safet#553. A habilitacao global das ferramentas e das permissoes de ChatGPT sao camadas diferentes, e qualquer bloqueio externo de criacao de cupons permanece valido independentemente desta PR.
