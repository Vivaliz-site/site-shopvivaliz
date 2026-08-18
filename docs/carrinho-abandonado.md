# E-mail de carrinho abandonado

## Como funciona

1. `checkout.php` captura o e-mail (fire-and-forget, via `blur` no campo) e envia pra
   `api/checkout/track-abandonment.php`, que faz upsert em `checkout_abandonments`
   (tabela criada em `includes/account-schema.php`, roda automaticamente via
   `sv_account_ensure_schema()` nos fluxos que já incluem esse arquivo).
2. `scripts/send-abandoned-cart-emails.php` (rodar via cron) varre essa tabela e
   dispara e-mail com o cupom `VOLTEI5` (5% off, já existe em `coupons`, não é
   anunciado publicamente de propósito) para quem:
   - preencheu e-mail há mais de 1h e menos de 48h,
   - ainda não recebeu o e-mail de recuperação,
   - não tem um pedido concluído com esse e-mail depois do momento do abandono
     (checado via `NOT EXISTS` contra `orders`, sem precisar marcar nada no
     fluxo de criação do pedido).

## Configurar o cron

Adicionar na VM (mesmo padrão dos outros crons documentados em `CLAUDE.md`):

```
*/30 * * * * php /home/ubuntu/site-shopvivaliz/scripts/send-abandoned-cart-emails.php >> /home/ubuntu/site-shopvivaliz/logs/abandoned-cart-email.log 2>&1
```

Ainda **não foi adicionado ao crontab da VM** — depende de acesso SSH funcional
(ver bloqueio documentado na sessão de 2026-08-14/15) ou de alguém rodar
manualmente na VM.

## Validação pendente

- [ ] Confirmar que a captura de e-mail no checkout está gravando de verdade
      (checar `SELECT COUNT(*) FROM checkout_abandonments` após um teste real
      de preencher o checkout sem finalizar).
- [ ] Rodar `scripts/send-abandoned-cart-emails.php` manualmente uma vez pra
      validar que o e-mail chega e o cupom `VOLTEI5` funciona no checkout.
- [ ] Adicionar o cron acima ao crontab da VM.
