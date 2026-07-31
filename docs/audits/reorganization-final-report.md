# Relatório final da reorganização do repositório

Data de consolidação: 2026-07-30/31

## Resultado

A reorganização crítica de agentes, automações e caminhos executáveis foi executada por fases pequenas, com checks e artifacts vinculados aos respectivos SHAs. O PR amplo `#599` foi fechado sem merge e preservado em `archive/pr-599-reorg-2026-07-30`.

O sucesso deste relatório cobre somente a árvore atual, os workflows, os entrypoints canônicos e os gates. Ele não comprova revogação de credenciais em provedores nem limpeza do histórico Git.

## Fases integradas

### Governança

- PR `#600`, merge `e0dfafd8f3d224eb41db96748fc93a2263ed0a1a`.
- Auditoria horária no minuto 17, gate fail-closed, testes e artifacts obrigatórios.

### Workflows ativos

- PR `#602`, merge `c91d66042e0b5ea21476a339192ee161d0b7abb1`.
- Removeu escrita automática, sucesso mascarado e gatilho Shopee por `push`.
- PR `#609`, merge `3f794a93177322ffbcceb2f783801b4ab93e32ad`.
- Removeu o workflow automático de migração.
- Governance run `30593455334`, artifact `8779196563`.
- Auditoria de agentes `30593455347`, artifact `8779199201`.

### Agentes e filas

- PR `#605`, merge `a42807ae0a9d0bba23642305e7088a6f52208aec`.
- Estados canônicos e conclusão somente em `completed_verified` com `run_id`, artifact, commit SHA e verificação.
- Governance run `30593287014`, artifact `8779133789`.
- Auditoria de agentes `30593287031`, artifact `8779134690`.

### Consolidação por domínio

Olist:

- PR `#611`, merge `892954bbec0d73dd24bd2f00b8d539ac0841dcff`.
- Entry points canônicos em `scripts/marketplace/olist/` e remoção do login legado inseguro.
- Governance run `30593807509`, artifact `8779322992`.
- Auditoria de agentes `30593807499`, artifact `8779326658`.

IA aposentada:

- PR `#612`, merge `d62a30a243b93a3bf6e40bf4a23681b7e097614c`.
- Entry points canônicos em `scripts/ai/`, todos fail-closed.
- Governance run `30594027510`, artifact `8779407279`.
- Auditoria de agentes `30594027519`, artifact `8779408399`.

Shopee legado:

- scripts com credenciais embutidas foram substituídos por wrappers para `scripts/marketplace/shopee/retired_credential_tool.py`;
- os wrappers terminam com código 2, estado `blocked`, `external_operation_performed=false` e artifact;
- nenhum desses caminhos chama API, navegador ou troca de token.

### Manutenção e documentação

- `scripts/maintenance/system_health_check.py` é o health check canônico.
- `scripts/maintenance/finalize_reorganization.py` valida estrutura, wrappers, workflows, arquivos privados rastreados e credenciais literais.
- documentos históricos da raiz são inventário não executável e não servem como prova de execução.

## Credenciais removidas da árvore atual

A varredura final encontrou e removeu valores de:

- webhook Olist;
- webhook Mercado Pago;
- parceiro, sandbox, authorization code, access token e refresh token Shopee;
- aplicação TikTok;
- webhook Tiny em query string;
- access token e refresh token Melhor Envio em `storage/private/`;
- exemplos com formato de token GitHub/OpenAI.

O arquivo privado do Melhor Envio foi removido e `storage/private/` permanece ignorado. O verificador final bloqueia qualquer arquivo rastreado nesse diretório.

Os documentos agora usam apenas nomes de secrets e placeholders explícitos. O scanner final cobre formatos conhecidos, JWTs, valores literais associados a campos sensíveis e blocos PEM completos, sem registrar o valor encontrado.

## Ações externas obrigatórias

Todas as credenciais acima devem ser tratadas como comprometidas. Ainda é necessário, fora do repositório:

1. revogar e rotacionar os valores nos respectivos provedores, incluindo Melhor Envio;
2. armazenar substitutos somente em secrets protegidos;
3. validar as integrações por execução real e read-back;
4. planejar a reescrita coordenada do histórico depois da revogação;
5. comunicar colaboradores antes de qualquer force-push de histórico.

A sanitização da árvore atual não invalida credenciais e não apaga commits antigos.

## Contrato final de sucesso

O workflow `Repository Governance` deve executar compilação, testes, health canônico, auditor de mudanças, auditor global de workflows, scanner de credenciais e verificador final. O merge somente é permitido quando:

- todos os checks estiverem verdes;
- o relatório tiver `status: success` e `blocking_finding_count: 0`;
- os artifacts estiverem ligados ao SHA revisado;
- não houver PR antigo de reorganização aberto.
