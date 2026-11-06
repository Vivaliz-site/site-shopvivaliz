# Marina: backup MySQL nativo para OCI Object Storage

Fonte versionada: ops/mysql-native-oci-backup.py; instalador scripts/install-mysql-native-oci-backup.sh.

- systemd oneshot e timer diario 03:25 UTC mais atraso aleatorio de ate 10min. Persistent=true repoe execucoes perdidas.
- Snapshot root via socket local, transacao consistente, com rotinas/eventos/triggers. Nenhum segredo fornecido por argv ou logs.
- OCI instance_principal existente, bucket shopvivaliz-marina-archive. Download do arquivo e manifesto apos cada upload para conferir tamanho, SHA-256 e GZIP.
- Copias locais root:root 0600 em /var/backups/shopvivaliz-mysql-native; diretorio 0700. Lock flock para impedir duplicidades, minimo de 10 GiB livres.
- Sem exclusao automatica de backups. Retencao e descarte exigem politica especifica do proprietario.
- Testes: python3 -m py_compile ops/mysql-native-oci-backup.py; sudo python3 ops/mysql-native-oci-backup.py --check; systemd-analyze verify unidades; sudo systemctl start shopvivaliz-mysql-native-oci-backup.service; depois Result=success, ExitCode=0, arquivo .verified.json, e systemctl list-timers.
- Apenas MySQL nativo. Pricing possui MySQL containerizado independente (porta 13312): nao coberto por este backup; exige procedimento separado.
- Para restauracao real, baixar dump e manifesto pelo OCI CLI oficial, comparar SHA-256, descomprimir, restaurar em MySQL isolado, validar integridade e contagens. Nunca restaurar em producao para testar.
