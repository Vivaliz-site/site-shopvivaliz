# Relatório histórico sanitizado — validação de 2026-07-24

## Resumo preservado

O relatório histórico registrou testes de páginas, recursos, checkout, integrações, responsividade, SEO, segurança e dados críticos. Foram relatados 92 itens auditados, com parte automatizada e parte dependente de testes manuais/transações reais.

## Correções registradas na ocasião

- recurso visual do Mercado Pago disponibilizado;
- configuração de webhook revisada;
- páginas principais verificadas por HTTP e navegador headless;
- itens manuais permaneceram pendentes, incluindo interações de carrinho, pagamento e email.

## Sanitização

A versão anterior continha um valor de autenticação de webhook. Esse valor foi removido da árvore atual, não é reproduzido aqui e deve ser considerado comprometido. A credencial correspondente precisa ser rotacionada no provedor/servidor e armazenada somente em secret protegido.

## Limitação histórica

Este documento não comprova o estado atual do site. Para validação vigente, use checks, logs, artifacts, resposta HTTP e testes executados no commit atual.
