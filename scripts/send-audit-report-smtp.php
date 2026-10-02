<?php
declare(strict_types=1);

// Legacy entrypoint retained for compatibility. The canonical implementation
// uses the ShopVivaliz Brevo API mailer and fails closed.
require __DIR__ . '/send-audit-report.php';
