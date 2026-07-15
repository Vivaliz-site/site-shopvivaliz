# Gamificacao ShopVivaliz

Superficie nova para badges e leaderboard mensal.

## Endpoint
- `GET /api/gamification/status.php`

## Pagina
- `/gamificacao.php`

## Regras atuais
- `Primeira compra`: ao menos 1 pedido registrado.
- `Cliente fiel`: ao menos 3 pedidos.
- `Avaliador`: ao menos 1 feedback.
- `Embaixador`: ao menos 5 pedidos e 2 feedbacks.
- `Top do mes`: ao menos 3 pedidos no mes corrente.

## Fontes de dados
- `storage/orders/*.json`
- `storage/support-feedback/*.json`

## Observacao
- A leaderboard usa apenas dados locais ja existentes no ambiente do projeto.
