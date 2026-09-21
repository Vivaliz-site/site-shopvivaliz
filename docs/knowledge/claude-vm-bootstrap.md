# Claude VM — bootstrap operacional global

Este documento é o bootstrap persistente do Claude Code usado nos projetos ShopVivaliz. Ele deve ser carregado em toda nova sessão na VM pelo `~/.claude/CLAUDE.md`.

## Ordem obrigatória antes de agir

Antes de diagnosticar, editar, testar, operar infraestrutura ou responder sobre estado atual:

1. leia `/home/ubuntu/shopvivaliz-deploy/current/docs/knowledge/host-access.md`;
2. leia `/home/ubuntu/shopvivaliz-deploy/current/docs/knowledge/README.md`;
3. leia `/home/ubuntu/shopvivaliz-deploy/current/docs/knowledge/agent-rules.md`;
4. leia `/home/ubuntu/shopvivaliz-deploy/current/REGRAS-AGENTES-CENTRALIZADAS.md`;
5. leia o `CLAUDE.md`, `AGENTS.md`, README/runbook e documentação específica do repositório alvo;
6. confirme evidência viva antes de assumir host, branch, release, serviço, modelo, credencial configurada ou estado operacional.

A documentação orienta, mas código, logs, banco, workflows e probes reais prevalecem quando há divergência. Nunca invente estado atual.

## Mapa de hosts

- `shopvivaliz-free-a1`: produção web e deploy do ShopVivaliz; privado `10.0.1.112`.
- `always-free-arm-1787907847-26`: backend/MEI/M365/relay e VM de navegação; privado `10.0.1.38`, sem IP público.
- `shopvivaliz-ai` / `137.131.156.17`: legado DEV/e-mail/testes; nunca produção web.
- `Fred-Win` e `KOCEPSV`: hosts Windows de apoio/acesso; não são o local padrão para navegador de agentes.

Use a fonte canônica `docs/knowledge/host-access.md` antes de qualquer operação, pois endereços e papéis podem mudar.

## Regra de navegador

Para tarefas ShopVivaliz, navegador de agente deve executar na VM destinada à navegação. Não use Opera Connector nem navegador visível/headless nos hosts Windows como caminho operacional. Os hosts Windows servem apenas de apoio/acesso remoto quando necessário.

Se uma tarefa exige browser, prefira a sessão/browser já provisionada na VM e siga o runbook do projeto. Não abra sessões ocultas abandonadas; identifique tarefa/sessão, use TTL quando aplicável e encerre ao concluir.

## Portfólio de projetos

Projetos conhecidos incluem, no mínimo:

- `Vivaliz-site/site-shopvivaliz` — storefront, admin, integrações e AI Squad;
- `Vivaliz-site/amazon-returns-safet` — Amazon Returns / SAFE-T;
- `Vivaliz-site/mercadolivre-returns-recovery` — recuperação de devoluções Mercado Livre;
- `fredmourao-ai/mei-mg-email` — automação de e-mail MEI-MG;
- `fredmourao-ai/solange-rolla-consultorio` — sistema do consultório.

Esta lista é índice, não substitui o repositório real. Ao atuar em qualquer projeto, localize o checkout/repositório canônico e leia primeiro as instruções próprias dele. Nunca propague automaticamente regras de negócio de um projeto para outro.

## Pesquisa técnica e programação na web

Em tarefas de programação, infraestrutura, APIs, bibliotecas, frameworks, segurança, modelos de IA, CLI, cloud ou integrações externas:

- consulte a web quando a resposta depender de versão, comportamento atual, compatibilidade, depreciação, bug conhecido, sintaxe recente ou documentação que possa ter mudado;
- priorize documentação oficial, especificações, release notes/changelogs, repositórios oficiais e documentação do fornecedor;
- para bugs/comportamentos não documentados, complemente com issues oficiais e fontes técnicas reputadas, distinguindo fato oficial de relato comunitário;
- use pesquisa web antes de assumir nomes de modelos, flags CLI, endpoints, limites, parâmetros, versões, status de depreciação ou comportamento de SDK/API;
- para conceitos estáveis e triviais, pesquisa web não é obrigatória; para qualquer detalhe materialmente atualizável ou incerto, pesquise;
- quando usar informação externa para decidir implementação, registre a fonte relevante no PR/doc/relatório técnico quando isso melhorar auditabilidade;
- não copie soluções da web cegamente: confronte com o código real, versão instalada, testes e constraints do projeto.

Claude Code deve usar WebSearch/WebFetch quando disponíveis e úteis. A ausência de acesso web não autoriza adivinhação: marque a lacuna como não verificada e use evidência local até o acesso ser restaurado.

## Desenvolvimento e mudanças

- Use @Superpowers em cada etapa material quando a capacidade estiver disponível; se não estiver, aplique metodologia equivalente e registre a indisponibilidade.
- Investigue antes de editar. Leia os arquivos mencionados pelo usuário e os contratos/testes relacionados.
- Não edite `/home/ubuntu/shopvivaliz-deploy/current` nem release ativa.
- Mudança versionada termina somente após branch, testes, push, PR, checks, merge, deploy pelo gate e validação pós-deploy quando aplicável.
- Não use force-push/bypass sem regra explícita; não esconda failures.
- Não declare sucesso com HTTP 200, arquivo existente ou secret presente isoladamente; valide o efeito funcional.
- Preserve isolamento de sessão e evite trabalho concorrente no mesmo repo/host.

## Segurança

Nunca imprimir, versionar, copiar para docs ou repetir em chat valores de senhas, tokens, cookies, chaves privadas, seeds TOTP, OTPs ou secrets. Use apenas stores e caminhos protegidos já provisionados. Verifique primeiro as fontes seguras existentes antes de pedir credencial novamente ao usuário.

## IA e continuidade

Claude/GPT/Codex pagos são para tarefas finitas, não para daemon, cron, watcher ou loop recorrente. Toda execução paga deve ter objetivo, timeout/limite e condição de saída.

Ao retomar uma tarefa, recupere o último checkpoint comprovado (branch/PR/SHA/log/issue) e continue dali; não reinicie trabalho concluído nem assuma sucesso sem evidência.
