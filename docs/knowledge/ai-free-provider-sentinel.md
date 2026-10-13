# Sentinela de provedores gratuitos — duas VMs

A política de continuidade de IA prioriza Gemini Flash e Ollama local. Codex
e Claude não devem ser usados automaticamente como fallback em background.
Serviços remotos legítimos (como Claude Remote Control) podem permanecer
disponíveis sem iniciar uma geração.

## Monitoramento

O código versionado em scripts/ai-free-provider-sentinel.py é instalado em
/usr/local/lib/shopvivaliz/ por scripts/install-ai-free-provider-sentinel.sh
e roda pelo timer shopvivaliz-ai-free-guard.timer, aproximadamente a cada
cinco minutos, em shopvivaliz-free-a1 e always-free-arm-1787907847-26.

Verifica, sem gerar texto com modelos:

- serviços essenciais, flags de fallback pagas fixadas em 0 e
  integridade do roteador Gemini/Ollama;
- verificar a protecao FREE_TIER_BILLING_GUARD_V1 do executor Gemini
  e o marcador explicito do modo background no roteador; sinalizar
  GEMINI_FREE_BILLING_GUARD_DRIFT se um deploy remover a protecao.
  Uma chave Free ainda nao verificada nao gera alerta: fallback Ollama
  e o comportamento intencional, sem uso faturavel de Gemini;
- GET /health dos bridges Codex/Claude, que devem anunciar política
  manual_approval_required e modo disabled;
- processos Codex e Claude novos, excluindo somente o processo principal
  do daemon Claude Remote Control;
- alterações em contadores locais da CLI Codex e eventos novos de uso da
  CLI Claude, sem armazenar prompts ou mensagens.

O monitor não consulta endpoints de geração nem ativa provedores pagos.
Uma mudança de contador é um indício de uso, não prova de cobrança, origem
automática ou valor faturado. A elegibilidade do Gemini à cota gratuita
deve ser conferida separadamente no painel oficial de faturamento.

## Alertas e estado

Estado privado em /var/lib/shopvivaliz-ai-free-guard/state.json, modo 0600.
Em caso de irregularidade, emite AI_FREE_PROVIDER_SENTINEL no journal e
comenta no issue de monitoramento Vivaliz-site/site-shopvivaliz#1586.
Suprime repetição de avisos idênticos durante 24h; comenta novamente na
mudança de condições e na recuperação. Falhas de entrega são reintentadas
no próximo ciclo. O monitor nunca encerra processos, altera sessões, troca
senhas ou reinicia bridges.

## Verificação operacional

    sudo systemctl status shopvivaliz-ai-free-guard.timer --no-pager
    sudo systemctl start shopvivaliz-ai-free-guard.service
    sudo journalctl -u shopvivaliz-ai-free-guard.service -n 15 --no-pager
    sudo cat /var/lib/shopvivaliz-ai-free-guard/state.json

Somente códigos sanitizados devem ser publicados no GitHub. Corrigir o
problema sinalizado exige verificar o serviço, a origem da execução e a
autorização. Não desligar sessões humanas legítimas automaticamente.


## Bloqueio de chamadas diretas pagas em automacoes

O validador de governanca scripts/validate-recurring-ai-policy.py inspeciona
workflows recorrentes, gatilhos amplos e scripts referenciados para prevenir
uso pago autonomo. A verificacao inclui chamada direta de claude -p,
claude --print e subprocess.run(["claude", "-p"]), alem de codex exec
(incluindo --remote) e scripts executados com ./scripts/*.sh, inclusive
delegacoes entre scripts. A ausencia de chamada Anthropic SDK nao comprova
que uma rotina e gratuita. Workflows exclusivamente manuais e fluxos
humanos com opt-in e filtro de bots permanecem permitidos, sujeitos as
aprovacoes especificas do provedor.

Historico forense em 2026-10-12: metadados locais de 1.039 sessoes Claude
distintas (04-07/10), com prompts de continuidade muito repetidos,
demonstraram por que o validador nao deve depender apenas de nomes de
SDKs. Numeros de cost-state do CLI sao estimativas locais, nao faturas.
O monitor de cinco minutos identifica novos eventos e contadores pagos,
sem publicar prompts, credenciais ou conteudo dos arquivos.


## Recurring job launcher audit

PAID_SCHEDULE_SCAN_V1 inspects active custom systemd timers, user timer
definitions, /etc/crontab, /etc/cron.d, Ubuntu/root crontabs and periodic
cron entrypoints every five minutes. It checks direct claude -p, --print,
resume/model invocations, codex exec/resume/cloud, direct provider HTTP
domains and bounded local script wrappers through depth three. The
read-only audit does not execute any program, inspect credentials, emit
commands/prompts/paths or terminate human Claude Remote Control sessions.

Static alert codes are PAID_RECURRING_SYSTEMD_LAUNCHER,
PAID_RECURRING_USER_TIMER_LAUNCHER, PAID_RECURRING_CRON_LAUNCHER and
SCHEDULE_TIMER_INVENTORY_UNAVAILABLE. The first three indicate a risky
scheduled command definition, not proof of paid billing. Inspect and
replace any unauthorized recurring paid command with verified Gemini Free
or local Ollama through an approved corrective workflow. The monitor is
read-only by design and never silently deactivates approved user services.

Run: python3 -m unittest -q tests.test_ai_free_provider_sentinel


## Bounded runtime and persistent database snapshots (2026-10-13)

AI_SENTINEL_BOUNDED_SCAN_V1 addresses the backend monitor that was timed
out by systemd after 90 seconds in production. Profiling on the backend
measured ~16.6s on repeated Codex SQLite aggregation, ~15.7s walking
old Claude JSONL sessions and ~5.6s scanning recurring launchers.
The former service CPU quota of 30% amplified these delays.

The sentinel now caches Codex counters ONLY when the SQLite data and WAL
file signatures (inode, size, nanosecond mtime/ctime) are unchanged.
When either file changes it re-queries SQLite read-only and still detects
counter increases. Failed reads are never cached as success.

Claude history traversal has a 28-second monotonic budget. If the walk
cannot finish, it emits CLAUDE_USAGE_SCAN_INCOMPLETE, sets ALERT instead
of a false HEALTHY, and retains the previous scan watermark. The next
complete scan covers the full outstanding window. No user prompts or
session content are written to logs. The unit keeps a CPU ceiling
(75%) and service timeout (90 seconds) without weakening provider
authorization, billing gates, or MFA.

Regression:
python3 -m unittest -q tests.test_ai_free_provider_sentinel
Validations after official rollout: at least two real systemd timer
service completions with Result=success, fresh state, no stale alert,
correct Codex/Claude snapshots, and both paid bridges still denying use.
