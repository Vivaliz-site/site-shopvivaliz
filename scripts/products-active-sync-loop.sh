#!/usr/bin/env bash
# Mantém products.active espelhando a lista de ativos do Tiny/Olist.
# Nao existe evento de webhook para ativacao/desativacao de produto em
# nenhuma versao da API Tiny/Olist (confirmado em 2026-08-11 nas docs
# oficiais: https://tiny.com.br/api-docs/api2-webhooks e
# https://olist.mintlify.app/documentacao/webhooks/webhooks) -- por isso
# esse sync precisa ser por polling periodico, nao por evento.
set -Eeuo pipefail

ROOT="/home/ubuntu/shopvivaliz-deploy/current"
INTERVAL_SECONDS="${PRODUCTS_ACTIVE_SYNC_INTERVAL:-10800}"

while true; do
    # Re-resolve o symlink "current" a cada ciclo -- releases trocam a cada
    # deploy (cron de 2min) e um `cd` feito uma unica vez fora do loop nao
    # acompanha a troca do symlink em um processo de longa duracao.
    cd "$ROOT"
    echo "[$(date -Is)] Iniciando ciclo de sincronizacao de produtos ativos"
    if /usr/bin/python3 daemon-sync-products.py --once --workers 4; then
        # O daemon canonico consulta lista Amazon + estoque ERP e aplica
        # revision guard; nunca executar o importador PHP de precos base.
        php sync-daemon-to-db.php
    else
        echo "[$(date -Is)] Falha ao buscar produtos ativos do Tiny; pulando aplicacao no banco neste ciclo"
    fi
    echo "[$(date -Is)] Ciclo concluido; dormindo ${INTERVAL_SECONDS}s"
    sleep "$INTERVAL_SECONDS"
done
