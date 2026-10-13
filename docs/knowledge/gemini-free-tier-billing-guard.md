# Gemini Flash em segundo plano — verificação da gratuidade

## Política obrigatória

A ordem do roteador de continuidade continua ChatGPT comum/Work, depois
Gemini Flash e Ollama para CLI, com Codex/Claude apenas mediante autorização
expressa e nunca em jobs persistentes. Gemini Flash pode ser elegível ao
nível gratuito, mas um projeto pago pode faturar o mesmo modelo.
**O nome do modelo e a presença da chave não provam uso gratuito.**

FREE_TIER_BILLING_GUARD_V1 impede invocações Gemini em background a
menos que exista comprovação recente da coluna Billing tier = Free no
Google AI Studio para a combinação exata de projeto e chave. Sem prova,
o wrapper retorna background_gemini_error=free_tier_not_verified
antes de iniciar Gemini CLI. O roteador tenta Ollama local em seguida,
preservando o checkpoint. Não habilitar billing automaticamente.

## Como verificar sem gerar tokens

1. Usar a conta Google autorizada e abrir
   https://aistudio.google.com/api-keys e a lista de projetos do AI Studio.
2. Identificar a chave usada pela VM através da referência protegida
   e confirmar a qual projeto ela pertence, sem revelar seu valor.
3. Confirmar visualmente no mesmo projeto Billing tier: Free.
   Não marcar Free se constar Tier 1, Tier 2, Paid ou ficar incerto.
4. Só após essa evidência, na VM correspondente, executar o helper
   scripts/attest-gemini-free-tier.py com --confirm-free-tier,
   --project-id PROJECT_ID e --key-source GEMINI_API_KEY ou GOOGLE_API_KEY,
   conforme a chave verificada. Ele lê a chave apenas localmente
   e persiste seu SHA-256. Nunca passar o valor no terminal, log ou Git.
5. Verificar sem tráfego com `scripts/attest-gemini-free-tier.py --status`.

**Fontes de credenciais distintas:**
- **Backend/controlador:** o instalador empacota o helper no diretório de scripts
  da release imutável `/opt/shopvivaliz-gemini-24x7-controller/releases/<SHA>/scripts/`.
  Execute o helper protegido com
  `--env-file /home/ubuntu/.config/shopvivaliz-gemini-24x7/gemini.env --status`
  (ou defina `GEMINI_ENV_FILE` apontando para essa mesma referência).
- **Site/produção web:** o helper no checkout de deploy utiliza por padrão
  `/home/ubuntu/shopvivaliz-deploy/shared/.env`.

A variável `GEMINI_ENV_FILE` é reconhecida tanto pelo executor quanto pelo
helper de prova; a opção explícita `--env-file` tem prioridade. O helper
somente lê a credencial *localmente*, divulga unicamente status e quantidade
aprovada e não chama modelos. A ausência de prova deve continuar resultando
em `UNVERIFIED`; não inventar atestado por nome Flash, presença de chave,
ausência de fatura ou silêncio do monitor.


O registro privado fica em
/home/ubuntu/shopvivaliz-deploy/shared/ai-free-policy/gemini-free-tier.json
com modo 0600, fora dos repos e releases. A comprovação vale por 30 dias
somente para chaves com hash correspondente. A troca de chave, projeto
ou billing requer nova verificação.

O helper não confirma o billing remotamente; apenas documenta a inspeção
humana no painel oficial. Nunca criar atestado automaticamente pela
ausência de fatura, por geração de teste ou pelo nome do modelo.

## Validação

Sem o atestado, executar o wrapper em modo background com prompt local
inofensivo retorna 78 antes de fazer chamada Gemini e o roteador segue
para Ollama. O monitor shopvivaliz-ai-free-guard.timer deve continuar
habilitado nas duas VMs. Não editar releases imutáveis diretamente.

Referências oficiais:
- https://ai.google.dev/gemini-api/docs/billing/
- https://ai.google.dev/gemini-api/docs/pricing


## Default gratuito comprovado em producao (2026-10-12)

O roteador de jobs persistentes prioriza gemini-3.1-flash-lite por padrao:
o projeto gen-lang-client-0341449504 foi conferido em Google AI Studio
como nivel Free, sua chave em producao foi atestada por hash, e tanto
generateContent via API (HTTP 200, 9 tokens) quanto a CLI protegida
retornaram sucesso com este modelo. Outros aliases Flash mostraram
excesso de cotas ou travamentos e nao devem voltar a ser o padrao
sem nova verificacao. O fallback Gemini padrao e o proprio modelo
3.1 Flash Lite, removido por deduplicacao: uma falha ou timeout leva
imediatamente ao Ollama local. Modelos do nivel pago continuam proibidos
para jobs persistentes. Faturamento nao e habilitado automaticamente.

## Timeout seguro e continuidade Ollama

O executor run_background_gemini.py não pode aguardar o CLI indefinidamente.
GEMINI_BOUNDED_RUN_V1 executa cada tentativa Gemini em grupo de processos
isolado, com entrada-padrão fechada e 60 segundos por tentativa por
padrão. GEMINI_BACKGROUND_TIMEOUT_SECONDS pode alterar o teto dentro do
intervalo de 15 a 90 segundos. Em expiração, encerra apenas esse grupo
privado de processos (TERM e, se necessário, KILL), descarta saída parcial,
registra request_timeout e retorna o código 124 para o roteador.

Uma tentativa travada não dispara novas tentativas com modelos Gemini;
o roteador continua para Ollama local, sem Codex ou Claude automáticos.
A operação não desliga o serviço Gemini, não altera sessões humanas e
não pode sobrescrever um atestado Free inválido ou ausente.

Validação: python3 -m unittest -q tests.test_gemini_cli_timeout
usa apenas executáveis fictícios sem rede para testar tempo máximo,
limpeza do grupo e recusa de nova tentativa. O teste de ponta a ponta
deve usar um repositório Git temporário isolado com um CLI fictício que
dorme, seguido da conclusão consultiva pelo Ollama local.
