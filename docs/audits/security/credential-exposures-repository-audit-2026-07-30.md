# Auditoria de exposições de credenciais no repositório

Data: 2026-07-30.

## Escopo

Durante o fechamento da reorganização estrutural, o auditor identificou credenciais ou tokens preenchidos em documentação histórica.

## Classes afetadas

- assinatura/token de webhook Tiny/Olist;
- credenciais e tokens Shopee, incluindo sandbox e OAuth;
- segredo de webhook Mercado Pago;
- token Olist identificado em lote anterior.

Os valores não são reproduzidos neste relatório.

## Ações executadas na árvore atual

- documentos substituídos por versões sanitizadas;
- exemplos alterados para variáveis de ambiente ou placeholders não autenticáveis;
- auditor de secrets mantido como gate de CI;
- incidentes registrados em `docs/audits/security/`.

## Ações externas obrigatórias

1. Rotacionar todas as credenciais afetadas no respectivo provedor.
2. Atualizar somente GitHub Secrets/Environment Secrets ou gerenciador aprovado.
3. Revogar tokens/códigos antigos quando o provedor permitir.
4. Verificar logs e integrações que possam ter reutilizado os valores.
5. Planejar limpeza coordenada do histórico Git depois da rotação.

## Limitação

Sanitizar a branch e o PR não remove valores de commits anteriores. Não declarar o incidente encerrado antes da rotação e, quando exigido, da limpeza validada do histórico.
