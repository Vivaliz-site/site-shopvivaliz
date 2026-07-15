<?php
/**
 * Painel de Auditoria - ShopVivaliz
 * Visualizar logs de segurança, acesso e mudanças
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

// Simular dados de auditoria (em produção, veria do BD)
$auditData = [
    'total_events_24h' => 1247,
    'total_access_logs' => 342,
    'failed_logins' => 3,
    'config_changes' => 12,
    'sensitive_events' => 8,
    'alerts' => [
        [
            'id' => 1,
            'timestamp' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'severity' => 'high',
            'type' => 'Unauthorized Domain DNS Change',
            'user_ip' => '168.220.83.228',
            'status' => 'investigating',
        ],
        [
            'id' => 2,
            'timestamp' => date('Y-m-d H:i:s', strtotime('-4 hours')),
            'severity' => 'medium',
            'type' => 'Multiple Failed Login Attempts',
            'user_ip' => '192.168.1.100',
            'status' => 'acknowledged',
        ],
        [
            'id' => 3,
            'timestamp' => date('Y-m-d H:i:s', strtotime('-6 hours')),
            'severity' => 'high',
            'type' => 'Sensitive Config File Modified',
            'user_ip' => 'git-auto-sync',
            'status' => 'resolved',
        ],
    ],
    'recent_changes' => [
        ['timestamp' => date('Y-m-d H:i:s'), 'type' => 'INSERT', 'table' => 'products', 'user' => 'Agente Autonomo', 'status' => 'success'],
        ['timestamp' => date('Y-m-d H:i:s', strtotime('-5 minutes')), 'type' => 'UPDATE', 'table' => 'orders', 'user' => 'system', 'status' => 'success'],
        ['timestamp' => date('Y-m-d H:i:s', strtotime('-15 minutes')), 'type' => 'UPDATE', 'table' => 'config_values', 'user' => 'admin', 'status' => 'success'],
    ]
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Auditoria - ShopVivaliz</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0;
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(30, 41, 59, 0.5);
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }

        h1 {
            font-size: 28px;
            color: #f1f5f9;
        }

        .header-info {
            font-size: 12px;
            color: #94a3b8;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 8px;
            padding: 20px;
            backdrop-filter: blur(10px);
        }

        .card-title {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .card-value {
            font-size: 32px;
            font-weight: bold;
            color: #f1f5f9;
        }

        .card-subtitle {
            font-size: 12px;
            color: #64748b;
            margin-top: 10px;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 10px;
        }

        .badge-high {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .badge-medium {
            background: rgba(251, 146, 60, 0.2);
            color: #fed7aa;
        }

        .badge-low {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 18px;
            color: #f1f5f9;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title::before {
            content: '';
            width: 4px;
            height: 20px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        thead {
            background: rgba(15, 23, 42, 0.8);
        }

        th {
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.05);
        }

        tr:hover {
            background: rgba(148, 163, 184, 0.05);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-success {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .status-failure {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .status-investigating {
            background: rgba(251, 146, 60, 0.2);
            color: #fed7aa;
        }

        .status-resolved {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .timestamp {
            font-size: 12px;
            color: #64748b;
            font-family: 'Courier New', monospace;
        }

        .alert-box {
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid #ef4444;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 10px;
        }

        .alert-box.warning {
            background: rgba(251, 146, 60, 0.1);
            border-left-color: #f97316;
        }

        .alert-box.info {
            background: rgba(59, 130, 246, 0.1);
            border-left-color: #3b82f6;
        }

        .alert-title {
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 4px;
        }

        .alert-text {
            font-size: 13px;
            color: #cbd5e1;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        button {
            padding: 8px 16px;
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }

        button:hover {
            background: rgba(59, 130, 246, 0.3);
            border-color: rgba(59, 130, 246, 0.5);
        }

        .refresh-time {
            text-align: right;
            font-size: 11px;
            color: #64748b;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(148, 163, 184, 0.1);
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }

            header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>🔐 Painel de Auditoria</h1>
                <div class="header-info">ShopVivaliz - Segurança e Conformidade</div>
            </div>
            <div class="header-info">
                Última atualização: <?= date('d/m/Y H:i:s') ?>
            </div>
        </header>

        <!-- Alertas Críticos -->
        <div class="alert-box">
            <div class="alert-title">🚨 ALERTA CRÍTICO: Acesso Não Autorizado Detectado</div>
            <div class="alert-text">
                Domínio shopvivaliz.com.br foi alterado para IP 168.220.83.228. Status: EM INVESTIGAÇÃO.
                <strong>Ação recomendada: Reverter DNS imediatamente.</strong>
            </div>
        </div>

        <!-- KPIs -->
        <div class="grid">
            <div class="card">
                <div class="card-title">Eventos (24h)</div>
                <div class="card-value"><?= number_format($auditData['total_events_24h']) ?></div>
                <div class="card-subtitle">Alterações registradas</div>
            </div>
            <div class="card">
                <div class="card-title">Acessos</div>
                <div class="card-value"><?= number_format($auditData['total_access_logs']) ?></div>
                <div class="card-subtitle">Tentativas de acesso</div>
            </div>
            <div class="card">
                <div class="card-title">Falhas de Autenticação</div>
                <div class="card-value"><?= $auditData['failed_logins'] ?></div>
                <div class="badge badge-high">Atenção</div>
            </div>
            <div class="card">
                <div class="card-title">Mudanças Sensíveis</div>
                <div class="card-value"><?= $auditData['sensitive_events'] ?></div>
                <div class="card-subtitle">Requerem revisão</div>
            </div>
        </div>

        <!-- Alertas de Segurança -->
        <div class="section">
            <div class="section-title">Alertas de Segurança</div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo de Alerta</th>
                        <th>Severidade</th>
                        <th>IP/Origem</th>
                        <th>Timestamp</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditData['alerts'] as $alert): ?>
                    <tr>
                        <td>#<?= $alert['id'] ?></td>
                        <td><?= htmlspecialchars($alert['type']) ?></td>
                        <td>
                            <span class="badge badge-<?= strtolower($alert['severity']) ?>">
                                <?= ucfirst($alert['severity']) ?>
                            </span>
                        </td>
                        <td class="timestamp"><?= htmlspecialchars($alert['user_ip']) ?></td>
                        <td class="timestamp"><?= $alert['timestamp'] ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower($alert['status']) ?>">
                                <?= ucfirst(str_replace('_', ' ', $alert['status'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mudanças Recentes -->
        <div class="section">
            <div class="section-title">Mudanças Recentes no Banco</div>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Tipo de Evento</th>
                        <th>Tabela</th>
                        <th>Usuário</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditData['recent_changes'] as $change): ?>
                    <tr>
                        <td class="timestamp"><?= $change['timestamp'] ?></td>
                        <td><code><?= $change['type'] ?></code></td>
                        <td><?= htmlspecialchars($change['table']) ?></td>
                        <td><?= htmlspecialchars($change['user']) ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower($change['status']) ?>">
                                <?= ucfirst($change['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="controls">
            <button>📥 Exportar Logs (CSV)</button>
            <button>🔄 Atualizar Agora</button>
            <button>⚙️ Configurações</button>
        </div>

        <div class="refresh-time">
            ✓ Auto-refresh a cada 60 segundos | Política de retenção: 365 dias
        </div>
    </div>

    <script>
        // Auto-refresh a cada 60 segundos
        setTimeout(() => {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>
