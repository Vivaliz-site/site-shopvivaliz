# Olist/Tiny OAuth — fluxo canônico

## Fonte de verdade

- Autorização/reautorização humana: `/olist/connect.php` → `/olist/callback.php`.
- Rotação automática: `daemon-token-renewer.py` via `shopvivaliz-token-renewer.service`.
- Estado rotativo: `/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json`.
- `.env` é bootstrap/compatibilidade; o token store privado tem prioridade para access/refresh token.

## Regra de segurança

Nenhum consumidor de pedidos, catálogo, publicação ou monitoramento pode trocar `refresh_token` diretamente. Esses componentes apenas leem o access token publicado pelo renovador. Isso evita concorrência entre refreshes e perda do refresh token rotacionado pelo provedor.

## Renovação preventiva

O serviço verifica no máximo a cada 5 minutos e inicia a renovação 30 minutos antes do vencimento. Access token e refresh token retornados pelo provedor são persistidos atomicamente no storage privado antes da próxima utilização.

## Reautorização

Se o provedor revogar o grant ou o aplicativo, abra `/olist/connect.php`, autorize o aplicativo e deixe `/olist/callback.php` concluir. O callback nunca devolve material do token e grava o novo estado no mesmo token store usado pelo daemon.

## Recuperação manual

O workflow `Olist OAuth Runtime` apenas invoca `daemon-token-renewer.py --once` na VM e valida a API v3. Ele não contém uma segunda implementação de refresh.
