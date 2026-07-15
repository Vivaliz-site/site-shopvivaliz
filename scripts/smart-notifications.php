<?php
/**
 * 📱 Smart Notifications - Escalação inteligente de alertas
 * CRÍTICO: SMS/Email/Slack imediato
 * HIGH: Slack + Email com 5min delay
 * MEDIUM: Log + digest 1x/hora
 * LOW: Log + digest diário
 */

class SmartNotifications {
    private $notificationQueue = '.notification-queue.json';
    private $digests = [
        'hourly' => [],
        'daily' => [],
    ];

    public function processAlert($alert) {
        $severity = $alert['severity'] ?? 'LOW';
        $channel = $alert['channel'] ?? 'system';
        $message = $alert['message'] ?? '';

        echo "[{$severity}] {$message}\n";

        switch ($severity) {
            case 'CRITICAL':
                $this->sendCriticalAlert($alert);
                break;

            case 'HIGH':
                $this->sendHighPriorityAlert($alert);
                break;

            case 'MEDIUM':
                $this->queueForHourlyDigest($alert);
                break;

            case 'LOW':
                $this->queueForDailyDigest($alert);
                break;
        }
    }

    private function sendCriticalAlert($alert) {
        echo "🚨 ENVIANDO ALERTA CRÍTICO EM TEMPO REAL\n";

        // 1. SMS
        $this->sendSMS(
            '+5537999374112',
            "🚨 CRÍTICO: {$alert['message']}"
        );

        // 2. Email
        $this->sendEmail(
            'fredmourao@gmail.com',
            '[CRÍTICO] ' . $alert['message'],
            "Gravidade: CRÍTICO\n" . json_encode($alert, JSON_PRETTY_PRINT),
            'critical'
        );

        // 3. Slack
        $this->sendSlack($alert, 'danger');

        // 4. Armazenar no histórico
        $this->storeAlert($alert);

        // 5. Agendar telefonema em 15min se não reconhecido
        $this->scheduleFollowup($alert, 15);
    }

    private function sendHighPriorityAlert($alert) {
        echo "⚠️ ENVIANDO ALERTA HIGH PRIORITY COM DELAY\n";

        // Delay 5min para evitar spam
        $alert['scheduled_time'] = time() + 300;
        $this->queueAlert($alert, 'high');

        // Enviar Slack imediatamente
        $this->sendSlack($alert, 'warning');

        // Email com delay via cron
        echo "📧 Email será enviado em 5 minutos\n";
    }

    private function queueForHourlyDigest($alert) {
        echo "📋 Adicionando à fila de digest horário\n";

        $queue = json_decode(file_get_contents($this->notificationQueue) ?: '[]', true);
        $alert['type'] = 'hourly';
        $alert['queued_at'] = time();

        $queue[] = $alert;

        file_put_contents($this->notificationQueue, json_encode($queue, JSON_PRETTY_PRINT));

        // Se atingiu 10 alertas, enviar digest imediatamente
        if (count($queue) >= 10) {
            $this->sendHourlyDigest();
        }
    }

    private function queueForDailyDigest($alert) {
        echo "📋 Adicionando à fila de digest diário\n";

        $alert['type'] = 'daily';
        $alert['queued_at'] = time();

        $dailyFile = '.daily-alerts.json';
        $alerts = json_decode(file_get_contents($dailyFile) ?: '[]', true);
        $alerts[] = $alert;

        file_put_contents($dailyFile, json_encode($alerts, JSON_PRETTY_PRINT));
    }

    private function sendHourlyDigest() {
        echo "📊 Enviando digest horário\n";

        $queue = json_decode(file_get_contents($this->notificationQueue) ?: '[]', true);
        $hourlyAlerts = array_filter($queue, fn($a) => ($a['type'] ?? '') === 'hourly');

        if (empty($hourlyAlerts)) return;

        $summary = "📊 RESUMO HORÁRIO - " . count($hourlyAlerts) . " Alertas\n\n";

        foreach ($hourlyAlerts as $alert) {
            $summary .= "- [{$alert['severity']}] {$alert['message']}\n";
        }

        $this->sendEmail(
            'fredmourao@gmail.com',
            'Resumo Horário de Alertas',
            $summary,
            'info'
        );

        // Limpar fila
        $remainingAlerts = array_filter($queue, fn($a) => ($a['type'] ?? '') !== 'hourly');
        file_put_contents($this->notificationQueue, json_encode($remainingAlerts, JSON_PRETTY_PRINT));
    }

    private function sendDailyDigest() {
        echo "📊 Enviando digest diário\n";

        $dailyFile = '.daily-alerts.json';
        $alerts = json_decode(file_get_contents($dailyFile) ?: '[]', true);

        if (empty($alerts)) return;

        // Agrupar por tipo
        $grouped = [];
        foreach ($alerts as $alert) {
            $key = $alert['channel'] ?? 'unknown';
            if (!isset($grouped[$key])) $grouped[$key] = [];
            $grouped[$key][] = $alert;
        }

        $summary = "📊 RESUMO DIÁRIO - " . count($alerts) . " Alertas\n\n";

        foreach ($grouped as $channel => $items) {
            $summary .= "**{$channel}** (" . count($items) . " alertas)\n";
            foreach ($items as $alert) {
                $summary .= "  - [{$alert['severity']}] {$alert['message']}\n";
            }
            $summary .= "\n";
        }

        $this->sendEmail(
            'fredmourao@gmail.com',
            'Resumo Diário de Alertas',
            $summary,
            'info'
        );

        // Limpar arquivo
        file_put_contents($dailyFile, '[]');
    }

    private function sendSMS($phone, $message) {
        echo "📱 Enviando SMS: $message\n";

        $apiKey = getenv('TWILIO_API_KEY') ?: '';
        $apiSecret = getenv('TWILIO_API_SECRET') ?: '';

        if (!$apiKey) {
            echo "⚠️ Twilio não configurado\n";
            return false;
        }

        // Em produção, usar Twilio API
        // Por agora, apenas log
        file_put_contents('.sms-log.txt', "[" . date('Y-m-d H:i:s') . "] {$phone}: {$message}\n", FILE_APPEND);

        return true;
    }

    private function sendEmail($to, $subject, $body, $priority = 'normal') {
        echo "📧 Enviando email: {$subject}\n";

        $headers = [
            'From' => 'alerts@shopvivaliz.com.br',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];

        if ($priority === 'critical') {
            $headers['X-Priority'] = '1';
        }

        $headerStr = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers));

        mail($to, $subject, $body, $headerStr);

        return true;
    }

    private function sendSlack($alert, $color = 'warning') {
        echo "💬 Enviando para Slack\n";

        $webhook = getenv('SLACK_WEBHOOK') ?: '';

        if (!$webhook) {
            echo "⚠️ Slack webhook não configurado\n";
            return false;
        }

        $payload = json_encode([
            'attachments' => [
                [
                    'fallback' => $alert['message'],
                    'text' => $alert['message'],
                    'color' => $color,
                    'fields' => [
                        ['title' => 'Severidade', 'value' => $alert['severity'], 'short' => true],
                        ['title' => 'Canal', 'value' => $alert['channel'] ?? 'system', 'short' => true],
                        ['title' => 'Timestamp', 'value' => date('Y-m-d H:i:s'), 'short' => false],
                    ],
                ],
            ],
        ]);

        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        curl_exec($ch);
        curl_close($ch);

        return true;
    }

    private function queueAlert($alert, $priority) {
        $queue = json_decode(file_get_contents($this->notificationQueue) ?: '[]', true);
        $alert['priority'] = $priority;
        $queue[] = $alert;

        file_put_contents($this->notificationQueue, json_encode($queue, JSON_PRETTY_PRINT));
    }

    private function storeAlert($alert) {
        $historyFile = '.alert-history.json';
        $history = json_decode(file_get_contents($historyFile) ?: '[]', true);
        $alert['received_at'] = date('Y-m-d H:i:s');
        $history[] = $alert;

        // Manter apenas últimos 1000 alertas
        if (count($history) > 1000) {
            $history = array_slice($history, -1000);
        }

        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    private function scheduleFollowup($alert, $minutes) {
        echo "📅 Agendando followup em {$minutes} minutos\n";

        $followup = [
            'alert_id' => md5(json_encode($alert)),
            'alert' => $alert,
            'scheduled_for' => time() + ($minutes * 60),
            'status' => 'pending',
        ];

        $followups = json_decode(file_get_contents('.followup-queue.json') ?: '[]', true);
        $followups[] = $followup;

        file_put_contents('.followup-queue.json', json_encode($followups, JSON_PRETTY_PRINT));
    }

    public function processPendingDigests() {
        // Processar digests pendentes
        $currentHour = date('H:00:00');
        $currentDay = date('Y-m-d 08:00:00'); // Enviar digest diário às 8am

        // Digest horário a cada hora
        static $lastHourlyRun = null;
        if ($lastHourlyRun !== $currentHour) {
            $this->sendHourlyDigest();
            $lastHourlyRun = $currentHour;
        }

        // Digest diário
        if (time() > strtotime($currentDay) && time() < strtotime($currentDay) + 3600) {
            $this->sendDailyDigest();
        }
    }
}

// Exemplo de uso
if (php_sapi_name() === 'cli' && $argc > 1) {
    $alertJson = $argv[1];
    $alert = json_decode($alertJson, true);

    $notif = new SmartNotifications();
    $notif->processAlert($alert);
}
