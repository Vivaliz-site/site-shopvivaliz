# Known Issues & Solutions

## ⚠️ CRÍTICO: Pasta `/includes/` Bloqueada no Apache (3ª Ocorrência)

**Última atualização:** 2026-07-19

### Problema
A pasta `/includes/` retorna HTTP 403 Forbidden no servidor de produção Apache, bloqueando o acesso a:
- Scripts JavaScript (`/includes/*.js`)
- Arquivos PHP (`/includes/*.php`)
- Qualquer outro arquivo nesta pasta

### Causa
Há uma configuração global no Apache (provavelmente em `/etc/apache2/` ou configuração do host virtual) que bloqueia explicitamente a pasta `/includes/`.

### Solução Implementada (Terceira Vez)
**Use symlinks na pasta `/js/` (que é pública) apontando para `/includes/`**

```bash
# No servidor de produção
cd /home/ubuntu/site-shopvivaliz
ln -s ../includes/auto-image-carousel.js js/auto-image-carousel.js
ln -s ../includes/navbar.php js/navbar.php  # Se necessário
# etc...
```

**Atualizar referencias no código:**
- Em `index.php`: `<script src="/js/auto-image-carousel.js"></script>`
- Em `catalogo.php`: `<script src="/js/auto-image-carousel.js"></script>`
- Em qualquer arquivo que carregue de `/includes/`

### Histórico de Ocorrências

**Ocorrência 1:** (data desconhecida)
- Problema com acesso a arquivo em `/includes/`
- Solução: [não documentada - PROBLEMA]

**Ocorrência 2:** (data desconhecida)  
- Problema repetiu
- Solução: [não documentada - PROBLEMA]

**Ocorrência 3:** 2026-07-19
- Carousel script (`/js/auto-image-carousel.js`) não carregava
- Retornava HTTP 403 Forbidden
- **Solução:** Criar symlink em `/js/` e atualizar referências

### Por Que Isso Ocorre
A configuração do Apache pode estar em:
1. `/etc/apache2/apache2.conf` - regra global de acesso
2. `/etc/apache2/sites-enabled/shopvivaliz.conf` - regra específica do virtual host
3. `.htaccess` em pasta pai - regra de rewrite que bloqueia

### Verificação de Diagnóstico

```bash
# Testar acesso
curl -I https://shopvivaliz.com.br/includes/auto-image-carousel.js
# Se retornar 403: problema está ativo

# Testar symlink
curl -I https://shopvivaliz.com.br/js/auto-image-carousel.js
# Se retornar 200: symlink funciona
```

### Recomendação Permanente

**NUNCA** referencie arquivos de `/includes/` diretamente no HTML/JavaScript. 

**Use sempre `/js/`, `/css/`, ou `/api/`** para servir assets públicos.

Se precisar de um arquivo que está em `/includes/`:
1. Crie um symlink em uma pasta pública
2. Atualize a referência no código
3. Documente aqui neste arquivo

### Próximas Mudanças

Ao adicionar novo arquivo em `/includes/` que precisa ser público:
- [ ] Criar symlink apropriado em `/js/`, `/css/`, ou `/api/`
- [ ] Atualizar referências de caminho no código
- [ ] Adicionar linha neste arquivo com data e descrição

---

## 🟡 Rotina de otimização inteligente Shopee (6h): credencial corrigida em 2026-08-14, mas falta integração de analytics (CTR/conversão)

**Última atualização:** 2026-08-26

### Atualização 2026-08-26 — regressão nova: `shopee-runtime-health.yml` voltou a falhar desde 2026-08-22, sem ninguém ter registrado

O ciclo agendado desta rotina (execução autônoma de 2026-08-26 ~01h UTC) checou o estado real via
`mcp__github__actions_list`/`get_job_logs` em vez de assumir o texto da entrada de 2026-08-15
abaixo. Achado: `shopee-runtime-health.yml` (o health check que prova leitura real de catálogo na
VM, criado pela correção de 2026-08-15) rodou com sucesso de 2026-08-15 a 2026-08-21 (última
execução `success`: run `1062`, 2026-08-21 18:55 UTC), mas **falha continuamente desde 2026-08-22
18:48 UTC** (run `1123`) até a última execução agendada disponível no momento desta checagem (run
`1304`, 2026-08-25 18:56 UTC) — **13 execuções seguidas em falha ao longo de ~3 dias**, sem que
nenhuma sessão anterior tenha percebido ou registrado isso (não há entrada em
`docs/HISTORICO-AGENTES-SHOPEE.md` entre 9.29 de 2026-08-19 e esta atualização).

**Erro exato** (idêntico em todas as 13 execuções, confirmado via `get_job_logs` nos runs `1123` e
`1304`): a etapa SSH conecta normalmente na VM (`137.131.156.17`) e roda
`scripts/shopee_runtime_exec.py --env-file .../shared/.env -- scripts/shopee_runtime_preflight.py`
em ~4-10s, mas o script retorna `ERROR: required Shopee runtime credentials are incomplete` (exit
code 4) antes de conseguir provar `catalog_read`/`detail_read`. Não é falha de SSH/host key — a
conexão e a execução remota funcionam; o problema é que o `.env`/`shopee-tokens.json` compartilhado
na VM (`/home/ubuntu/shopvivaliz-deploy/shared/`) parece ter ficado com credencial Shopee
incompleta a partir de 2026-08-22. Isso é anterior e possivelmente independente do travamento de
memória da VM em 2026-08-25 registrado em `CLAUDE.md` (que só começou 3 dias depois, 08-25); vale
checar as duas causas separadamente.

**Não investigado ainda** (fora do alcance deste sandbox, que não tem `SHOPEE_TOKEN`/`SHOPEE_*` nas
envs, como esperado): se é o serviço `shopvivaliz-shopee-token-renewer` (systemd, ver
`deploy/systemd/shopvivaliz-shopee-token-renewer.service`) que parou de renovar o token rotativo, se
o token foi revogado no painel Shopee, ou se `shared/.env`/`shared/shopee-tokens.json` foi
sobrescrito/corrompido por alguma das mudanças de infraestrutura da VM na mesma janela (vários
commits de "Desktop Commander"/"VM checkout migration" entre 08-22 e 08-25).

**Ação sugerida para o Fred:** `ssh ubuntu@137.131.156.17` e inspecionar
`/home/ubuntu/shopvivaliz-deploy/shared/.env` + `shared/shopee-tokens.json` (existem? têm todos os
campos exigidos por `scripts/shopee_runtime_preflight.py`?) e o status do serviço
`shopvivaliz-shopee-token-renewer` (`systemctl status`, logs) — provavelmente o token rotativo
expirou sem renovação. Isso é anterior e mais restrito que o bloqueio de analytics (CTR/conversão)
descrito na entrada de 2026-08-15 abaixo, que segue igualmente pendente.

Nenhuma otimização de título/descrição/imagem/atributo/preço foi aplicada e nenhum dado de
CTR/conversão foi inventado neste ciclo, conforme a regra de segurança da rotina. `env | grep -iE
"SHOPEE|TINY|OLIST"` seguiu vazio neste sandbox, como esperado.

**Ver também:** `docs/HISTORICO-AGENTES-SHOPEE.md` seção 9.30 (detalhe completo desta checagem).

---

### Registro anterior (2026-08-15) — mantido como histórico, ver correção acima

**Última atualização (registro original):** 2026-08-15

### Atualização 2026-08-15 — bloqueador primário corrigido; premissa dos registros abaixo (2026-07-XX) estava errada

Os PRs `#979`/`#980` (usuário, 2026-08-14 ~21h -03) corrigiram a causa raiz real: o sandbox onde
agentes autônomos rodam nunca recebeu os secrets operacionais, e cada ciclo da rotina de 6h
tratou essa ausência **local** como prova de que a credencial Shopee não existia em lugar nenhum.
Na verdade os tokens Shopee rotativos sempre estiveram presentes na VM de produção — só não
estavam disponíveis no runner do GitHub Actions usado por `shopee-production-seo.yml`. O bloqueio
"OAuth2 do Tiny" descrito abaixo referia-se a um pipeline (`fetch-shopee-listings.yml`/
`optimize-shopee-listings.yml`) que **já não é o caminho real de produção**: o executor atual
(`scripts/shopee_production_seo_apply.py`) fala direto com a API da Shopee, sem depender do
Tiny/Olist.

**O que foi corrigido:** `shopee-production-seo.yml` agora roda na própria VM (fonte canônica dos
tokens rotativos, `shared/shopee-tokens.json`) em vez do runner do GitHub Actions; novo workflow
`shopee-runtime-health.yml` (schedule a cada 6h) confirma leitura real de catálogo via SSH —
rodando com sucesso desde o merge (3/3 execuções `conclusion: success` em 2026-08-15). Primeira
confirmação de acesso real ao catálogo desde 2026-07-26 (19 dias).

**O que ainda falta (novo bloqueador, mais restrito que o anterior):** nenhum dos scripts de
produção (`shopee_full_catalog_optimizer.py`, `shopee_production_seo_apply.py`,
`shopee_runtime_preflight.py`) chama qualquer endpoint de analytics do Shopee Open Platform
(CTR, taxa de conversão, comparação alto/baixo desempenho, dado de A/B testing). Isso significa
que a rotina agendada "Otimização Shopee 6h" consegue **ler e escrever** no catálogo real, mas os
itens que dependem de dado de desempenho (análise de CTR/conversão, recomendação orientada a
dado, A/B testing medido) continuam tecnicamente inexequíveis até alguém integrar esses
endpoints. `shopee-production-seo.yml` (apply real) também ainda não foi executado com sucesso
desde o fix — requer `workflow_dispatch` manual com confirmação humana digitada
(`APPLY_ALL_SHOPEE_PRODUCTS`), que nenhum agente autônomo deve disparar sem dado real de
desempenho para basear a decisão.

**Ação sugerida para quando o usuário tiver tempo:** (1) rodar `shopee-production-seo.yml`
manualmente com `limit` pequeno para validar o primeiro apply real desde o fix; (2) decidir se
vale integrar os endpoints de analytics do Shopee Open Platform para viabilizar a análise
orientada a dado; (3) alternativamente, reduzir o escopo da rotina de 6h para apenas leitura de
catálogo + apply manual pontual, já que é o que o código hoje sustenta.

**Ver também:** `docs/HISTORICO-AGENTES-SHOPEE.md` seção 9.21 (detalhe completo desta correção),
PRs `#979`/`#980`.

---

### Registro anterior (até 2026-07-27) — mantido como histórico, ver correção acima

**Última atualização (registro original):** 2026-07-27

### Atualização 2026-07-27
`fetch-shopee-listings.yml` e `optimize-shopee-listings.yml` **não existem mais** em
`.github/workflows/` — removidos, aparentemente como colateral da consolidação "99→10
workflows" registrada em `CLAUDE.md` (2026-07-26); nenhum workflow ativo restante
referencia Shopee. Nenhum artefato novo em `listings/` desde `20260726-080756`/
`20260726-060921` (ambos ainda com o erro OAuth2 abaixo, de antes da remoção). Ou seja, além
de renovar a credencial (problema original abaixo), agora também é preciso **recriar os dois
workflows** para o pipeline voltar a rodar. Detalhes: `docs/HISTORICO-AGENTES-SHOPEE.md`
seção 9.10.

### Problema original (até 2026-07-26)
Os três workflows que dependem do client OAuth2 do Tiny ERP (`fetch-shopee-listings.yml` a
cada 6h, `optimize-shopee-listings.yml` diário 03h UTC, e o refresh usado por
`sync-shopee-6h.yml`) estavam falhando em praticamente todo ciclo desde pelo menos 2026-07-03,
sempre com o mesmo erro:

```
Falha OAuth2 refresh: Invalid client or Invalid client credentials
Autenticação Tiny falhou (401).
```

Nenhum produto está sendo otimizado (título/descrição/atributos/ordem de imagens) há 3+
semanas. Confirmado ainda ativo em 2026-07-25 13:01 UTC (`listings/shopee-listings-20260725-130116.json`,
`status: partial`, `total_products: 0`), minutos antes desta entrada.

### Causa
Credencial OAuth2 do Tiny (`TINY_CLIENT_ID` / `TINY_CLIENT_SECRET` / `TINY_REFRESH_TOKEN` nos
GitHub Secrets) expirou ou foi revogada no painel da Tiny. Não é um bug de código — o refresh
token em si está inválido, então nenhum agente autônomo consegue corrigir isso sozinho.

### Solução (requer ação manual humana)
1. Acessar `accounts.tiny.com.br` → app/client OAuth2 usado pela integração.
2. Regenerar o client (novo `client_id`/`client_secret`) e gerar um novo `refresh_token`.
3. Atualizar os secrets no GitHub: `Settings > Secrets and variables > Actions` →
   `TINY_CLIENT_ID`, `TINY_CLIENT_SECRET`, `TINY_REFRESH_TOKEN` (e `TINY_ACCESS_TOKEN` se usado
   como fallback estático).
4. Disparar `fetch-shopee-listings.yml` via `workflow_dispatch` para confirmar que
   `total_products > 0` antes de considerar resolvido.

### Por que isso importa
Qualquer outro processo que dependa do mesmo client OAuth2 do Tiny (ex:
`daemon-shopee-token-renewer.py`, sync de pedidos) provavelmente também está falhando
silenciosamente. Os workflows continuam rodando "no schedule" a cada 6h só gravando
relatórios de erro em `listings/*.json` sem que isso pare ou alerte visivelmente — daí esta
entrada, pra não ficar só em relatórios JSON dispersos.

### Histórico de descoberta
- **2026-07-19:** Sessão autônoma identificou que o agendamento "Otimização Shopee 6h" não
  tem credenciais no ambiente sandbox e que a automação real roda via GitHub Actions
  (`docs/MEMORIA-AGENTES.md`).
- **2026-07-25 07:10 UTC:** Auditoria dos `listings/optimization-report-*.json` confirmou que
  o pipeline real (com secrets de verdade) está 100% inoperante desde 2026-07-03 por essa
  falha OAuth2 (`docs/MEMORIA-AGENTES.md`).
- **2026-07-25 13:xx UTC (esta entrada):** Confirmado que a falha persiste em execução real
  minutos antes desta sessão; entrada criada aqui (que faltava) porque o problema não estava
  em nenhum lugar centralizado além do `MEMORIA-AGENTES.md`.

**Ver também:** `docs/MEMORIA-AGENTES.md` (entradas 2026-07-19 e 2026-07-25), `docs/TINY-ERP-API-V3.md`

---

**Última pessoa a corrigir:** Claude (AI Assistant)  
**Data:** 2026-07-19 15:46 UTC  
**Commit:** 8b9adb83
