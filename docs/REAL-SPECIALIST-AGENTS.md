# Agentes especialistas 24/7 com trabalho real

Este conjunto substitui descrições genéricas por inspeções verificáveis do repositório.

## Agentes implementados

### Designer Autônomo
- percorre páginas PHP/HTML reais;
- mede páginas, imagens e formulários;
- identifica imagens sem `alt`, campos sem referência acessível e páginas sem `<main>`;
- registra arquivo, linha e trecho de evidência.

### Analytics Agent
- percorre PHP, JavaScript, TypeScript e HTML;
- verifica instrumentação analítica;
- mede referências a `view_item`, `add_to_cart`, `begin_checkout` e `purchase`;
- falha quando eventos essenciais ou o tracker estão ausentes.

### Deploy Manager
- lê todos os workflows atuais;
- identifica workflows de deploy existentes;
- bloqueia comandos de push direto amplo, `git add -A`, reset destrutivo e force push;
- denuncia falhas mascaradas por `continue-on-error` ou `|| echo`.

O Deploy Manager é um gate de segurança e evidência. Ele não publica sozinho e não recebe permissão de escrita. O deploy continua sendo executado pelo pipeline de produção já aprovado, após os gates do repositório.

### Security Agent
- percorre arquivos textuais reais;
- procura padrões de tokens GitHub, chaves OpenAI, AWS e chaves privadas;
- procura comandos destrutivos;
- nunca imprime uma credencial completa: a evidência é redigida.

## Evidências

Cada execução produz:

- `reports/specialist-agents/latest.json`;
- `reports/specialist-agents/latest.md`;
- SHA-256 do JSON;
- artifact do GitHub Actions retido por 30 dias;
- associação explícita com o commit analisado.

## Política de sucesso

Uma execução somente passa quando:

1. o script terminou;
2. os dois relatórios existem e não estão vazios;
3. o JSON contém o commit, o agente, métricas e achados;
4. nenhum achado de severidade alta ou crítica foi encontrado.

Não há:

- alteração de `tasks-queue.json`;
- marcação artificial de tarefa concluída;
- `git push`;
- auto-merge;
- `contents: write`;
- `continue-on-error`;
- sucesso sem artifact.

## Agenda

O workflow `.github/workflows/real-specialist-agents.yml` roda a cada seis horas, manualmente e em pull requests que alterem superfícies relevantes.
