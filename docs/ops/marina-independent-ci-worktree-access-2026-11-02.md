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
