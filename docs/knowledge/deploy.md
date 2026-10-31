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

## Gate verificavel de conclusao de deploy

Depois do merge, obtenha o commit de `main`, rode `python3 scripts/quality/deployment_closure_guard.py --expected-sha <SHA_MAIN>` e exija exit 0 com evidencia de deploy, revisao publica igual e smoke test aprovado. Se o retorno for diferente de zero, a tarefa permanece `RUNNING`; corrigir pipeline e revalidar. `.github/workflows/production-deployment-closure-guard.yml` reaudita apos o pipeline e quando acionado manualmente com alerta rastreavel em issue unico. Nao substituir isso por PR, testes green ou apenas health 200.

<!-- DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2 -->
## Gate de conclusão verificável e preços de ponta a ponta

Todo deploy implantável exige a mesma revisão comprovada por `main`, `current/.release-sha`, `/api/health/version.php` e `deployment-evidence/deployment/latest.json`; `validate`, `deploy` e `smoke_test` devem estar em `success`. Depois do deploy executar as jornadas reais da UI aplicáveis, incluindo catálogo, produto e carrinho (sem compra real). Sem prova, estado `RUNNING/NAO_APTO`.

A auditoria integral do ERP Olist/Tiny é implementada em `scripts/quality/audit-storefront-erp-prices.py`. Ela consulta todos os produtos ativos e a **tabela Amazon 991 do Olist/Tiny** pelo OAuth v3 protegido, recalcula o preço efetivo pelo percentual geral ou pelas exceções de ID do produto, compara preço regular/promocional e proveniência com o cache derivado, confere todos os preços públicos e acusa SKU duplicado mesmo se um dos registros estiver correto. O preço-base de cadastro não é a referência de publicação. NUNCA altera preços no ERP. O resultado `PRICE_PARITY_VERDICT=FAIL` permanece pendência operacional, não permite afirmar catálogo integralmente validado.

Execução controlada após publicação: `.github/workflows/postdeploy-erp-price-parity.yml`, ativado pelo sucesso do `Master Production Pipeline 24/7` com SHA de deploy confirmado, também com agenda diária e execução manual. Só executa o comparativo completo se o SHA da evidência imutável corresponder ao da release ativa. Um relatório é armazenado em `/home/ubuntu/shopvivaliz-deploy/shared/logs/price-parity-<SHA>.json` e anexado como artefato de workflow quando possível. Falhas devem ser analisadas e corrigidas, não ignoradas nem mascaradas com preços locais.

A política `human-approved-price-stock` e os controles de deploy/ownership continuam intactos. Sem aprovação obrigatória ou webhook real, não marcar `CONCLUIDO`.
<!-- /DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2 -->
