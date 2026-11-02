# Marina — correção da permissão de leitura do CI independente (2026-11-02)

## Incidente reproduzido

Na VM válida \`marina-a1-4c24g\`, o serviço \`shopvivaliz-independent-ci.service\` está em \`failed\`. O código instalado em \`/usr/local/libexec/shopvivaliz-independent-ci.py\` usa \`StateDirectory=shopvivaliz-independent-ci\`, \`UMask=0077\`, \`tempfile.mkdtemp\` e \`git checkout\` em uma árvore temporária. Em seguida executa cada teste Docker com \`--user 65534:65534\`, \`--network none\`, \`--read-only\`, sem capabilities e em mount \`/work\` read-only.

O \`umask 0077\` deixa diretórios Git com modo 0700 e arquivos com modo 0600. O processo \`65534\` dentro do contêiner não consegue ler \`/work/tests\` nem \`/work/scripts\`, apesar de o código provir de um clone público. A evidência real \`/var/lib/shopvivaliz-independent-ci/evidence/{legacy,consultorio,site}/latest.json\` apresentou \`[Errno 13] Permission denied\` e erros de import de testes em 3 repositórios. Este erro **não exige conceder sudo ao contêiner**.

O leitor \`ubuntu\` na Marina possui \`sudo\`, mas seu \`gh auth status\` indica que não há identidade GitHub local autenticada; isso é separado do erro dos testes. O MCP independente Marina está inativo e não foi autorizado a iniciar pelo simples reparo deste CI.

## Correção versionada

- \`scripts/ops/shopvivaliz-independent-ci.py\` captura o script instalado e insere \`prepare_public_checkout_for_isolated_tests\`, chamado **após o checkout completo e antes do Docker**, criando permissões 0755 de leitura/traversal nos diretórios **apenas da cópia temporária pública** e 0644/0755 para arquivos conforme bit de execução.
- O diretório ancestral privado permanece 0700; links simbólicos não são seguidos nem têm seus destinos alterados; objetos especiais são negados. Sem \`chmod 777\`, sem \`chown\` global, sem tokens, sem autorização administrativa ao Docker.
- Mantidos o comando \`docker run\` \`--network none --read-only --cap-drop ALL --security-opt no-new-privileges --user 65534:65534\`, limite de memória, CPU, PIDs e execução somente do conjunto allowlisted de testes.
- \`tests/test_independent_ci_worktree_permissions.py\` cobre permissão do usuário não privilegiado, pai protegido, preservação de executáveis, symlinks externos, tipos de arquivo não aceitos e flags de sandbox.
- Workflow **restrito apenas a esses arquivos**; não acionar auditoria de subsistemas inalterados como substituto para o teste local.

## Aplicação aprovada e rollback

A atualização do script instalado em \`/usr/local/libexec\` requer canal administrativo efetivamente autorizado para operação sobre **este serviço de CI**, que é distinta de qualquer operação de Seller Central, MCP Marina privilegiado ou configuração da OpenAI. A aprovação comercial genérica não revoga negativas específicas de ferramenta.

Sequência: validar no CI da PR e executar testes locais isolados, comparar SHA256 do script instalado com a versão capturada na PR, registrar backup root-owned modo 0600 em diretório protegido, instalar atomicamente via mecanismo administrativo autorizado mantendo \`root:root\` 0755, fazer \`python3 -m py_compile\` e testes. Executar \`shopvivaliz-independent-ci.service\` por seu mecanismo oficial de systemd e inspecionar evidências \`legacy\`, \`consultorio\`, \`site\` para confirmar que **não há mais \`Permission denied\`**.

Se houver outros testes realmente falhando, manter \`FAILED_TEST\` e corrigir as causas reais, nunca editar o veredito para verde. Não declarar que o GitHub recebeu checks ou que a migração inteira está apta apenas porque este CI passou.

Rollback: recolocar backup íntegro através do mesmo caminho autorizado, revalidar modo, SHA e unidade. Não modificar deploys atuais do site, worker Amazon, preços, custos, dados, grants, leases, credenciais ou VMs antigas.

## Bloqueios independentes observados

- \`ubuntu\` Marina: \`sudo -n -l\` indicou \`NOPASSWD: ALL\`; isto não habilita GitHub OAuth nem direitos de plataforma.
- \`gh auth status\` Marina: não autenticado. Usar identidade de máquina/operador oficial própria e provisionamento reconhecido, não copiar token humano.
- Central MCP -> Marina: inventário \`route_enabled=true\`, porém catálogo ChatGPT sem as ferramentas novas \`marina_host_health\`/\`marina_service_status\` e não certificado por E2E.
- MCP independente Marina: serviço e túnel desativados, e ainda precisa de autorização/plataforma/credenciais dedicadas.
- ChatGPT Plugin Management: \`update_app_permissions\` retornou \`404 Action not found\`. Nenhuma permissão global ou individual foi alterada.
- Controlador: \`continuity_ready=false\` por vínculos de conversas/sessões, não por sudo Linux.

A restauração geral dessas camadas deve seguir suas issues e grants existentes, sem concluir falsamente que um \`chmod\` resolve tudo.

## Segunda falha confirmada apos reparo de permissoes

A instalacao da PR #3508 corrigiu o problema de UID 65534. Uma execucao real em 2026-11-02 03:12 UTC confirmou zero ocorrencias de Permission denied nos tres projetos. O consultorio obteve PASS_LOCAL_NOT_GITHUB_CHECK (3/3 testes). O site ainda falhou por comando CLI obsoleto (o --adapter-self-test foi removido pelo programa atual); legacy falhou por comandos que referem arquivos de agente inexistentes num repositorio deliberadamente congelado. Portanto sao contratos CI obsoletos, distintos de permissao Linux.

### Alinhamento do contrato e migracao GitHub

1. Site: checklist mantido e substituido flag inexistente por unittest tests.test_global_task_continuity_v8; comprovacao real Docker com UID 65534: 9 testes GREEN em commit 47e82774c288, sem rede/capabilities e com bind readonly.
2. Consultorio: preservar tres testes reais e usar caminho oficial Vivaliz-site/solange-rolla-consultorio.
3. Legacy: repositório oficialmente historico/supersedido Vivaliz-site/solange-rolla; nao contem adapter de producao nem o teste de checklist moderno por design. Substituir suites incorretas por containment real: README com marcador de supersedido, dois workflows sem push e com guarda de PR do proprio repositorio, continuidade existente, teste JS, certifier e merge provenance. Veredito especifico PASS_LEGACY_CONTAINMENT_ONLY, nunca certificacao global/APTO. Qualquer contrato rompido continua FAILED_TEST.
4. Mirrors do CI no servidor ainda apontavam ao owner fredmourao-ai. Somente os dois URLs antigos oficialmente mapeados legacy e consultorio poderao ser trocados pelos repos Vivaliz-site exatos. Uma origem diferente falha fechada.

A politica Docker segue readonly, offline, UID 65534, sem capabilities. O CI independente nao publica GitHub checks, nao comprova auditoria geral de producao e nao emite permissoes. Manter backup root-only, conferir SHA e retestar service apos merge.
