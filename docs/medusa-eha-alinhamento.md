# MedusaJS + EHA Alignment

Este documento registra como a migração para MedusaJS deve conviver com a linha de melhoria autônoma do ShopVivaliz, chamada aqui de **EHA**.

## Princípio

- **MedusaJS** será o core comercial da loja
- **EHA** continuará como camada de melhoria, automação, validação e evolução contínua
- o legado PHP permanece até a migração ganhar estabilidade

## Papel da EHA na nova arquitetura

EHA deve atuar em:

- validação de catálogo e produtos
- checagem de integridade de integrações
- monitoramento de sincronização com ERP e marketplaces
- automações de QA, auditoria e relatórios
- apoio à migração gradual do legado para MedusaJS

## O que manter

- rotinas de validação já existentes
- scripts de auditoria e relatórios cumulativos
- filosofia de melhoria contínua
- revisão automática antes de releases

## O que adaptar

- separar automações legadas de fluxos do novo backend
- criar integrações por API em vez de acoplamento direto ao front antigo
- mapear eventos do Medusa para os agentes EHA

## Recomendação prática

Tratar a transição assim:

1. MedusaJS recebe o domínio de e-commerce
2. EHA observa, valida e corrige
3. o PHP antigo serve como fallback temporário

