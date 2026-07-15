# DIAGNÓSTICO - AGENTES NÃO RESPONDENDO

## PROBLEMA
Mensagens registradas no webhook mas agentes não respondem.

## POSSÍVEIS CAUSAS

### 1. Workflow não disparando
- monitor-chat-responses.yml roda a cada 2 minutos
- Mas pode estar falhando silenciosamente

### 2. APIs offline
- GEMINI_API_KEY não configurado
- ANTHROPIC_API_KEY não configurado
- OpenAI offline

### 3. Script com erro
- chat-responder.py pode ter erro de sintaxe
- Pode estar usando bibliotecas não instaladas

### 4. Arquivo de respostas não criado
- logs/monitor-responses.jsonl vazio
- Significa agentes nunca executaram

## CHECKLIST

- [ ] Workflow monitor-chat-responses.yml está ativado no GitHub?
- [ ] API keys estão em GitHub Secrets?
- [ ] Script chat-responder.py tem erros?
- [ ] logs/monitor-responses.jsonl tem conteúdo?
- [ ] Há erros no GitHub Actions?

## PRÓXIMA AÇÃO

1. Verificar GitHub Actions logs
2. Verificar se APIs estão configuradas
3. Testar script manualmente
4. Ativar agentes manualmente se necessário

## ALTERNATIVA RÁPIDA

Se agentes estão offline, podemos:
1. Criar uma tarefa manual no tasks-queue.json
2. Disparar executor manualmente
3. Agentes executarão a tarefa
4. Chat funcionará quando voltarem
