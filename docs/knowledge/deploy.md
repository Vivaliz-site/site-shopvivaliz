# Deploy

## Fluxo principal

O fluxo esperado é:

`push/merge em main → GitHub Actions → validações → deploy FTP → HostGator → verificação pós-deploy`

O merge no GitHub não comprova que a versão chegou ao servidor. Sempre verifique o workflow e o endpoint publicado.

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

Arquivos de upload, logs, relatórios e credenciais podem ser excluídos do deploy conforme a configuração do workflow. Confirme os `paths-ignore` e exclusões FTP antes de concluir que um arquivo deveria ter sido publicado.
