# Identificação do agente em pull requests — ShopVivaliz

Decisão do proprietário: 08/11/2026. Abrange todos os repositórios e agentes
ShopVivaliz (GPT, Claude, Gemini, Codex, Copilot, Ollama e agentes próprios).

## Convenção obrigatória na criação

1. Toda PR criada por um agente deve começar no **título** com a identidade
   real do agente executor: `[GPT]`, `[Claude]`, `[Gemini]`, `[Codex]`,
   `[Copilot]`, `[Ollama]`, `[Auto-Healer]` ou `[Agente: Nome exato]`.
   Exemplo: `[GPT] fix: corrigir publicação de anúncios`.
2. O título, a descrição e os comentários operacionais produzidos por agentes
   devem permanecer em português brasileiro. Preservam-se comandos, nomes de
   arquivos e termos técnicos quando necessário.
3. Na **descrição da PR**, declarar, sem segredos, os seguintes campos:
   - Agente executor real e modelo, se conhecido;
   - Identidade GitHub utilizada (ex.: `fredmourao-ai`);
   - Agente que iniciou a PR e agentes que posteriormente contribuíram,
     indicando quando o original não for verificável;
   - Escopo e testes executados.
4. A **conta GitHub** é autoria da PR na plataforma, não prova qual IA
   escreveu o código. Bots revisores, como Codex, não são autores por apenas
   revisar. Não atribuir a GPT, Claude ou Gemini sem evidência verificável.
5. Se o agente de origem é desconhecido em PRs **anteriores a esta regra**,
   marcar `[Agente não identificado]` provisoriamente e pedir que a origem
   seja esclarecida, sem inventar autoria. Este rótulo não é permitido para
   novas PRs criadas depois da política.
6. A identidade `shopvivaliz-human` é reservada à revisão humana oficial.
   Uma PR criada por essa conta pode não ter prefixo de agente; isto não
   concede aprovação automática a PRs de agentes.

## Validação e integração

- O workflow `.github/workflows/pr-agent-attribution.yml` é iniciado em
  `pull_request_target: opened` e executa verificação de título com código
  confiável do `main` e token de leitura apenas; **nenhum agente pago**.
- O workflow não altera títulos automaticamente, não aprova PRs e não
  acrescenta nenhum requisito de revisão humana por si só. Tornar seu status
  obrigatório em proteção de branch, se pretendido, exige autorização do
  proprietário e os controles existentes para alteração de governança.
- Não invalidar aprovações antigas nem bloquear retroativamente PRs legadas.
  A identidade do modelo nunca deve ser derivada de comentários de review.
- Para replicar em outros repositórios, distribuir a documentação e o
  verificador através de PRs próprios com as aprovações de governança cabíveis.
  Esta PR no repositório canônico não instala automaticamente o workflow em
  repositórios irmãos.

## Estado de referência em 08/11

- `site-shopvivaliz#3846`: GitHub `fredmourao-ai`; commit do
  agente "Agente Autonomo ShopVivaliz", sem identificação comprovada do modelo.
- `site-shopvivaliz#3835`: GitHub `fredmourao-ai`; GPT contribuiu
  nesta sessão, e o bot Auto-Healer posteriormente fez um merge de branch.
- `amazon-returns-safet#623`: GitHub `fredmourao-ai`; o modelo
  que originalmente criou a PR não está identificado.
