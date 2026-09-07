# Deploy

## Fluxo principal

O fluxo canônico é:

`push/merge em main → GitHub Actions → validações → release imutável na VM → troca atômica de current → smoke test → verificação pós-deploy`

A produção usa `/home/ubuntu/shopvivaliz-deploy/releases/<release>` e o symlink `/home/ubuntu/shopvivaliz-deploy/current`. Nunca edite a release ativa nem `current` diretamente. O deploy manual revisado usa `repo/scripts/deploy-production.sh <SHA>` sob `/var/lock/shopvivaliz-deploy.lock`.

O merge no GitHub não comprova que a versão chegou ao servidor. Só considere deploy comprovado quando `origin/main`, `current/.release-sha`, o endpoint `/api/health/version.php` e o smoke test concordarem com o SHA esperado.

## Teste via curl

Health geral:

```bash
curl -i 'https://SEU-DOMINIO/api/health.php'
```

Squad Chat:

```bash
curl -i 'https://SEU-DOMINIO/api/agent/squad-chat.php?health=1'
```

Teste POST:

```bash
curl -i -X POST 'https://SEU-DOMINIO/api/agent/squad-chat.php' \
  -H 'Content-Type: application/json' \
  --data '{"message":"teste de deploy"}'
```

## Exemplo de GitHub Actions para validação

```yaml
name: Validate ShopVivaliz

on:
  pull_request:
  push:
    branches: [main]

jobs:
  validate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
      - name: PHP lint
        shell: bash
        run: |
          while IFS= read -r -d '' file; do
            echo "::group::php -l $file"
            php -l "$file"
            echo "::endgroup::"
          done < <(find . -type f -name '*.php' -not -path './vendor/*' -print0)
      - name: Validate required knowledge
        run: |
          test -f docs/knowledge/project.md
          test -f docs/knowledge/squad-chat.md
          test -f docs/knowledge/troubleshooting.md
          test -f docs/knowledge/deploy.md
          test -f docs/knowledge/agent-rules.md
```

## Atualizador automático

Toda atualização cumulativa deve:

1. executar preflight;
2. criar backup sem recursão;
3. copiar arquivos;
4. executar SQLs e migrations idempotentes;
5. executar reparos de vínculo necessários;
6. limpar ou invalidar cache quando aplicável;
7. executar testes rápidos;
8. registrar arquivos copiados, migrations executadas, ignoradas e falhas;
9. interromper com erro claro quando uma etapa crítica falhar.

Não deve ser necessário abrir URLs manuais para concluir SQL, migration ou reparo.

## Checklist de deploy

- [ ] CI concluída sem falhas.
- [ ] Commit não contém `[skip ci]` quando deploy é necessário.
- [ ] Cache limpo ou versionado.
- [ ] Permissões de arquivos e diretórios conferidas.
- [ ] Arquivo atualizado presente no destino.
- [ ] `.env` e secrets disponíveis sem exposição de valores.
- [ ] Banco conectado.
- [ ] SQLs e migrations executados automaticamente.
- [ ] Reparos de vínculo executados automaticamente.
- [ ] Catálogo testado com preço e imagem.
- [ ] Carrinho e checkout testados no mobile.
- [ ] Health check validado pelo conteúdo da resposta.
- [ ] Logs verificados após a publicação.

## Observações

Arquivos persistentes, uploads, logs, relatórios e credenciais devem permanecer em `shared/` ou em outro storage persistente previsto pelo deploy; não devem ser gravados dentro de releases imutáveis. Confirme o workflow, os scripts de deploy e os symlinks de persistência antes de concluir que um arquivo deveria estar dentro da release.

O `.env` de produção é persistente em `shared/.env`. Nunca o imprima. Atualizações devem preservar permissões/ownership e remover somente linhas malformadas que não sejam comentário, linha vazia ou atribuição `KEY=VALUE` válida.
