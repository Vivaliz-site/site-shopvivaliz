# Safe Sync da Marina: manifesto canonico CRLF deixa checkout permanentemente sujo

Registro: 2026-11-02. Host valido marina-a1-4c24g; checkout /home/ubuntu/shopvivaliz-deploy/sync-repo. Nao e a release imutavel de producao.

## Falha reproduzida

Apos PR #3508, shopvivaliz-sync-safe.service falhou repetidamente com 'checkout remoto permaneceu sujo apos reconciliacao'. O proprio sistema logou SAFE_SYNC_REPAIR=NORMALIZED_LINE_ENDING_ONLY_DRIFT. git status mostrava somente docs/quality/GLOBAL_AUDIT_MANIFEST.json, git diff --ignore-space-at-eol era limpo, e git ls-files --eol mostrava 'i/crlf w/crlf attr/text eol=lf'. O Git blob do HEAD e o blob bruto tinham SHA 5f405fc25229f584bb6c880cfe82c0e0db386dda, mas git hash-object --path era diferente por normalizacao LF imposta a todos os JSON.

O manifesto canônico mantido por Vivaliz-site/-shopvivaliz-pipeline e um blob CRLF exato; o gate global exige paridade integral dos bytes, entao nao e permitido normalizar somente uma copia nem falsificar hashes.

## Correcao minima e seguranca

Em .gitattributes, adicionar uma unica excecao: docs/quality/GLOBAL_AUDIT_MANIFEST.json -text -eol. Mantem *.json text eol=lf para todos os outros arquivos. Nao altera o manifesto, a assinatura Git, a politica de auditoria, dados de producao, CI checks, nem permissoes de usuarios.

Teste de regressao tests/test_manifest_byte_parity_sync.py (byte-a-byte em disco e HEAD, hash de Git filtrado, atributos da excecao, outro JSON controle e git status limpo), executado pelo workflow canonical-manifest-byte-parity-ci.yml apenas quando caminhos relevantes mudarem.

## Recuperacao apos merge

1. Confirmar identidade Ubuntu, usuario correto, git status de sync-repo com somente o manifesto e diferenca EOL-only; interromper se existir qualquer mudanca nao classificada.
2. Confirmar no origin/main o blob CRLF exato e .gitattributes com a excecao, usando SHA e checks obrigatorios.
3. Reconciliar checkout de sincronizacao pela rotina oficialmente autorizada, com backup e controle de concorrencia. Nao executar reset amplo nem apagar alteracoes de terceiro.
4. Validar systemctl start shopvivaliz-sync-safe.service, Result=success, git status limpo, HEAD atualizado, e release imutavel nao editada.
5. Em caso de politica externa negar, registrar impedimento sem contornar recusas por outro canal.

Este defeito nao e ausencia de sudo no Linux: e incompatibilidade entre blob CRLF canonico e politica de normalizacao Git LF.
