# MedusaJS Migration Roadmap

Este documento define a migração do ShopVivaliz para uma base pronta com **MedusaJS** como backend principal de e-commerce.

## Decisão

Escolha oficial: **MedusaJS**

Motivos principais:
- arquitetura headless e modular
- melhor encaixe para integrações com ERPs e marketplaces
- front-end desacoplado, permitindo usar Next.js Commerce depois
- caminho mais limpo para manter catálogo, estoque, preços e canais separados

## Estratégia de migração

Vamos migrar por camadas, sem derrubar o site atual de uma vez:

1. Criar uma base MedusaJS separada para o novo core de loja
2. Manter o PHP atual como camada legada durante a transição
3. Integrar catálogo, estoque e pedidos por API
4. Substituir o front atual por Next.js Commerce quando o back estiver estável

## Alinhamento com EHA

A migração precisa manter a filosofia de melhoria autônoma do projeto.
Para isso, a EHA continua responsável por:

- validação automática
- auditoria de integrações
- QA recorrente
- relatórios cumulativos
- apoio à migração do legado

## Arquitetura alvo

- **Backend:** MedusaJS
- **Banco:** PostgreSQL
- **Cache/fila:** Redis
- **Frontend:** Next.js Commerce
- **Integrações:** Olist, Tiny, marketplaces e automações internas

## Fases

### Fase 1: Base pronta

- criar a estrutura `medusa/`
- documentar variáveis de ambiente
- definir fluxos de catálogo, produto e pedido
- mapear integrações obrigatórias

### Fase 2: Integrações core

- importar catálogo atual
- sincronizar estoque
- preparar webhook de pedido
- alinhar regras de preço e canais

### Fase 3: Front-end novo

- subir Next.js Commerce
- ligar homepage, catálogo, produto e carrinho
- validar SEO e performance

### Fase 4: Cutover gradual

- manter fallback no site antigo
- migrar tráfego por páginas e rotas
- desativar o legado apenas quando o novo fluxo estiver estável

## O que não fazer agora

- não apagar o projeto PHP atual
- não migrar tudo em um único passo
- não integrar marketplaces diretamente ao front
- não mudar o fluxo de produção sem validação local

## Próximo passo recomendado

Criar a estrutura inicial do workspace MedusaJS dentro do repositório e deixar pronto o contrato de integração com o legado.
