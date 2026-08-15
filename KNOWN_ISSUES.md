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

## 🟡 Pipeline de otimização Shopee: leitura restaurada via nova integração direta (sem Tiny); escrita em produção ainda manual

**Última atualização:** 2026-08-15

### Atualização 2026-08-15 — novo pipeline direto (sem Tiny OAuth2), leitura confirmada em produção
Fred reescreveu a integração hoje (commit `abf3c8f`, 03:21 UTC) para não depender mais do OAuth2
do Tiny ERP (problema original abaixo, nunca resolvido): novo workflow
`.github/workflows/shopee-runtime-health.yml` roda a cada 6h + após todo `Master Production
Pipeline 24/7`, faz SSH na VM de produção e chama `scripts/shopee_runtime_preflight.py`
(read-only) usando credenciais `SHOPEE_PARTNER_ID`/`SHOPEE_PARTNER_KEY`/`SHOPEE_SHOP_ID` +
tokens em `shopvivaliz-deploy/shared/shopee-tokens.json` na própria VM — não GitHub Secrets
rotativos, não Tiny. Confirmado via GitHub Actions API rodando com sucesso repetidamente desde
06:21 UTC de hoje (`catalog_read: true`, `detail_read: true`). `.github/workflows/shopee-optimizer-safety.yml`
(gate de CI para esses scripts) também verde em todo push/PR de hoje.

**O que ainda não está resolvido:** a aplicação real de otimizações (títulos/descrições/preços)
em produção continua só via `.github/workflows/shopee-production-seo.yml`
(`Shopee SEO Production Apply`), que é **exclusivamente `workflow_dispatch` manual** com um input
`confirmation` que exige digitar literalmente `APPLY_ALL_SHOPEE_PRODUCTS` — não tem `schedule`,
não roda em push. Última execução registrada: **2026-07-30, falhou** (antes da reescrita de
hoje); nenhuma execução confirmada da versão nova ainda. `fetch-shopee-listings.yml` /
`optimize-shopee-listings.yml` (os antigos, dependentes do Tiny) continuam ausentes do repo, mas
isso agora parece irrelevante já que o novo caminho não usa o Tiny.

**Próximo passo (ação humana):** disparar `shopee-production-seo.yml` manualmente com
`limit` pequeno (ex: `1`) pra confirmar que o caminho de escrita funciona de ponta a ponta antes
de considerar o pipeline totalmente restaurado. Sessões autônomas do ciclo "Otimização Shopee 6h"
não devem disparar esse `workflow_dispatch` sozinhas — o gate de confirmação digitada é
intencional.

**Ver também:** `docs/MEMORIA-AGENTES.md` (entrada 2026-08-15, "Ciclo 6h de otimização Shopee:
primeira mudança real desde 07-26"), `docs/HISTORICO-AGENTES-SHOPEE.md` seção 9.

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
