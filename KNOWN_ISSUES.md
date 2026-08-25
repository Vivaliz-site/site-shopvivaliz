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

## 🟠 Rotina de otimização inteligente Shopee (6h): NOVA regressão de credencial na VM desde 2026-08-22 (13h UTC) + segue faltando integração de analytics

**Última atualização:** 2026-08-25

### Atualização 2026-08-25 — `shopee-runtime-health.yml` volta a falhar; credencial da VM parece ter sumido (diferente do bloqueio de 07/2026)

`shopee-runtime-health.yml` (schedule 6h) tinha sucesso consistente desde o fix de 08-15 (ex:
runs 992/1055/1062/1089/1114, todos `conclusion: success` até 2026-08-22T06:59 UTC). A partir do
run `32611709229` (2026-08-22T13:00:54Z) **todo** run agendado passou a falhar com
`ERROR: required Shopee runtime credentials are incomplete` (exit code 4) — mais de 40 execuções
seguidas até a checagem mais recente (`32851832513`, 2026-08-25T13:11:15Z). Essa checagem lê as
credenciais direto de `shared/.env`/`shared/shopee-tokens.json` **na VM** (não do sandbox nem do
GitHub Actions), então isso indica que a credencial real sumiu ou expirou na própria VM — não é o
mesmo problema de "sandbox sem secret" já mapeado em `docs/audits/shopee-runtime-credentials-2026-08-14.md`.
Nenhum commit relacionado a Shopee coincide com o início da falha; a janela tem um volume grande
de commits `ops:` sobre instabilidade/recuperação do "Fred-Win Desktop Commander" e "VM checkout
migration" (incluindo uma limpeza de "stale Shopee unit backups" em 2026-08-25T11:53), o que é
suspeito mas não confirmado como causa — requer acesso SSH à VM (que este ambiente não tem) para
confirmar se o `shopee-token-renewer` parou de rodar ou se `shared/shopee-tokens.json` foi
sobrescrito/removido. **Ação sugerida:** Fred conferir na VM (`ssh ubuntu@137.131.156.17`) o
status de `shopvivaliz-shopee-token-renewer.service` e o conteúdo/validade de
`shopvivaliz-deploy/shared/shopee-tokens.json`, e comparar com o `.env` esperado antes da janela de
instabilidade. Detalhe completo em `docs/MEMORIA-AGENTES.md`, entrada 2026-08-25.

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
