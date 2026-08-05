# Verificacao de deploy

A implantacao de producao deve usar somente o pipeline `Master Production Pipeline 24/7` apos o `Quality Gate` concluir com sucesso.

A confirmacao autoritativa e publicada em `deployment-evidence:deployment/latest.json` e deve conter o mesmo SHA de `main`, com os jobs `validate`, `deploy` e `smoke_test` em `success`.

Segredos de GA4, Google Ads, Olist e demais integracoes nunca devem ser versionados. O repositorio deve conter apenas nomes de variaveis e placeholders inequivocamente ficticios; valores reais pertencem aos secrets do ambiente de producao.
