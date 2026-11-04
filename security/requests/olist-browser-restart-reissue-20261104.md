# Pedido de reemissao do grant `olist_browser_restart` (Marina)

Data: 2026-11-04. Substitui o grant da PR #3560 (2026-11-02), que o autorizador
atual nega com `github_queue_owner_provenance_not_verified`: aquele merge
precede a regra de proveniencia da fila nativa. Nenhum privilegio novo; mesma
operacao, mesma unit, mesmo host.

## Escopo exato
- Host `marina-a1-4c24g`, principal `marina-mcp-service`, recurso `mcp:marina`.
- Capacidade `deployment_manage`; unica operacao `olist_browser_restart`.
- Alvo `Vivaliz-site/amazon-returns-safet`; unit fixa
  `amazon-returns-olist-erp-browser.service`.
- Sem shell, root HTTP, JWT externo administrativo, start/stop/enable ou edicao
  de unit. Lease emitido pelo host, fencing e auditoria obrigatorios.
- TTL: ate 2026-12-04T23:59:00Z.

## Riscos
- Restart da unit do navegador ERP do Olist interrompe a sessao em andamento.
  Mitigacao: lease/fencing, ticket de uso unico, resultado indeterminado sem
  repeticao automatica.

## Testes
- `tests/test_validate_owner_pr_grant.py` e suite `tests/test_marina*.py`.
- Na Marina: preflight do grant com o cliente do broker como
  `shopvivaliz-marina-mcp` apos o merge.

## Rollback
- Remover `/etc/shopvivaliz/marina-root-broker/client-grant.json` e reiniciar
  `shopvivaliz-marina-mcp.service`: a ferramenta do Olist volta a ficar desligada
  sem afetar `service_restart` nem a leitura.

## Decisao humana
Somente `shopvivaliz-human` emite APPROVED no HEAD final. Este pedido nao
concede nenhum outro escopo.
