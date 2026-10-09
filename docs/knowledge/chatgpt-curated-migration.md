# Migração curada de contexto ChatGPT

Este documento define como transferir contexto útil de uma conta ChatGPT ShopVivaliz para outra sem copiar indiscriminadamente o histórico.

## Objetivo

Preservar continuidade operacional e decisões duráveis, excluindo chats irrelevantes, repetitivos, obsoletos ou sem valor futuro. O repositório e a documentação canônica continuam sendo a fonte de verdade; histórico de chat é evidência auxiliar, não autoridade superior.

## Bootstrap da conta nova

A conta nova deve começar por:

1. `docs/knowledge/atendimento-chatgpt-bootstrap.md`;
2. `docs/knowledge/host-access.md`;
3. `docs/knowledge/README.md`;
4. `docs/knowledge/agent-rules.md`;
5. `docs/knowledge/project.md`;
6. documentação específica do projeto/tarefa.

Não copiar memória privada de outra conta como substituto dessas fontes.

## O que migrar

Migrar somente conteúdo que altere decisões futuras ou evite retrabalho:

- decisões arquiteturais e operacionais ainda válidas;
- estado atual comprovado de projetos ativos;
- repositórios canônicos e relações entre eles;
- regras permanentes dos agentes e critérios de conclusão;
- mapa não secreto de hosts, serviços, deploy e rotas de acesso;
- procedimentos de diagnóstico, correção, testes, deploy, rollback e validação E2E;
- tarefas abertas, dependências e próximo passo comprovado;
- incidentes relevantes quando a causa raiz, correção ou prevenção ainda forem úteis;
- integrações, plugins e conectores necessários, distinguindo instalação de autenticação real;
- automações recorrentes relevantes;
- evidências duráveis: relatórios E2E, auditorias, checkpoints e decisões com fonte verificável;
- preferências de trabalho que realmente mudem o comportamento dos agentes.

## O que não migrar

Excluir da base operacional:

- chats casuais ou pessoais sem relação com trabalho futuro;
- perguntas pontuais já resolvidas sem decisão reutilizável;
- tentativas intermediárias, mensagens repetidas e loops de troubleshooting;
- hipóteses refutadas e estados temporários já superados;
- respostas genéricas/fallback sem evidência;
- duplicatas de conteúdo já consolidado na documentação canônica;
- logs extensos quando bastar preservar conclusão, causa raiz e referência da evidência;
- contexto obsoleto que contradiga evidência atual.

Quando houver dúvida, preferir consolidar a decisão final em documentação em vez de importar a conversa inteira.

## Segredos e autenticação

Nunca migrar ou versionar:

- senhas;
- tokens/API keys;
- private keys;
- seeds TOTP ou OTPs;
- cookies/session storage;
- códigos de recuperação;
- conteúdo de secrets.

Migrar somente a referência não secreta ao mecanismo seguro autorizado e o procedimento para utilizá-lo. A conta nova deve reutilizar sessões e fontes seguras provisionadas conforme os runbooks.

## Regra de consolidação

Para cada chat candidato, extrair no máximo:

- assunto/projeto;
- decisão ou fato durável;
- evidência/fonte quando existir;
- estado atual;
- próximo passo ainda aberto;
- documento canônico que deve receber a informação.

Se o chat não produzir nenhum desses itens, ele não precisa ser migrado.

## Prioridade de fontes

Em conflito, usar esta ordem:

1. evidência viva de produção/runtime;
2. documentação canônica atual no repositório;
3. código/configuração versionados atuais;
4. relatórios e checkpoints recentes;
5. histórico de chat.

Nunca preservar uma afirmação antiga apenas porque apareceu em uma conversa.

## Projetos prioritários

A curadoria deve priorizar os projetos e áreas atualmente operacionais, incluindo:

- ShopVivaliz/site e infraestrutura;
- Amazon Returns / SAFE-T;
- Mercado Livre Returns Recovery;
- pipeline e continuidade/automações;
- MEI-MG;
- Solange Rolla no repositório canônico;
- integrações de marketplaces/ERP e rotinas operacionais relacionadas.

Projetos encerrados entram somente se houver regra, decisão ou lição ainda aplicável.

## Validação da conta nova

A migração só é considerada funcional quando uma conversa nova consegue, sem depender do histórico bruto:

1. identificar os repositórios e hosts canônicos;
2. aplicar as regras de segurança e releases imutáveis;
3. localizar documentação específica antes de agir;
4. distinguir estado comprovado de hipótese ou memória histórica;
5. retomar uma tarefa a partir de checkpoint/evidência;
6. executar o ciclo diagnosticar -> corrigir -> prevenir -> testar -> validar;
7. operar sem pedir novamente secrets já provisionados, salvo prova de ausência/invalidez;
8. concluir somente com evidência fresca ou registrar bloqueio externo real.

## Arquivo bruto de exportação

Uma exportação integral da conta antiga pode ser mantida separadamente como arquivo de segurança/auditoria. Ela não deve ser usada como bootstrap principal nem despejada integralmente no contexto da conta nova. Consultar o bruto apenas quando uma informação histórica específica não estiver consolidada nas fontes canônicas.

## Manutenção

Quando um chat novo gerar uma decisão durável, atualizar a documentação canônica correspondente. O objetivo é reduzir progressivamente a dependência de memória de conta e de conversas antigas.
