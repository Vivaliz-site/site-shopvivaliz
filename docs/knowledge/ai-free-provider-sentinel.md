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
