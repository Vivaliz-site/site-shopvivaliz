# 🔍 AUDITORIA FASE 1: MAPEAMENTO E NAVEGAÇÃO EXPLORATÓRIA

**Data:** 2026-08-15T00:33:20.708Z
**URL Testada:** https://shopvivaliz.com.br
**Navegador:** Playwright + Chromium


## 1️⃣ HOME PAGE

### Performance Metrics
- DOM Content Loaded: 1.7999999821186066ms
- Load Complete: 0.4000000059604645ms
- First Paint: 29124ms
- First Contentful Paint: 29292ms
- Recursos carregados: 58
- Transfer size total: 2108.2 KB
- Recursos mais lentos (>300ms):
  - 0f461ce3ef17d675fbe1939d437443ae.jpg: 1319ms
  - : 1307ms
  - 04b14b23214020421068fb1721b9bc5e.jpg: 1289ms
  - 58a1558469c6640af464bb3a5878fb6a.jpg: 1170ms
  - c553a30db3a50bfde7a4daae065fdb46.jpg: 1161ms

### 🔴 Requisições Falhas (HOME)
- [fetch] https://shopvivaliz.com.br/0fb1/ag/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754029857&_gaz=1&gcd=13l3l3l3l1l1&npa=0&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=1834282915&_eu=AAAAAGAC&_uip=%3A%3A&are=1&cid=78853741.1786754032&ec_mode=a&fp=1&frm=0&pscdl=noapi&rcb=10&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=2mM_jk2yBLa7NGxrdGL5gXq6FQvp4TjS3w&gaf=2&_s=1&tag_exp=115938465~115938468~118897920~118897930~119367802~119367810~119527019~120385422&sid=1786754031&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2F&dt=Vivaliz%20%7C%20Loja%20Online&en=page_view&_fv=1&_nsi=1&_ss=1&_ee=1&gap.gtb=1&tfd=30446 → net::ERR_ABORTED
- [fetch] https://www.google-analytics.com/g/collect?v=2&tid=G-QWYPLYMZ9&gtm=45je68c1h1z89254139212za20gzb9254139212zd9254139212&_p=1786754029857&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT&are=1&cid=786677583.1786754033&frm=0&pscdl=denied&rcb=2&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&_s=1&tag_exp=115938465~115938469~118897920~118897930~119367802~119367810~119527019~120385422&sid=1786754032&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2F&dt=Vivaliz%20%7C%20Loja%20Online&en=page_view&_fv=1&_nsi=1&_ss=1&tfd=32117 → net::ERR_ABORTED

📸 Screenshot salvo: `home-full.png`

### Elementos Críticos Detectados
- Hero/Banner: ✅
- Busca: ❌
- Produtos visíveis: 43 elementos
- Imagens de banner: 2


## 2️⃣ CATEGORIA / VITRINE

### Performance Metrics
- DOM Content Loaded: 0.6000000238418579ms
- Load Complete: 0.30000001192092896ms
- First Paint: 3736ms
- First Contentful Paint: 3736ms
- Recursos carregados: 66
- Transfer size total: 34.9 KB
- Recursos mais lentos (>300ms):
  - c95cc3b3a076ec2b89e7043aa30c2726.jpg: 652ms
  - 01718c46f99773a05032bb477f7843b1.jpg: 611ms
  - products.php?limit=20&page=1&ordem=relevance: 582ms
  - f5d114dbeb06f758e5b36ebe7c2097f1.jpg: 571ms
  - bf5f714909d3d3e418420c40ac101746.jpg: 549ms

### Elementos de Vitrine
- Cards de produto: 143
- Opções de filtro: 1
- Opções de ordenação: 1

📸 Screenshot salvo: `catalog.png`


## 3️⃣ PÁGINA DO PRODUTO (PDP)

### Performance Metrics
- DOM Content Loaded: 4.200000017881393ms
- Load Complete: 0.09999999403953552ms
- First Paint: 2276ms
- First Contentful Paint: 2276ms
- Recursos carregados: 54
- Transfer size total: 11.7 KB
- Recursos mais lentos (>300ms):
  - ce74566cc92eac9397460955f63b29fe.png: 965ms
  - 2f8c49c91c5443574d8795439859c7ec.png: 851ms
  - c82cd5493d866aba0e8b6a1c451274a0.png: 687ms
  - 45d90fbd934db1f526574e4b6330131e.png: 681ms
  - 0e09e3c8142f85a878b6fbd8e358a5ff.jpg: 527ms

### Elementos de Produto
- Título: Assento Sanitário Oval Universal Soft Branco Astra
- Preço: 
                            Pronto para comprar?
                            R$ 44,10
                        
- Imagens de produto: 0
- Botão "Adicionar ao Carrinho": ❌
- Opções de variação: 0

📸 Screenshot salvo: `product-detail.png`


## 4️⃣ CARRINHO

### 🔴 Requisições Falhas (CARRINHO)
- [ping] https://shopvivaliz.com.br/cdn-cgi/rum? → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ga/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754043043&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=852914105&_eu=AAAAAGAC&_uip=%3A%3A&are=1&cid=1999017651.1786754044&fp=1&frm=0&pscdl=denied&rcb=4&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=yslqkpvsMnZLGV1D7axITRrhlHQwvXKchg&gaf=2&_s=4&tag_exp=115938466~115938468~118395334~118897920~118897930~119367802~119367810~119527019~120385422&sid=1786754043&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fproduto%2Fassento-sanitario-oval-universal-soft-branco&dt=Assento%20Sanit%C3%A1rio%20Oval%20Universal%20Soft%20Branco%20Astra%20TPJ%2F%20%7C%20Vivaliz&en=user_engagement&gap.gtb=2&_et=2201&tfd=5218 → net::ERR_ABORTED
- [fetch] https://www.google-analytics.com/g/collect?v=2&tid=G-QWYPLYMZ9&gtm=45je68c1h1za20gzb9254139212zd9254139212&_p=1786754043043&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT&are=1&cid=1999017651.1786754044&frm=0&pscdl=denied&rcb=13&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&_s=2&tag_exp=115938466~115938469~118395333~118897920~118897930~119367802~119367810~119527019~120315471~120385423&sid=1786754043&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fproduto%2Fassento-sanitario-oval-universal-soft-branco&dt=Assento%20Sanit%C3%A1rio%20Oval%20Universal%20Soft%20Branco%20Astra%20TPJ%2F%20%7C%20Vivaliz&en=user_engagement&_et=2216&tfd=5221 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ag/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754046313&gcd=13l3l3l3l1l1&npa=0&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=1834282915&_eu=AAAAAGQC&_uip=%3A%3A&are=1&cid=78853741.1786754032&ec_mode=a&fp=1&frm=0&pscdl=noapi&rcb=1&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=2mM_jk2yBLa7NGxrdGL5gXq6FQvp4TjS3w&gaf=2&_s=1&tag_exp=115616986~115938465~115938469~118897920~118897930~119367802~119367810~119527020~120125305~120385423&sid=1786754031&sct=1&seg=1&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcarrinho&dt=Carrinho%20%7C%20Vivaliz&en=page_view&_ee=1&gap.gtb=1&tfd=184 → net::ERR_ABORTED
- [fetch] https://www.google-analytics.com/g/collect?v=2&tid=G-QWYPLYMZ9&gtm=45je68c1h1z89254139212za20gzb9254139212zd9254139212&_p=1786754046313&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT&are=1&cid=792817022.1786754047&frm=0&pscdl=denied&rcb=17&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&_s=1&tag_exp=115616986~115938465~115938469~118897920~118897930~119367802~119367810~119527020~120385423&sid=1786754046&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcarrinho&dt=Carrinho%20%7C%20Vivaliz&en=page_view&_fv=1&_nsi=1&_ss=1&tfd=822 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ag/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754046313&gcd=13l3l3l3l1l1&npa=0&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=1834282915&_eu=AEAAAGQC&_uip=%3A%3A&ae=a&are=1&cid=78853741.1786754032&fp=1&frm=0&pscdl=noapi&rcb=1&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=2mM_jk2yBLa7NGxrdGL5gXq6FQvp4TjS3w&gaf=2&_s=2&tag_exp=115616986~115938465~115938469~118897920~118897930~119367802~119367810~119527020~120125305~120385423&sid=1786754031&sct=1&seg=1&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcarrinho&dt=Carrinho%20%7C%20Vivaliz&en=scroll&gap.gtb=1&epn.percent_scrolled=90&_et=5&tfd=830 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ga/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754046313&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=427621909&_eu=AAAAAGAC&_uip=%3A%3A&are=1&cid=792817022.1786754047&fp=1&frm=0&pscdl=denied&rcb=1&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=nVPvpVbcf7Tq94LQpv4ZybtqI7Q5rXYHKg&gaf=2&_s=3&tag_exp=115616986~115938465~115938469~118897920~118897930~119367802~119367810~119527020~120125305~120385423&cu=BRL&sid=1786754046&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcarrinho&dt=Carrinho%20%7C%20Vivaliz&en=view_cart&_fv=1&_ss=1&_ee=1&gap.gtb=2&epn.value=0&tfd=830 → net::ERR_ABORTED

### Elementos do Carrinho
- Itens no carrinho: 0
- Preço total: R$ 0,00
- Botão Checkout: ✅
- Inputs de quantidade: 0

📸 Screenshot salvo: `cart.png`


## 5️⃣ CHECKOUT

### ⚠️ Console Errors/Warnings
- [ERROR] Refused to execute script from 'https://shopvivaliz.com.br/0fb1/?is_td=1&v=3&t=t&pid=2131840976&gtm=45g92e68c1v9184223184za200zd9184223184&seq=2&exp=115616985~115938465~115938469~118897920~118897930~119367802~119367810~119527019~120385423&dl=shopvivaliz.com.br%2Fcheckout&tdp=G-1H55K1TZ5D;184223184;0;0;0&mde=G-1H55K1TZ5D;17_1;61_1&mbc=1&z=0' because its MIME type ('text/plain') is not executable, and strict MIME type checking is enabled.

### 🔴 Requisições Falhas (CHECKOUT)
- [ping] https://shopvivaliz.com.br/cdn-cgi/rum? → net::ERR_ABORTED
- [fetch] https://www.google-analytics.com/g/collect?v=2&tid=G-QWYPLYMZ9&gtm=45je68c1h1za20gzb9254139212zd9254139212&_p=1786754046313&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT&are=1&cid=792817022.1786754047&frm=0&pscdl=denied&rcb=17&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&_s=2&tag_exp=115616986~115938465~115938469~118897920~118897930~119367802~119367810~119527020~120385423&sid=1786754046&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcarrinho&dt=Carrinho%20%7C%20Vivaliz&en=user_engagement&_et=1349&tfd=2172 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ga/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754046313&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=427621909&_eu=AAAAAGAC&_uip=%3A%3A&are=1&cid=792817022.1786754047&fp=1&frm=0&pscdl=denied&rcb=1&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=nVPvpVbcf7Tq94LQpv4ZybtqI7Q5rXYHKg&gaf=2&_s=4&tag_exp=115616986~115938465~115938469~118897920~118897930~119367802~119367810~119527020~120125305~120385423&sid=1786754046&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcarrinho&dt=Carrinho%20%7C%20Vivaliz&en=user_engagement&gap.gtb=2&_et=1336&tfd=2169 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ag/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754048395&gcd=13l3l3l3l1l1&npa=0&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=1834282915&_eu=AAAAAGQC&_uip=%3A%3A&are=1&cid=78853741.1786754032&ec_mode=a&fp=1&frm=0&pscdl=noapi&rcb=16&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=2mM_jk2yBLa7NGxrdGL5gXq6FQvp4TjS3w&gaf=2&_s=1&tag_exp=115616985~115938465~115938469~118897920~118897930~119367802~119367810~119527019~120385423&sid=1786754031&sct=1&seg=1&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcheckout&dt=Finalizar%20Pedido%20%7C%20Vivaliz&en=page_view&_ee=1&gap.gtb=1&tfd=333 → net::ERR_ABORTED
- [fetch] https://www.google-analytics.com/g/collect?v=2&tid=G-QWYPLYMZ9&gtm=45je68c1h1z89254139212za20gzb9254139212zd9254139212&_p=1786754048395&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT&are=1&cid=1699841794.1786754049&frm=0&pscdl=denied&rcb=2&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&_s=1&tag_exp=115938465~115938469~118395333~118897920~118897930~119367802~119367810~119527019~120385422&sid=1786754048&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcheckout&dt=Finalizar%20Pedido%20%7C%20Vivaliz&en=page_view&_fv=1&_nsi=1&_ss=1&tfd=1168 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ag/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754048395&gcd=13l3l3l3l1l1&npa=0&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=1834282915&_eu=AEAAAGQC&_uip=%3A%3A&ae=a&are=1&cid=78853741.1786754032&fp=1&frm=0&pscdl=noapi&rcb=16&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=2mM_jk2yBLa7NGxrdGL5gXq6FQvp4TjS3w&gaf=2&_s=2&tag_exp=115616985~115938465~115938469~118897920~118897930~119367802~119367810~119527019~120385423&sid=1786754031&sct=1&seg=1&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcheckout&dt=Finalizar%20Pedido%20%7C%20Vivaliz&en=scroll&gap.gtb=1&epn.percent_scrolled=90&_et=6&tfd=1179 → net::ERR_ABORTED
- [fetch] https://shopvivaliz.com.br/0fb1/ga/g/c?v=2&tid=G-1H55K1TZ5D&gtm=45g92e68c1v9184223184za200zd9184223184&_p=1786754048395&gcs=G100&gcd=13p3p3p3p5l1&npa=1&dma_cps=-&dma=0&gdid=dYzg1YT.dNzQzZD&ecid=1335180124&_eu=AAAAAGAC&_uip=%3A%3A&are=1&cid=1699841794.1786754049&fp=1&frm=0&pscdl=denied&rcb=16&sr=1440x900&uaa=x86&uab=64&uafvl=HeadlessChrome%3B149.0.7827.55%7CChromium%3B149.0.7827.55%7CNot)A%253BBrand%3B24.0.0.0&uam=&uamb=0&uap=Windows&uapv=19.0.0&uaw=0&ul=pt-br&ur=BR-MG&_gsid=Mx0q6vS7d0FikOoh8OCfgH_cPIFo5FiF7Q&gaf=2&_s=3&tag_exp=115616985~115938465~115938469~118897920~118897930~119367802~119367810~119527019~120385423&cu=BRL&sid=1786754048&sct=1&seg=0&dl=https%3A%2F%2Fshopvivaliz.com.br%2Fcheckout&dt=Finalizar%20Pedido%20%7C%20Vivaliz&en=begin_checkout&_fv=1&_ss=1&_ee=1&gap.gtb=2&epn.value=0&tfd=1180 → net::ERR_ABORTED

### Elementos de Checkout
- Campos de formulário: 15
- Etapas visíveis: 8
- Métodos de pagamento: 12
- Selos de segurança: 0

📸 Screenshot salvo: `checkout.png`


## 📱 SCREENSHOTS MOBILE (390x844)

📸 `mobile-home.png` capturado (https://shopvivaliz.com.br)
📸 `mobile-catalogo.png` capturado (https://shopvivaliz.com.br/catalogo/)
📸 `mobile-produto.png` capturado (https://shopvivaliz.com.br/produto/)

---

### 📊 Resumo Final
Auditoria concluída. Screenshots e métricas capturadas.
Total de erros de console: 1
Total de warnings: 0
Total de requisições falhas: 52
