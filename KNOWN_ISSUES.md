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

**Última pessoa a corrigir:** Claude (AI Assistant)  
**Data:** 2026-07-19 15:46 UTC  
**Commit:** 8b9adb83
