#!/usr/bin/env python3
"""
Reparador legado aposentado.

Regra atual: cadastro, imagens, preco e estoque devem vir do ERP Olist/Tiny API v3.
Este script fazia reparo direto em tabelas locais e podia ressuscitar dados fora do ERP.
Use o fluxo canonico:
  php olist/sync-products.php
  php olist/fetch-estoque-v3.php
  php scripts/quality/validate-olist-catalog-integrity.php
"""
import sys

print("ERRO: scripts/reparo-catalogo-olist.py foi aposentado; use o sync ERP Olist/Tiny v3 canonico.", file=sys.stderr)
sys.exit(2)
