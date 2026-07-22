# Emergency redeploy trigger

Data: 2026-07-22
Motivo: indisponibilidade/tela branca reportada no dominio principal.

Acoes esperadas pelo pipeline e sincronizacao da VM:
- validar o commit atual;
- sincronizar `main` na VM Oracle;
- restaurar a storefront estavel;
- executar health check da homepage.

Validar apos o deploy:
- https://www.shopvivaliz.com.br/
- https://shopvivaliz.com.br/
