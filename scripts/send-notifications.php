<?php
require_once __DIR__ . '/mailer.php';
/**
 * Notificador de Impedimentos - Trio IA
 * Envia emails quando há bloqueios ou erros
 */

class TriaNotifier {
    private $emailTo;
    private $emailFrom;
    private $logFile;

    public function __construct() {
        $this->emailTo = getenv('EMAIL_TO') ?: getenv('NOTIFY_EMAIL_TO') ?: '';
        $this->emailFrom = getenv('EMAIL_FROM') ?: getenv('SMTP_USER') ?: getenv('EMAIL_USER') ?: getenv('MAIL_USER') ?: 'trio-ia@shopvivaliz.com.br';
        $this->logFile = __DIR__ . '/../logs/notifications.log';
        @mkdir(dirname($this->logFile), 0755, true);
    }

    /**
     * Notificar sobre impedimento
     */
    public function notifyBlockage($title, $description, $taskId = null) {
        $subject = "🚨 Trio IA - Impedimento Detectado: $title";

        $body = $this->buildEmailBody([
            'type' => 'blockage',
            'title' => $title,
            'description' => $description,
            'task_id' => $taskId,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_url' => 'https://dev.shopvivaliz.com.br/admin/monitor/'
        ]);

        $this->send($subject, $body, 'blockage', $title);
    }

    /**
     * Notificar sobre erro
     */
    public function notifyError($title, $errorMessage, $taskId = null) {
        $subject = "❌ Trio IA - Erro: $title";

        $body = $this->buildEmailBody([
            'type' => 'error',
            'title' => $title,
            'error' => $errorMessage,
            'task_id' => $taskId,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_url' => 'https://dev.shopvivaliz.com.br/admin/monitor/'
        ]);

        $this->send($subject, $body, 'error', $title);
    }

    /**
     * Notificar sobre tarefa concluída
     */
    public function notifyTaskComplete($taskId, $title) {
        $subject = "✅ Trio IA - Tarefa Completa: $title";

        $body = $this->buildEmailBody([
            'type' => 'success',
            'title' => $title,
            'task_id' => $taskId,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_url' => 'https://dev.shopvivaliz.com.br/admin/monitor/'
        ]);

        $this->send($subject, $body, 'success', $title);
    }

    /**
     * Notificar sobre necessidade de intervenção manual
     */
    public function notifyNeedsAttention($title, $description, $requiredAction) {
        $subject = "⚠️ Trio IA - Requer Atenção: $title";

        $body = $this->buildEmailBody([
            'type' => 'attention',
            'title' => $title,
            'description' => $description,
            'action_required' => $requiredAction,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_url' => 'https://dev.shopvivaliz.com.br/admin/monitor/'
        ]);

        $this->send($subject, $body, 'attention', $title);
    }

    /**
     * Relatório diário
     */
    public function sendDailyReport($stats) {
        $subject = "📊 Trio IA - Relatório Diário";

        $body = $this->buildEmailBody([
            'type' => 'report',
            'stats' => $stats,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_url' => 'https://dev.shopvivaliz.com.br/admin/monitor/'
        ]);

        $this->send($subject, $body, 'report', 'Relatório Diário');
    }

    /**
     * Enviar email
     */
    private function send($subject, $body, $type, $title) {
        if ($this->emailTo === '') {
            $this->log($type, $title, 'Destino de email nao configurado');
            return false;
        }

        $headers = [
            'From' => $this->emailFrom,
            'Reply-To' => $this->emailFrom,
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer' => 'Trio IA v1.0'
        ];

        $headerStr = '';
        foreach ($headers as $key => $value) {
            $headerStr .= "$key: $value\r\n";
        }

        // Log da tentativa
        $this->log($type, $title, $subject);

        // Enviar email (usar função mail do PHP ou SMTP)
        if (function_exists('send_email')) {
            $result = send_email($this->emailTo, $subject, $body);
        } else {
            $result = @mail($this->emailTo, $subject, $body, $headerStr);
        }

        if ($result) {
            $this->log($type, $title, 'Email enviado com sucesso');
        } else {
            $this->log($type, $title, 'Falha ao enviar email');
        }

        return $result;
    }

    /**
     * Construir corpo do email
     */
    private function buildEmailBody($data) {
        foreach (['title', 'description', 'error', 'task_id', 'action_required', 'status_url', 'timestamp'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                $data[$key] = htmlspecialchars((string)$data[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $type = $data['type'] ?? 'info';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 4px 4px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 4px 4px; }
        .info-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #667eea; }
        .error-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #ef4444; }
        .success-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #10b981; }
        .warning-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #f59e0b; }
        .timestamp { font-size: 0.9em; color: #666; margin-top: 10px; }
        .button { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 4px; margin-top: 15px; }
        .stats { margin: 15px 0; }
        .stat-row { padding: 8px; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0; font-size: 1.5em;">🤖 Trio IA Autônomo</h2>
            <p style="margin: 5px 0 0 0; opacity: 0.9;">Notificação de Sistema</p>
        </div>
        <div class="content">
HTML;

        switch ($type) {
            case 'blockage':
                $html .= <<<HTML
            <div class="error-box">
                <strong style="color: #ef4444;">🚨 Impedimento Detectado</strong>
                <p style="margin: 10px 0 0 0;"><strong>{$data['title']}</strong></p>
                <p style="margin: 5px 0 0 0;">{$data['description']}</p>
HTML;
                if ($data['task_id']) {
                    $html .= "<p style=\"margin: 5px 0 0 0; font-size: 0.9em; color: #666;\">Tarefa: {$data['task_id']}</p>";
                }
                $html .= "</div>";
                break;

            case 'error':
                $html .= <<<HTML
            <div class="error-box">
                <strong style="color: #ef4444;">❌ Erro Detectado</strong>
                <p style="margin: 10px 0 0 0;"><strong>{$data['title']}</strong></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 0.9em;">{$data['error']}</pre>
HTML;
                if ($data['task_id']) {
                    $html .= "<p style=\"margin: 5px 0 0 0; font-size: 0.9em; color: #666;\">Tarefa: {$data['task_id']}</p>";
                }
                $html .= "</div>";
                break;

            case 'success':
                $html .= <<<HTML
            <div class="success-box">
                <strong style="color: #10b981;">✅ Tarefa Concluída</strong>
                <p style="margin: 10px 0 0 0;"><strong>{$data['title']}</strong></p>
                <p style="margin: 5px 0 0 0; font-size: 0.9em; color: #666;\">ID: {$data['task_id']}</p>
            </div>
HTML;
                break;

            case 'attention':
                $html .= <<<HTML
            <div class="warning-box">
                <strong style="color: #f59e0b;">⚠️ Requer Atenção</strong>
                <p style="margin: 10px 0 0 0;"><strong>{$data['title']}</strong></p>
                <p style="margin: 5px 0 0 0;">{$data['description']}</p>
                <p style="margin: 10px 0 0 0; color: #ef4444; font-weight: bold;">Ação Necessária: {$data['action_required']}</p>
            </div>
HTML;
                break;

            case 'report':
                $html .= <<<HTML
            <div class="info-box">
                <strong>📊 Relatório Diário</strong>
                <div class="stats">
HTML;
                if (is_array($data['stats'])) {
                    foreach ($data['stats'] as $key => $value) {
                        $html .= "<div class=\"stat-row\"><strong>$key:</strong> $value</div>";
                    }
                }
                $html .= <<<HTML
                </div>
            </div>
HTML;
                break;
        }

        $html .= <<<HTML
            <div style="margin-top: 20px; text-align: center;">
                <a href="{$data['status_url']}" class="button">Ver Status</a>
            </div>
            <div class="timestamp">
                Timestamp: {$data['timestamp']}
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Log
     */
    private function log($type, $title, $message) {
        $entry = json_encode([
            'timestamp' => date('c'),
            'type' => $type,
            'title' => $title,
            'message' => $message
        ]) . "\n";

        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }
}

// Exemplo de uso:
if (php_sapi_name() === 'cli') {
    $notifier = new TriaNotifier();

    // Teste: enviar notificação de bloqueio
    if ($argc > 1 && $argv[1] === 'test') {
        $notifier->notifyBlockage(
            'Credenciais de API Inválidas',
            'A autenticação com a API do Gemini falhou. Verifique as credenciais em GitHub Secrets.',
            'task-001'
        );
        echo "✅ Notificação de teste processada para o destino configurado\n";
    }
}
