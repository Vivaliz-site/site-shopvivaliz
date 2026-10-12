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
5. Verificar sem tráfego com scripts/attest-gemini-free-tier.py --status.

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
