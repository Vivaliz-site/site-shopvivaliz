<?php
/**
 * OrderNotificationService - Central idempotent transaction email notification service.
 */

declare(strict_types=1);

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

class OrderNotificationService
{
    private static ?self $instance = null;
    private $db;
    private array $whitelist = [
        'shopvivaliz@gmail.com',
        'fredmourao@gmail.com',
        'atendimento@shopvivaliz.com.br'
    ];
    private int $maxAttempts = 5;

    private function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Map provider status to internal events.
     */
    public function mapStatus(string $provider, string $status, ?string $detail = null): ?string
    {
        $provider = strtolower(trim($provider));
        $status = strtolower(trim($status));
        $detail = $detail ? strtolower(trim($detail)) : '';

        if ($provider === 'mercadopago') {
            // approved, rejected, pending, in_process, in_mediation, rejected, cancelled, refunded, charged_back
            return match ($status) {
                'approved' => 'pagamento_aprovado',
                'rejected' => 'pagamento_recusado',
                'cancelled', 'expired' => 'pagamento_expirado',
                'refunded' => 'reembolso_concluido',
                default => null
            };
        }

        if ($provider === 'pagarme') {
            // paid, refused, pending, refunded, canceled
            return match ($status) {
                'paid' => 'pagamento_aprovado',
                'refused' => 'pagamento_recusado',
                'canceled' => 'pedido_cancelado',
                'refunded' => 'reembolso_concluido',
                default => null
            };
        }

        if ($provider === 'tiny' || $provider === 'olist') {
            // waiting_payment, payment_approved, invoice_sent, invoiced, ready_to_ship, shipped, delivered, cancelled, returned
            return match ($status) {
                'waiting_payment' => 'pedido_criado',
                'payment_approved' => 'pagamento_aprovado',
                'invoice_sent', 'invoiced' => 'nota_fiscal_emitida',
                'ready_to_ship' => 'pedido_em_preparacao',
                'shipped' => 'pedido_enviado',
                'delivered' => 'pedido_entregue',
                'cancelled' => 'pedido_cancelado',
                'returned' => 'troca_devolucao_solicitada',
                default => null
            };
        }

        if ($provider === 'melhorenvio') {
            // posted, delivered, etc.
            return match ($status) {
                'posted', 'shipped' => 'pedido_enviado',
                'delivered' => 'pedido_entregue',
                default => null
            };
        }

        return $status; // Default fallback: assume direct event name
    }

    /**
     * Centralized order event notification dispatcher.
     */
    public function notifyOrderEvent(
        string $orderId,
        string $eventName,
        array $eventData,
        ?string $externalEventId = null
    ): bool {
        $orderId = trim($orderId);
        $eventName = trim($eventName);
        if ($orderId === '' || $eventName === '') {
            error_log("[OrderNotificationService] Missing order ID or event name.");
            return false;
        }

        // Resilient fallback: if customer info or items are missing, load from JSON storage
        if (empty($eventData['customer']['email']) || empty($eventData['items'])) {
            $eventData = $this->loadOrderJsonData($orderId, $eventData);
        }

        $email = trim((string)($eventData['customer']['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log("[OrderNotificationService] Invalid email: '$email' for order '$orderId'.");
            return false;
        }

        $payloadHash = hash('sha256', json_encode($eventData));
        $externalEventIdClean = $externalEventId ? trim($externalEventId) : null;

        // Idempotency check: query DB for existing record
        $existing = $this->findExistingNotification($orderId, $eventName, $payloadHash, $externalEventIdClean);
        if ($existing) {
            if ($existing['status'] === 'sent') {
                error_log("[OrderNotificationService] Idempotency match: event '$eventName' for order '$orderId' already sent. Skipping.");
                return true;
            }
            if ($existing['status'] === 'failed' && (int)$existing['attempts'] >= $this->maxAttempts) {
                error_log("[OrderNotificationService] Idempotency match: event '$eventName' failed and exceeded max attempts. Skipping.");
                return false;
            }
            // If exists but failed, reuse record id
            $recordId = (int)$existing['id'];
            $attempts = (int)$existing['attempts'];
        } else {
            // Log new attempt in DB
            $recordId = $this->createNotificationRecord($orderId, $eventName, $payloadHash, $externalEventIdClean, $email, $eventName);
            if ($recordId === 0) {
                // Duplicate transaction block: query again to be sure or skip
                error_log("[OrderNotificationService] Duplicate transaction blocked by DB unique index. Skipping.");
                return true;
            }
            $attempts = 0;
        }

        // Render template
        $subject = $this->getSubject($eventName, $orderId);
        [$html, $text] = $this->renderTemplates($eventName, $subject, $eventData);

        // Sandbox check
        $recipient = $email;
        $sandboxMode = $this->isSandboxMode();
        if ($sandboxMode && !in_array(strtolower($recipient), $this->whitelist, true)) {
            $recipient = 'shopvivaliz@gmail.com';
            $subject = "[SANDBOX - Para: $email] " . $subject;
        }

        // Trigger send
        $result = $this->sendEmail($recipient, $subject, $html, $text);

        if ($result['success']) {
            $this->updateRecordSuccess($recordId, $attempts + 1, $result['message_id']);
            error_log("[OrderNotificationService] E-mail sent successfully to $recipient for event $eventName. MessageID: " . $result['message_id']);
            return true;
        } else {
            $this->updateRecordFailure($recordId, $attempts + 1, $result['error']);
            error_log("[OrderNotificationService] Failed to send e-mail to $recipient: " . $result['error']);
            return false;
        }
    }

    private function findExistingNotification(
        string $orderId,
        string $eventName,
        string $payloadHash,
        ?string $externalEventId
    ): ?array {
        $sql = 'SELECT id, status, attempts FROM order_email_notifications 
                WHERE order_id = ? AND event_name = ? AND (payload_hash = ?';
        if ($externalEventId !== null) {
            $sql .= ' OR external_event_id = ?';
        }
        $sql .= ') LIMIT 1';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return null;

        if ($externalEventId !== null) {
            $stmt->bind_param('ssss', $orderId, $eventName, $payloadHash, $externalEventId);
        } else {
            $stmt->bind_param('sss', $orderId, $eventName, $payloadHash);
        }

        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function createNotificationRecord(
        string $orderId,
        string $eventName,
        string $payloadHash,
        ?string $externalEventId,
        string $recipientEmail,
        string $templateName
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO order_email_notifications 
                (order_id, event_name, external_event_id, recipient_email, template_name, payload_hash, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, "pending", NOW())'
            );
            if (!$stmt) return 0;
            $stmt->bind_param('ssssss', $orderId, $eventName, $externalEventId, $recipientEmail, $templateName, $payloadHash);
            $stmt->execute();
            if ($this->db->affected_rows <= 0) {
                // Check if already exists to get ID
                $check = $this->findExistingNotification($orderId, $eventName, $payloadHash, $externalEventId);
                return $check ? (int)$check['id'] : 0;
            }
            return $this->db->insert_id;
        } catch (Throwable $e) {
            error_log('[OrderNotificationService] DB Insert Error: ' . $e->getMessage());
            return 0;
        }
    }

    private function updateRecordSuccess(int $id, int $attempts, string $messageId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE order_email_notifications 
             SET status = "sent", attempts = ?, provider_message_id = ?, sent_at = NOW(), updated_at = NOW() 
             WHERE id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('isi', $attempts, $messageId, $id);
            $stmt->execute();
        }
    }

    private function updateRecordFailure(int $id, int $attempts, string $error): void
    {
        $stmt = $this->db->prepare(
            'UPDATE order_email_notifications 
             SET status = "failed", attempts = ?, last_error = ?, updated_at = NOW() 
             WHERE id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('isi', $attempts, $error, $id);
            $stmt->execute();
        }
    }

    private function getSubject(string $eventName, string $orderId): string
    {
        return match ($eventName) {
            'pedido_criado' => "Recebemos seu pedido #$orderId",
            'pagamento_aprovado' => "Pagamento aprovado — Pedido #$orderId",
            'pagamento_recusado' => "Não foi possível aprovar o pagamento do pedido #$orderId",
            'pagamento_expirado' => "O pagamento do pedido #$orderId expirou",
            'nota_fiscal_emitida' => "Nota fiscal emitida — Pedido #$orderId",
            'pedido_em_preparacao' => "Seu pedido #$orderId está sendo preparado",
            'pedido_enviado' => "Seu pedido #$orderId foi enviado",
            'saiu_para_entrega' => "Seu pedido #$orderId saiu para entrega",
            'tentativa_entrega_falhou' => "Precisamos de atenção na entrega do pedido #$orderId",
            'pedido_entregue' => "Pedido #$orderId entregue",
            'pedido_cancelado' => "Pedido #$orderId cancelado",
            'reembolso_solicitado' => "Reembolso solicitado — Pedido #$orderId",
            'reembolso_concluido' => "Reembolso concluído — Pedido #$orderId",
            'troca_devolucao_solicitada' => "Troca ou devolução solicitada — Pedido #$orderId",
            default => "Atualização do seu Pedido #$orderId"
        };
    }

    private function renderTemplates(string $eventName, string $subject, array $data): array
    {
        $dir = dirname(__DIR__) . '/templates/emails';
        $layout = @file_get_contents("$dir/layout.html") ?: '{{main_content}}';
        $htmlContent = @file_get_contents("$dir/$eventName.html") ?: '';
        $textContent = @file_get_contents("$dir/$eventName.txt") ?: '';

        // Build variables replacement map
        $vars = $this->buildReplacementVars($subject, $data);

        // Replace vars in text
        $renderedText = $this->replacePlaceholders($textContent, $vars);

        // Replace vars in html body first
        $renderedHtmlBody = $this->replacePlaceholders($htmlContent, $vars);

        // Merge into layout
        $renderedHtml = str_replace(
            ['{{subject}}', '{{main_content}}'],
            [$subject, $renderedHtmlBody],
            $layout
        );
        $renderedHtml = $this->replacePlaceholders($renderedHtml, $vars);

        return [$renderedHtml, $renderedText];
    }

    private function replacePlaceholders(string $content, array $vars): string
    {
        $search = [];
        $replace = [];
        foreach ($vars as $key => $val) {
            $search[] = '{{' . $key . '}}';
            $replace[] = $val;
        }
        return str_replace($search, $replace, $content);
    }

    private function buildReplacementVars(string $subject, array $data): array
    {
        $customerName = htmlspecialchars((string)($data['customer']['name'] ?? 'Cliente'));
        $orderId = (string)($data['order_number'] ?? $data['id'] ?? 'N/A');
        $total = number_format((float)($data['total'] ?? 0), 2, ',', '.');
        $paymentLabel = htmlspecialchars((string)($data['payment_label'] ?? 'PIX'));
        $paymentInstructions = htmlspecialchars((string)($data['payment_instructions'] ?? ''));
        if (($data['payment_method'] ?? '') === 'boleto') {
            $mpBoleto = $data['mercadopago']['boleto'] ?? null;
            if (is_array($mpBoleto) && !empty($mpBoleto['ticket_url'])) {
                $ticketUrl = htmlspecialchars((string)$mpBoleto['ticket_url']);
                $digitableLine = htmlspecialchars((string)($mpBoleto['digitable_line'] ?? ''));
                $paymentInstructions = "Para efetuar o pagamento, utilize a linha digitável abaixo ou clique no link para visualizar o boleto:<br><br>";
                if ($digitableLine !== '') {
                    $paymentInstructions .= "<strong>Linha Digitável:</strong><br><code style='background-color:#e2e8f0; padding:4px 8px; border-radius:4px; display:inline-block; margin:6px 0; word-break:break-all;'>$digitableLine</code><br><br>";
                }
                $paymentInstructions .= "<a href='$ticketUrl' target='_blank' style='display:inline-block; padding:10px 20px; background-color:#10b981; color:#ffffff; text-decoration:none; font-weight:bold; border-radius:6px;'>Visualizar Boleto Bancário</a>";
            }
        }
        $carrier = htmlspecialchars((string)($data['shipping_label'] ?? 'Correios'));
        $trackingCode = htmlspecialchars((string)($data['tracking_number'] ?? $data['tracking_code'] ?? 'Em breve'));
        $trackingUrl = 'https://www.melhorenvio.com.br/rastreamento/' . urlencode($trackingCode);
        $estimatedDelivery = htmlspecialchars((string)($data['estimated_delivery'] ?? 'Não informada'));
        $invoiceUrl = htmlspecialchars((string)($data['invoice_url'] ?? '#'));
        $refundAmount = number_format((float)($data['refund_amount'] ?? $data['total'] ?? 0), 2, ',', '.');

        // Cancel instructions explaining financial impact
        $cancelReason = (string)($data['cancel_reason'] ?? '');
        $cancelReasonInstructions = "O seu pedido foi cancelado e nenhuma cobrança adicional será gerada. ";
        if (in_array($data['payment_method'] ?? '', ['pix', 'mercado_pago', 'pagarme'], true)) {
            $cancelReasonInstructions .= "Caso o pagamento já tenha sido debitado, o valor de R$ $total será estornado automaticamente no mesmo meio de pagamento em até 2 dias úteis.";
        } else {
            $cancelReasonInstructions .= "Caso tenha pago o boleto, por favor entre em contato com nosso suporte informando os dados bancários para transferência de devolução.";
        }

        $refundInstructions = "O estorno de R$ $refundAmount foi processado na operadora. ";
        if (($data['payment_method'] ?? '') === 'pix') {
            $refundInstructions .= "A devolução ocorrerá diretamente na conta de origem via Pix chave em até 24 horas.";
        } else {
            $refundInstructions .= "O crédito será lançado na fatura do seu cartão de crédito em até 30 dias (conforme as regras do seu banco).";
        }

        // WhatsApp details
        $whatsapp = getenv('LOJA_WHATSAPP') ?: '551140415850';
        $whatsappFormatted = '(' . substr($whatsapp, 2, 2) . ') ' . substr($whatsapp, 4, 5) . '-' . substr($whatsapp, 9);

        // Generate items rows and text representation
        $itemsRows = '';
        $itemsText = '';
        foreach ($data['items'] ?? [] as $item) {
            $name = htmlspecialchars((string)($item['name'] ?? 'Produto'));
            $qty = (int)($item['quantity'] ?? 1);
            $price = number_format((float)($item['price'] ?? 0), 2, ',', '.');
            $itemsRows .= "<tr><td>$name</td><td style='text-align:center;'>$qty</td><td style='text-align:right;'>R$ $price</td></tr>";
            $itemsText .= "- $name ($qty x R$ $price)\n";
        }

        return [
            'subject' => $subject,
            'customer_name' => $customerName,
            'order_id' => $orderId,
            'total' => $total,
            'payment_label' => $paymentLabel,
            'payment_instructions' => $paymentInstructions,
            'carrier' => $carrier,
            'tracking_code' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'estimated_delivery' => $estimatedDelivery,
            'invoice_url' => $invoiceUrl,
            'refund_amount' => $refundAmount,
            'cancel_reason_instructions' => htmlspecialchars($cancelReasonInstructions),
            'refund_instructions' => htmlspecialchars($refundInstructions),
            'whatsapp_raw' => $whatsapp,
            'whatsapp_formatted' => $whatsappFormatted,
            'support_email' => getenv('SUPPORT_EMAIL') ?: 'contato@shopvivaliz.com.br',
            'current_year' => date('Y'),
            'items_rows' => $itemsRows,
            'items_text' => $itemsText
        ];
    }

    private function loadOrderJsonData(string $orderId, array $fallbackData): array
    {
        $preferred = dirname(__DIR__) . "/storage/orders/$orderId.json";
        $temp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "shopvivaliz-orders/$orderId.json";
        
        $path = is_file($preferred) ? $preferred : (is_file($temp) ? $temp : '');
        if ($path !== '') {
            $json = @file_get_contents($path);
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    // Merge dynamically loaded data with existing webhook update details
                    return array_replace_recursive($decoded, $fallbackData);
                }
            }
        }
        return $fallbackData;
    }

    private function isSandboxMode(): bool
    {
        $smtpUser = getenv('SMTP_USER') ?: '';
        // If SMTP_USER is gmail or APP_ENV is dev/staging, default to sandbox mode
        if (str_contains($smtpUser, 'gmail') || getenv('APP_ENV') === 'development' || getenv('APP_ENV') === 'staging') {
            return true;
        }
        return false;
    }

    private function sendEmail(string $to, string $subject, string $html, string $text): array
    {
        // Safe SMTP config parsing
        $host = getenv('SMTP_HOST') ?: getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $port = (int)(getenv('SMTP_PORT') ?: getenv('MAIL_PORT') ?: 465);
        $user = getenv('SMTP_USER') ?: getenv('MAIL_USER') ?: '';
        $pass = getenv('SMTP_PASS') ?: getenv('MAIL_PASS') ?: '';
        $fromEmail = getenv('SMTP_FROM') ?: getenv('EMAIL_FROM') ?: $user;
        $fromName = getenv('SMTP_FROMNAME') ?: getenv('EMAIL_FROM_NAME') ?: 'ShopVivaliz';

        if ($user === '' || $pass === '') {
            return ['success' => false, 'error' => 'SMTP credentials missing from environment.'];
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
            $mail->Timeout = 12; // Short timeout so webhooks don't block
            
            // Connect implicitly using SSL on 465, or STARTTLS on 587/other ports
            if ($port === 465) {
                $mail->SMTPSecure = 'ssl';
            } else {
                $mail->SMTPSecure = 'tls';
            }

            // Disable peer verification errors for local testing if needed
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;

            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;

            $mail->CharSet = 'UTF-8';
            $mail->Encoding = '8bit';

            $mail->send();
            
            $messageId = $mail->getLastMessageID() ?: ('local_' . uniqid('', true));
            return ['success' => true, 'message_id' => $messageId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /**
     * CLI Retry runner helper.
     */
    public function retryPendingNotifications(): int
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, event_name, recipient_email, payload_hash, external_event_id, attempts, updated_at 
             FROM order_email_notifications 
             WHERE status != "sent" AND attempts < ?'
        );
        if (!$stmt) return 0;
        
        $stmt->bind_param('i', $this->maxAttempts);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $retriedCount = 0;
        foreach ($rows as $row) {
            $recordId = (int)$row['id'];
            $attempts = (int)$row['attempts'];
            $updatedAt = strtotime($row['updated_at']);
            
            // Exponential backoff logic: wait at least 2^attempts minutes since last attempt
            $delay = pow(2, $attempts) * 60;
            if (time() - $updatedAt < $delay) {
                continue; // Skip for now
            }

            $orderId = $row['order_id'];
            $eventName = $row['event_name'];
            $externalEventId = $row['external_event_id'];

            // Reload order JSON data
            $eventData = $this->loadOrderJsonData($orderId, []);
            if (empty($eventData['customer']['email'])) {
                error_log("[OrderNotificationService] [Retry] Order data not found or missing customer details for order $orderId. Skipping.");
                continue;
            }

            $subject = $this->getSubject($eventName, $orderId);
            [$html, $text] = $this->renderTemplates($eventName, $subject, $eventData);

            $recipient = $row['recipient_email'];
            if ($this->isSandboxMode() && !in_array(strtolower($recipient), $this->whitelist, true)) {
                $recipient = 'shopvivaliz@gmail.com';
                if (!str_starts_with($subject, '[SANDBOX')) {
                    $subject = "[SANDBOX - Para: {$row['recipient_email']}] " . $subject;
                }
            }

            $result = $this->sendEmail($recipient, $subject, $html, $text);
            if ($result['success']) {
                $this->updateRecordSuccess($recordId, $attempts + 1, $result['message_id']);
                $retriedCount++;
            } else {
                $this->updateRecordFailure($recordId, $attempts + 1, $result['error']);
            }
        }
        return $retriedCount;
    }
}
