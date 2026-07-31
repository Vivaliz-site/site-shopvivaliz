# Auditoria de escritas no catálogo (`api/catalog/fallback-products.json`)

> Criado em 2026-07-18 depois de encontrar o catálogo revertido para dados
> antigos da API v2 do Tiny (`sync_source: "tiny_v2"`, sem imagens/dimensões)
> mesmo depois de confirmar um sync v3 correto pouco antes. Tentativa de
> configurar `auditd` (kernel audit) **não funcionou neste ambiente** —
> regras carregadas com sucesso via `auditctl`/`augenrules`, mas `ausearch`
> nunca retornou nenhum evento mesmo com escritas reais confirmadas por
> outro lado (mtime do arquivo mudando). Provavelmente o kernel da VM
> (Oracle Cloud) não expõe o subsistema de audit completo. Solução: watcher
> em Python rodando como serviço systemd, com polling simples de mtime.

## Como funciona

`scripts/catalog-audit-watcher.py` roda em loop (`shopvivaliz-catalog-audit.service`,
`Restart=always`, sobrevive a reboot), checando o mtime de
`api/catalog/fallback-products.json` a cada 5 segundos. A cada mudança detectada,
grava em `logs/catalog-audit.log`:
- timestamp UTC
- contagem total de produtos, `sync_source` do primeiro item, quantos têm `images` não vazio
- snapshot de todos os processos ativos no servidor cujo `cmd` contenha `php`/`python3`/`sync`/`olist`/`tiny`

## Instalação no servidor (já feita em produção, 2026-07-18)

```bash
sudo bash -c 'cat > /etc/systemd/system/shopvivaliz-catalog-audit.service <<EOF
[Unit]
Description=ShopVivaliz Catalog Audit Watcher
After=network.target

[Service]
Type=simple
User=ubuntu
WorkingDirectory=/home/ubuntu/site-shopvivaliz
ExecStart=/usr/bin/python3 /home/ubuntu/site-shopvivaliz/scripts/catalog-audit-watcher.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF'
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-catalog-audit.service
```

## Como consultar

```bash
tail -f logs/catalog-audit.log
```

## Escopo

Cobre só `fallback-products.json` por enquanto (era o arquivo com o problema
concreto). Se aparecer o mesmo tipo de regressão em outro arquivo crítico,
adicionar outro `CATALOG_PATH`-like alvo no mesmo padrão (ou generalizar o
script pra aceitar uma lista de arquivos via argumento) é a extensão natural.
