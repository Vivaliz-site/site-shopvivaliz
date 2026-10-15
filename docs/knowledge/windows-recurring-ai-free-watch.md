# Windows: vigia de checkpoints sem IA paga

## Incidente confirmado em 12/10/2026

No DESKTOP-KOCEPSV, a tarefa agendada ShopVivaliz Fairplay 20019958
Continuity executava PowerShell a cada cinco minutos (PT5M). O wrapper
local chamava um comando de execucao Codex sem verificar o checkpoint
waiting_external, que permanecia inalterado desde 05/10/2026.

O banco SQLite do Codex mostrava 288 sessoes exec por dia em 10 e 11/10,
mais novas sessoes em 12/10. As tentativas mais recentes registravam zero
tokens. Isto comprova a repeticao de inicializacoes, nao a fatura do
provedor; contadores cumulativos devem ser tratados com cuidado.

Em 12/10 foi feito backup local do XML do agendamento, do run.ps1 e do
state.json e desabilitada a tarefa automatica paga para evitar recorrencia.
As sessoes interativas nao foram encerradas.

## Politica e codigo

scripts/windows/checkpoint-free-watch.ps1 e uma verificacao local
somente de leitura, sem bibliotecas externas, rede ou geracao de tokens.

- completed, cancelled, waiting_external, human_action_required,
  blocked_external: codigo 0 sem inferencia, sem alterar state.json.
- ready, running, pending: codigo 75 sinalizando que um executor gratuito
  e autorizado sera necessario; nunca invocar Codex/Claude em background.
- estado invalido, ausente ou JSON ilegivel: codigo 78 (fail closed).

Se uma mensagem externa chegar, a evidencia deve ser validada pelo agente
autorizado antes de atualizar o checkpoint. A vigia nao consulta caixa de
email nem realiza operacoes por conta propria e nao deve alegar que resolveu
uma pendencia de transportadora.

## Retomada segura do agendamento

1. Verificar o commit aprovado no GitHub e obter o script exatamente
   a partir dele, preservando o arquivo original e o XML ja salvos.
2. Instalar o watcher como free-watch.ps1 no diretorio privado da tarefa
   C:\site-shopvivaliz\.codex-local-jobs\fairplay-20019958.
3. Substituir run.ps1 por um wrapper exclusivo que invoque free-watch.ps1
   com -JobRoot e propague seu codigo de saida, sem fallback pago.
4. Testar no Windows os seis casos de checkpoint: completed,
   waiting_external, ready, running, estado desconhecido e JSON invalido.
5. Reabilitar o agendamento somente apos esses testes. Confirmar
   ao menos uma execucao natural PT5M, codigo 0 no estado atual,
   e ausencia de novas sessoes Codex desde a reabilitacao.
6. Nunca reiniciar sessoes ou remover o historico antigo de runner.log.

A tarefa pausada waiting_external depende de um novo evento verificavel
externo para progredir. A leitura periodica de state.json nao substitui
uma consulta ao transportador ou um agente com controle de acesso.
