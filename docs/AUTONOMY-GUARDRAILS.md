# Guardrails de autonomia ShopVivaliz

## Objetivo

Manter o projeto operando com minima intervencao humana sem permitir que automacoes destruam historico, alterem producao sem validacao ou exponham credenciais.

## Permitido sem aprovacao humana

- monitoramento de disponibilidade e integridade;
- repeticao limitada de chamadas idempotentes;
- geracao de relatorios e artefatos;
- atualizacao de branches `automation/*`;
- abertura e atualizacao de pull requests;
- abertura e atualizacao de issues de incidente;
- sincronizacao de dados em arquivos previamente autorizados;
- testes, lint, analise estatica e verificacoes de seguranca;
- fechamento automatico de incidente somente apos verificacoes saudaveis consecutivas, quando implementado.

## Exige aprovacao ou protecao de ambiente

- merge em `main`;
- deploy de producao;
- alteracao de banco de dados;
- cobranca, estorno, cancelamento ou criacao de pedidos;
- mudanca de estoque em ERP ou marketplace;
- rotacao ou criacao de secrets;
- bloqueio de IP, firewall, WAF ou DNS;
- reinicio de servicos, encerramento de processos ou failover;
- exclusao de arquivos ou dados.

## Proibido

- `git push --force` em `main`;
- `git reset --hard` seguido de publicacao automatica;
- commit direto em `main` por jobs agendados;
- ignorar falhas com `|| true` em operacoes criticas;
- imprimir secrets, tokens ou senhas;
- desativar validacao da chave SSH;
- executar comandos recebidos de fontes externas sem allowlist.

## Modelo operacional

1. O agente detecta ou produz uma alteracao.
2. Valida escopo, sintaxe, testes e limites.
3. Publica em branch `automation/*`.
4. Abre ou atualiza um PR unico e reutilizavel.
5. Checks automatizados decidem se a alteracao e tecnicamente aceitavel.
6. Branch protection e ambientes protegidos controlam merge e deploy.
7. Incidentes geram issue deduplicada com evidencias.

Esse modelo preserva autonomia operacional, rastreabilidade e capacidade de reversao.