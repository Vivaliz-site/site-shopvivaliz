# ShopVivaliz — Blog de alta utilidade e auditoria inteligente sitewide

**Data:** 2026-09-11  
**Base:** `e093e19b1430c92a42cb7ee1c61378042790010a`  
**Branch:** `feat/blog-sitewide-quality-20260911`

## 1. Objetivo

Transformar a Central de Conhecimento em uma área editorial útil, confiável e comercialmente integrada ao catálogo, corrigindo a origem do conteúdo repetitivo; depois executar uma auditoria inteligente de todas as URLs públicas indexáveis do site e corrigir achados reais por prioridade, sem destabilizar fluxos transacionais.

A entrega só é considerada concluída após testes, PR, merge, deploy e validação pós-deploy com evidência real.

## 2. Diagnóstico de causa raiz

O problema não é apenas CSS. O blog público já possui canonical, Open Graph, JSON-LD, busca, categorias e layout responsivo, mas a automação editorial gera conteúdo a partir de três moldes fixos por dia da semana. Excertos, headings, listas e FAQs repetem estruturas como “Entenda o que avaliar”, “Aprenda um passo a passo simples” e “Veja ideias objetivas”. Isso cria baixa diferenciação semântica e sensação evidente de conteúdo fabricado em massa.

A produção usa `blog_articles` no MySQL quando disponível e `blog/content.php` somente como fallback. Portanto, alterar apenas o fallback não corrige os artigos que já foram publicados no banco. A solução precisa atuar em três estados: conteúdo futuro, conteúdo já publicado e apresentação pública.

A auditoria atual `scripts/ecommerce-excellence-audit.py --mode live` valida só um conjunto representativo de páginas, embora o sitemap contenha muitas URLs. Isso não satisfaz uma auditoria “em todas as páginas”.

## 3. Abordagens consideradas

### A. Redesign cosmético

Refazer hero, cards e tipografia. É rápida, mas preserva os textos repetitivos e o autopilot que recria o problema. Rejeitada.

### B. Redesign + reescrita manual do conteúdo atual

Melhora a percepção imediatamente, porém o gerador continuaria produzindo artigos genéricos. Rejeitada como solução final.

### C. Qualidade editorial por contrato + experiência nova + reparo do histórico + auditoria sitemap-driven

Recomendação adotada. Corrige a causa e adiciona mecanismos para impedir regressão.

## 4. Arquitetura proposta

### 4.1. Contrato editorial baseado em tipo de intenção

Substituir o molde por dia da semana por perfis de conteúdo associados à intenção do tema: guia de compra, comparativo, manutenção/diagnóstico, organização/projeto e tutorial. Cada perfil deve possuir uma estrutura específica e linguagem própria.

O gerador determinístico continua sem IA paga recorrente. Os artigos precisam apresentar critérios concretos, decisões, erros comuns, medidas ou limitações quando aplicáveis, e conexão coerente com o catálogo. A automação não deve inventar especificações de produtos, estoque, preço ou compatibilidade.

### 4.2. Quality gate editorial

`sv_blog_editorial_validate_article()` será ampliado para rejeitar:

- excertos ou headings de boilerplate proibido;
- conteúdo com pouca diferenciação entre seções;
- texto excessivamente curto;
- repetição de frases entre artigos gerados;
- CTA ou busca de catálogo sem termo coerente;
- metadados fora das faixas existentes;
- FAQ sem valor informacional.

Os testes precisam provar a rejeição do molde antigo e a aceitação dos novos perfis.

### 4.3. Reparo seguro do conteúdo já publicado

Adicionar uma rotina CLI idempotente de reparo editorial que:

1. opere somente em slugs reconhecidos da agenda automática;
2. faça backup lógico dos registros-alvo antes da alteração;
3. reconstrua os artigos pelo novo contrato mantendo slug e data de publicação;
4. atualize apenas campos editoriais, sem mexer em comentários ou identificadores;
5. aceite `--dry-run` e exiba contagens, nunca secrets;
6. possa ser reexecutada sem produzir divergência.

O deploy não executará silenciosamente alterações de banco. A rotina será disparada de forma controlada após backup e validada por leitura independente.

### 4.4. Nova experiência da Central de Conhecimento

A home do blog deixará de ser apenas uma grade cronológica. Ela terá:

- hero mais curto e orientado à solução;
- conteúdo em destaque selecionado por `featured` ou relevância;
- atalhos por necessidade/categoria;
- seção “Guias para escolher melhor”;
- seção de conteúdos recentes;
- cards com hierarquia melhor e CTA explícito;
- sidebar apenas quando útil em desktop;
- integração clara com catálogo, sem transformar artigo em anúncio;
- mobile first, acessibilidade e movimento reduzido preservados.

### 4.5. Página de artigo

Preservar a estrutura segura existente, acrescentando navegação de leitura e informação útil: sumário derivado das seções, blocos de decisão/alerta quando presentes, CTA contextual ao termo do artigo e relacionados mais coerentes. Não adicionar HTML editorial bruto ao banco.

## 5. Auditoria inteligente de todas as páginas

A auditoria live passará a usar o sitemap como inventário canônico de páginas públicas. Depois dos endpoints críticos, cada URL same-origin do sitemap será validada com limites de timeout e tamanho para evitar loops ou carga desnecessária.

### 5.1. Checagens por URL

- status HTTP e cadeia/final de redirect;
- URL final versus canonical;
- `title`, meta description, H1 e robots;
- JSON-LD válido;
- Open Graph básico;
- ausência de mojibake e placeholders editoriais visíveis;
- duplicidade de title e description entre URLs indexáveis;
- conteúdo excessivamente raso para páginas editoriais;
- imagens principais com referência válida quando detectável;
- links internos críticos quebrados em amostra limitada e determinística por página.

### 5.2. Classificação

- **blocker:** 5xx/4xx em URL do sitemap, canonical ausente/incompatível, JSON-LD inválido, redirect para origem externa inesperada, página indexável sem título;
- **warning:** metadados fracos/duplicados, H1 inadequado, OG ausente, conteúdo editorial raso, imagem ausente;
- **info:** oportunidades de UX/SEO que não quebram o comportamento.

A auditoria deve gerar JSON e Markdown com totais, páginas afetadas e códigos estáveis para permitir regressão.

## 6. Correções sitewide após o crawl

Não haverá “redesign geral” cego. Cada achado real será corrigido na origem com esta ordem:

1. segurança, HTTP, canonical e indexação;
2. navegação quebrada e fluxo de compra;
3. SEO técnico e dados estruturados;
4. UX mobile/acessibilidade;
5. conteúdo/clareza/conversão;
6. performance e acabamento visual.

Checkout, pagamentos, ERP, pedido e autenticação só serão alterados se a auditoria demonstrar falha diretamente relacionada; nenhum efeito financeiro real será disparado durante QA.

## 7. Testes e evidência

### TDD focal

- adicionar regressões para qualidade editorial antes da implementação;
- adicionar testes unitários/fixtures para crawl completo do sitemap e detecção de duplicidade/canonical;
- provar RED no comportamento atual e GREEN após a correção.

### Validação em camadas

1. PHP/Python syntax e lint;
2. testes focais de blog e auditor;
3. Quality Gate completo;
4. `ecommerce-excellence-audit.py --mode static`;
5. auditoria live da produção após deploy;
6. smoke visual real do blog em desktop e mobile via browser autorizado;
7. smoke das rotas críticas do ecommerce.

## 8. Rollout e rollback

- alterações versionadas seguem branch → PR → checks → merge;
- produção usa releases imutáveis existentes;
- antes do reparo editorial no banco, gerar backup dos registros-alvo;
- se a UI falhar, rollback para release anterior;
- se o reparo de conteúdo produzir resultado inválido, restaurar registros do backup sem apagar comentários;
- preservar URLs/slugs existentes para não perder indexação.

## 9. Critérios de aceite

- o autopilot não produz mais os padrões genéricos atuais;
- todos os artigos automáticos futuros passam por quality gate editorial;
- artigos automáticos já publicados são reparados de modo idempotente;
- `/blog/` apresenta hierarquia editorial clara e melhor descoberta;
- artigos mantêm SEO, comentários e URLs atuais;
- auditoria live percorre todas as URLs válidas do sitemap e reporta por página;
- blockers reais encontrados na auditoria são corrigidos ou documentados como bloqueio externo comprovado;
- Quality Gate e auditorias canônicas passam;
- PR mergeada, produção no SHA correto e validação pós-deploy concluída;
- UI comprovada em navegador real, desktop e mobile.
