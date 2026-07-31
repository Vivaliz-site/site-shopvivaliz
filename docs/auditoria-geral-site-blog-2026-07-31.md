# Auditoria geral do site e blog — 31/07/2026

## Escopo

Auditoria ponta a ponta da experiência pública da ShopVivaliz, com foco em:

- navegação e primeira dobra no mobile;
- convivência entre Liz, WhatsApp e barra inferior;
- homepage, catálogo, produto, carrinho e checkout;
- Central de Conhecimento e artigos;
- SEO técnico, acessibilidade e codificação UTF-8;
- exposição acidental de arquivos operacionais;
- cobertura E2E para evitar regressões.

## Achados críticos corrigidos

### 1. Liz e WhatsApp disputavam a mesma área no mobile

A combinação anterior deixava aproximadamente dois pixels de distância real entre os limites dos botões. Sombras, bordas e a escala do dispositivo faziam os elementos parecerem sobrepostos — e, em algumas dimensões, efetivamente disputarem a mesma área de toque.

Correção aplicada:

- WhatsApp permanece abaixo;
- Liz fica acima com distância visual e área de toque separadas;
- ambos sobem juntos quando existe barra de compra/checkout;
- WhatsApp é ocultado enquanto o painel da Liz está aberto;
- regras respeitam `safe-area-inset-bottom` do iPhone;
- transições são desativadas quando o usuário prefere movimento reduzido.

Arquivo: `css/visual-polish-v6-hotfix.css`.

### 2. Blog precisava de reforço técnico e visual

Correções aplicadas:

- schema.org `Blog` com os artigos publicados;
- metadados de indexação e prévia social mais completos;
- estado atual das categorias com `aria-current`;
- datas usando elemento `time`;
- dimensões e decodificação assíncrona das capas para reduzir mudança de layout;
- foco de teclado visível;
- cartões, textos longos e imagens mais estáveis;
- espaçamento inferior mobile compatível com a navegação fixa;
- suporte a movimento reduzido;
- versão do CSS atualizada para invalidar cache.

Arquivos: `blog/index.php` e `public/assets/blog/blog.css`.

### 3. Backup PHP no web root

`checkout.php.bak` continha código PHP e estava versionado na raiz pública. Mesmo quando o servidor não executa a extensão `.bak`, o conteúdo pode ser entregue como texto em configurações permissivas.

Correção aplicada:

- arquivo removido da árvore atual;
- o histórico do Git continua disponível para recuperação controlada;
- teste E2E exige resposta 403, 404 ou 410 para essa URL.

## Regressões automatizadas adicionadas

O fluxo `tests/e2e-journey.spec.js` agora valida:

- ausência de `Preço de pré-venda`, `0% OFF` e `R$ 0,00` na homepage;
- Liz e WhatsApp sem colisão em viewport 390 × 844;
- ocultação do WhatsApp quando a Liz abre;
- abertura e fechamento da Liz pelo teclado;
- blog com canonical, JSON-LD e texto sem mojibake;
- rotas públicas principais sem erro HTTP 500;
- headers essenciais de segurança;
- indisponibilidade pública de `checkout.php.bak`.

## Pontos observados que exigem validação após deploy

- confirmar o posicionamento em Safari iOS real, Chrome Android e desktop;
- executar o conjunto Playwright contra a URL publicada;
- validar que o banco possui artigos publicados e imagens acessíveis;
- confirmar que o CDN/proxy invalidou as versões antigas de CSS;
- revisar os resultados do workflow antes do merge automático, quando disponível.

## Critérios de aceite

- distância vertical mínima de 12 px entre Liz e WhatsApp no mobile;
- nenhuma sobreposição com a barra inferior ou barra de compra;
- WhatsApp inacessível visualmente enquanto o painel da Liz estiver aberto;
- homepage sem placeholders comerciais inválidos;
- blog sem texto corrompido por codificação;
- `/checkout.php.bak` não retorna conteúdo;
- rotas principais não retornam HTTP 500.
