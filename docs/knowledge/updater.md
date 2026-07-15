# Atualizador Cumulativo

## Objetivo

Permitir que uma instalação compatível avance diretamente para a versão mais recente sem aplicar manualmente cada atualização intermediária.

## Requisitos obrigatórios

1. Declarar a versão mínima compatível.
2. Validar estrutura do ZIP e arquivos essenciais antes da cópia.
3. Criar backup sem incluir recursivamente backups anteriores.
4. Copiar arquivos de forma determinística e registrar copiados e ignorados.
5. Executar SQLs e migrations automaticamente.
6. Tornar cada migration idempotente.
7. Executar reparos de vínculo de produtos, imagens, preços e demais relações afetadas.
8. Limpar ou invalidar cache quando necessário.
9. Executar testes rápidos após a instalação.
10. Encerrar com resumo claro de sucesso, avisos e falhas.

## Idempotência

Uma migration executada novamente não pode destruir dados nem duplicar estruturas. Use verificações como existência de tabela, coluna, índice ou registro de migration antes de modificar o banco.

## Falhas

Falhas críticas de banco, cópia ou integridade devem interromper a atualização e manter evidência suficiente para diagnóstico. Não marcar a versão como instalada quando uma etapa obrigatória falhar.

## Proibição de etapas manuais

SQLs, migrations e reparos de vínculo não devem depender de abrir páginas administrativas ou URLs de manutenção após a atualização.
