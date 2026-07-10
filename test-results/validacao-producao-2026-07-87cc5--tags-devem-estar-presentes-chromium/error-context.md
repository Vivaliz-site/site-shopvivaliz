# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: validacao-producao-2026-07-08.spec.ts >> 🚀 VALIDAÇÃO PRODUÇÃO 2026-07-08 >> 📱 SEO - Meta Tags e Structured Data >> Open Graph tags devem estar presentes
- Location: tests\validacao-producao-2026-07-08.spec.ts:42:9

# Error details

```
Test timeout of 300000ms exceeded.
```

```
Error: locator.getAttribute: Test timeout of 300000ms exceeded.
Call log:
  - waiting for locator('meta[property="og:title"]')

```

# Page snapshot

```yaml
- generic [active] [ref=e1]:
  - generic [ref=e2]:
    - banner [ref=e3]:
      - generic [ref=e6]:
        - paragraph [ref=e8]: VASOS PARA PLANTAS
        - paragraph [ref=e10]: FERRAMENTAS
        - paragraph [ref=e12]: ACESSÓRIOS PARA CASA
      - generic [ref=e13]:
        - heading "Início da loja" [level=1] [ref=e14]:
          - link "Início da loja" [ref=e15] [cursor=pointer]:
            - /url: /
            - img "SHOPVIVALIZ LTDA" [ref=e16]
        - generic [ref=e21]:
          - searchbox "Buscar" [ref=e22] [cursor=pointer]
          - button "Buscar" [ref=e23] [cursor=pointer]:
            - img [ref=e24]
        - generic [ref=e26]:
          - link "Acessar Minha Conta" [ref=e28] [cursor=pointer]:
            - /url: /conta
            - img [ref=e29]
          - button "Abrir carrinho" [ref=e32] [cursor=pointer]:
            - img [ref=e33]
            - generic [ref=e35]: "0"
      - navigation [ref=e36]:
        - list [ref=e37]:
          - listitem [ref=e38]:
            - link "Menu Casa, Móveis e Decoração" [ref=e39] [cursor=pointer]:
              - /url: /casa-moveis-e-decoracao
              - text: ☰ Casa, Móveis e Decoração
          - listitem [ref=e40]:
            - link "Menu Ferramentas" [ref=e41] [cursor=pointer]:
              - /url: /ferramentas
              - text: Ferramentas
          - listitem [ref=e42]:
            - link "Menu Construção" [ref=e43] [cursor=pointer]:
              - /url: /construcao
              - text: Construção
          - listitem [ref=e44]:
            - link "Menu Acessórios para Veículos" [ref=e45] [cursor=pointer]:
              - /url: /acessorios-para-veiculos
              - text: Acessórios para Veículos
          - listitem [ref=e46]:
            - link "Menu Pet Shop" [ref=e47] [cursor=pointer]:
              - /url: /pet-shop
              - text: Pet Shop
          - listitem [ref=e48]:
            - link "Menu Arte, Papelaria e Armarinho" [ref=e49] [cursor=pointer]:
              - /url: /arte-papelaria-e-armarinho
              - text: Arte, Papelaria e Armarinho
    - main [ref=e50]:
      - generic [ref=e52]:
        - generic [ref=e53]:
          - link [ref=e55] [cursor=pointer]:
            - /url: /
            - figure [ref=e56]
          - link [ref=e58] [cursor=pointer]:
            - /url: /
            - figure [ref=e59]
        - button "Próximo slide" [ref=e60] [cursor=pointer]: next
        - button "Slide anterior" [disabled] [ref=e61] [cursor=pointer]: prev
      - generic [ref=e67]:
        - link "Ir para [Home] Banner linha" [ref=e69] [cursor=pointer]:
          - /url: /
          - figure [ref=e70]:
            - img "[Home] Banner linha" [ref=e71]
          - generic [ref=e72]:
            - heading "Categoria" [level=2] [ref=e73]
            - paragraph [ref=e74]: Lorem ipsum dolor sit amet conse ctetur.
            - button "Call to action" [ref=e75]
        - link "Ir para [Home] Banner linha" [ref=e77] [cursor=pointer]:
          - /url: /
          - figure [ref=e78]:
            - img "[Home] Banner linha" [ref=e79]
          - generic [ref=e80]:
            - heading "Categoria" [level=2] [ref=e81]
            - paragraph [ref=e82]: Lorem ipsum dolor sit amet conse ctetur.
        - link "Ir para [Home] Banner linha" [ref=e84] [cursor=pointer]:
          - /url: /
          - figure [ref=e85]:
            - img "[Home] Banner linha" [ref=e86]
          - generic [ref=e87]:
            - heading "Categoria" [level=2] [ref=e88]
            - paragraph [ref=e89]: Lorem ipsum dolor sit amet conse ctetur.
            - button "Call to action" [ref=e90]
    - contentinfo [ref=e91]:
      - generic [ref=e92]:
        - generic [ref=e93]:
          - heading "Receba as novidades" [level=3] [ref=e94]
          - group [ref=e95]:
            - textbox [ref=e96] [cursor=pointer]
            - generic [ref=e97]:
              - textbox "Digite seu e-mail aqui" [ref=e99] [cursor=pointer]
              - button "Cadastrar e-mail na newsletter" [ref=e100] [cursor=pointer]: Cadastrar
          - generic [ref=e101]: Se inscreva na nossa newsletter para receber em primeira mão as últimas notícias, promoções exclusivas e dicas incríveis. Não perca nada!
        - navigation [ref=e102]:
          - list [ref=e103]:
            - listitem [ref=e104]:
              - link "Termos e Condições" [ref=e105] [cursor=pointer]:
                - /url: /p/termos
            - list [ref=e106]:
              - listitem [ref=e107]:
                - img [ref=e108]
                - link "Menu Política de Privacidade" [ref=e109] [cursor=pointer]:
                  - /url: /p/termos
                  - text: Política de Privacidade
              - listitem [ref=e110]:
                - img [ref=e111]
                - link "Menu Política de Trocas e Devoluções" [ref=e112] [cursor=pointer]:
                  - /url: /p/termos
                  - text: Política de Trocas e Devoluções
              - listitem [ref=e113]:
                - img [ref=e114]
                - link "Menu Política de Frete" [ref=e115] [cursor=pointer]:
                  - /url: /p/termos
                  - text: Política de Frete
          - list [ref=e116]:
            - listitem [ref=e117]:
              - heading "Institucional" [level=3] [ref=e118]
            - list [ref=e119]:
              - listitem [ref=e120]:
                - img [ref=e121]
                - link "Menu Quem somos" [ref=e122] [cursor=pointer]:
                  - /url: /p/quem-somos
                  - text: Quem somos
          - list [ref=e123]:
            - listitem [ref=e124]:
              - link "Ajuda" [ref=e125] [cursor=pointer]:
                - /url: /p/ajuda
            - list [ref=e126]:
              - listitem [ref=e127]:
                - img [ref=e128]
                - link "Menu Dúvidas Frequentes" [ref=e129] [cursor=pointer]:
                  - /url: https://shopvivaliz.com.br/p/ajuda
                  - text: Dúvidas Frequentes
              - listitem [ref=e130]:
                - img [ref=e131]
                - link "Menu Fale Conosco" [ref=e132] [cursor=pointer]:
                  - /url: /p/atendimento
                  - text: Fale Conosco
      - generic [ref=e133]:
        - navigation [ref=e134]:
          - link "Ir para instagram" [ref=e135] [cursor=pointer]:
            - /url: ""
            - img [ref=e136]
          - link "Ir para facebook" [ref=e138] [cursor=pointer]:
            - /url: ""
            - img [ref=e139]
          - link "Ir para whatsapp" [ref=e141] [cursor=pointer]:
            - /url: ""
            - img [ref=e142]
          - link "Ir para tiktok" [ref=e144] [cursor=pointer]:
            - /url: ""
            - img [ref=e145]
        - generic [ref=e147]:
          - img "Visa" [ref=e148]
          - img "Mastercard" [ref=e149]
          - img "Boleto" [ref=e150]
          - img "Depósito" [ref=e151]
          - img "Pix" [ref=e152]
      - generic [ref=e154]:
        - paragraph [ref=e155]: Nome da Loja - CNPJ XX.XXX.XXX/XXXX-XX
        - link "Ir para o site da Olist" [ref=e156] [cursor=pointer]:
          - /url: //www.vnda.com.br
          - text: "Technology:"
          - img [ref=e157]
  - generic [ref=e160]:
    - generic [ref=e163]: Clique aqui e fale com Shopvivaliz Ltda
    - button "Abrir atendimento por WhatsApp" [ref=e164] [cursor=pointer]:
      - img [ref=e165]
```