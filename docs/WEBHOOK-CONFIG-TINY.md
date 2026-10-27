# Configuracao de notificacoes ERP Olist/Tiny

**Referencia atual:** [docs/TINY-WEBHOOKS-SETUP.md](TINY-WEBHOOKS-SETUP.md).

Os eventos de produto, estoque e preco pertencem a **Integrações -> API do ERP -> Gerenciar -> Notificações**, e devem apontar para o endpoint canonico `/api/olist/webhook.php` com autenticacao por segredo dedicado e verificacao de `idEcommerce`.

Nao configurar o caminho legado `/api/webhooks/tiny-product-price-sync.php` para notificacoes novas, nem aceitar o preco recebido no corpo do webhook como fonte de verdade. O receptor deve obter o valor pelo detalhe do produto na API v3 do ERP e atualizar o cache derivado.

Olastro operacional exige teste real do provedor com HTTP 200, evidencia do evento recebido, consulta ao ERP e read-back do site. Ausencia de eventos nao equivale a integracao valida, mesmo que o timer de reconciliacao esteja funcionando.

Para instrucoes completas de autenticacao, teste e diagnostico, consultar o documento referenciado acima.
