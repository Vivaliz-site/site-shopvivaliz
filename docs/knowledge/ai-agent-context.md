# AI Agent Context — ShopVivaliz

Este arquivo é contexto operacional **não secreto** para agentes de IA do ecossistema ShopVivaliz.
Ele complementa, mas não substitui, a evidência ao vivo do código, workflows, logs, banco e hosts.

## Bootstrap obrigatório

Antes de agir materialmente:

1. leia `docs/knowledge/host-access.md`;
2. leia `docs/knowledge/README.md`;
3. leia `docs/knowledge/agent-rules.md`;
4. leia `docs/knowledge/project.md` e o runbook específico da rotina;
5. confirme host, identidade, diretório, branch e estado Git com evidência;
6. nunca assuma que documentação histórica ainda representa produção.

## Hosts canônicos

- `shopvivaliz-free-a1` — produção web/deploy; origin `137.131.149.55`, privado `10.0.1.112`.
- `always-free-arm-1787907847-26` — backend, MEI, M365 e relay; privado `10.0.1.38`, sem IP público.
- `shopvivaliz-ai` / `137.131.156.17` — legado DEV/e-mail/testes; nunca tratar como produção web.

Produção usa `/home/ubuntu/shopvivaliz-deploy` com releases imutáveis. Nunca editar `current/` ou uma release ativa diretamente.

## Navegador

Para tarefas ShopVivaliz, navegador deve executar nas VMs destinadas à automação. Não usar navegador local Windows nem Opera Connector como caminho operacional. Sessões persistentes devem ser identificadas, isoladas por perfil, ter TTL e ser encerradas quando não forem mais necessárias.

O fallback ChatGPT web usa o perfil persistente exclusivo `ai-squad-chatgpt-fallback` no browser worker da VM backend.

## Repositórios e projetos principais

- `Vivaliz-site/site-shopvivaliz` — e-commerce, admin, AI Squad, deploy, integrações e automações centrais.
- `Vivaliz-site/amazon-returns-safet` — Amazon Returns / SAFE-T, recuperação e reconciliação.
- `Vivaliz-site/mercadolivre-returns-recovery` — devoluções/recovery Mercado Livre.
- `fredmourao-ai/mei-mg-email` — automação de e-mail/MEI e integrações M365.
- `fredmourao-ai/solange-rolla-consultorio` — aplicação Solange Rolla.
- `Vivaliz-site/amazon-returns-safet` e `Vivaliz-site/mercadolivre-returns-recovery` têm seus próprios contratos e gates; não inferir regras de um para o outro.

Sempre verificar o repositório específico antes de alterar código.

## Regras operacionais essenciais

- usar evidência ao vivo; não aceitar falso-verde;
- auditorias extremas exigem cobertura integral aplicável, revisão contraditória e pós-deploy;
- alterações versionadas terminam somente após branch, testes, PR, checks, merge, deploy aplicável e validação pós-merge;
- nunca expor ou registrar senhas, tokens, chaves, cookies, OTPs ou conteúdo de secrets;
- preferir Remote Desktop Commander pelos nomes dos hosts; SSH público direto está desabilitado;
- ações destrutivas/irreversíveis exigem salvaguardas e autorização aplicável;
- não inventar preço, estoque, frete, disponibilidade, estado de integração ou sucesso operacional;
- `configured=true` não comprova autenticação; health deve provar o contrato real do componente;
- para Squad Chat, health válido exige simultaneamente `ok=true`, `endpoint=squad-chat` e `providers`.

## Pesquisa técnica e programação na web

Quando a tarefa envolver programação, bibliotecas, frameworks, APIs, protocolos, erros, segurança, infraestrutura, CI/CD, banco de dados, navegador, sistema operacional ou comportamento dependente de versão:

1. pesquise a web antes de concluir quando houver chance razoável de a informação ter mudado;
2. priorize documentação oficial, repositórios oficiais, especificações, release notes, changelogs e issues do projeto;
3. use fóruns/comunidade apenas como evidência complementar e identifique incertezas;
4. verifique versão/data aplicável ao ambiente real;
5. cite URLs completas das fontes materiais;
6. não copie solução incompatível com a versão observada;
7. confronte a pesquisa com o código/runtime real do ShopVivaliz antes de implementar;
8. em debugging, busque a mensagem de erro exata e a documentação da versão antes de aplicar workaround.

## Política de contexto

Este arquivo pode ser enviado ao modelo como contexto. Ele não deve conter secrets. Se um detalhe sensível for necessário, o agente deve usar apenas o runtime/secret store autorizado e nunca incluir o valor em prompts, logs ou documentação.
