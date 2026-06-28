<?php
/**
 * ShopVivaliz - Ecommerce Autônomo com Agentes IA
 * Homepage Principal
 */

// Inicializar
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Cabeçalhos de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=UTF-8');

// Versão da aplicação
define('APP_VERSION', '9.2.85');
define('APP_NAME', 'ShopVivaliz');
define('BASE_URL', 'https://dev.shopvivaliz.com.br');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ShopVivaliz - Ecommerce inteligente com agentes IA autônomos operando 24/7">
    <meta name="theme-color" content="#1a73e8">

    <title><?php echo APP_NAME; ?> - Ecommerce Inteligente com IA</title>

    <link rel="stylesheet" href="/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* WELCOME MODAL */
        .welcome-modal {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        }

        .welcome-modal.hidden {
            display: none;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .welcome-content {
            background: white;
            border-radius: 16px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #666;
            z-index: 10001;
        }

        .welcome-close:hover {
            color: #000;
        }

        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            border-radius: 16px 16px 0 0;
        }

        .welcome-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .welcome-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }

        .welcome-body {
            padding: 30px;
            color: #333;
        }

        .welcome-body p {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .agents-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .agent-card {
            background: #f5f7fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border: 2px solid #e0e7ff;
        }

        .agent-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .agent-card h3 {
            font-size: 16px;
            margin-bottom: 4px;
            color: #667eea;
        }

        .agent-card p {
            font-size: 12px;
            color: #666;
            margin: 0;
        }

        .welcome-features {
            list-style: none;
            padding: 0;
        }

        .welcome-features li {
            font-size: 14px;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .welcome-features li:last-child {
            border-bottom: none;
        }

        .welcome-features a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .welcome-features a:hover {
            text-decoration: underline;
        }

        .welcome-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .stat {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #667eea;
        }

        .stat-number {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            display: block;
            font-size: 12px;
            color: #666;
        }

        .welcome-footer {
            padding: 20px 30px;
            background: #f9fafb;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
            justify-content: center;
            border-radius: 0 0 16px 16px;
        }

        .btn-primary, .btn-secondary {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e0e7ff;
            color: #667eea;
        }

        .btn-secondary:hover {
            background: #c5ceff;
        }

        @media (max-width: 600px) {
            .welcome-content {
                width: 95%;
                max-height: 95vh;
            }

            .agents-grid {
                grid-template-columns: 1fr;
            }

            .welcome-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .welcome-header h1 {
                font-size: 22px;
            }
        }
    </style>

    <script>
        // WELCOME MODAL
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('welcomeModal');
            const hasVisited = localStorage.getItem('shopvivaliz_visited');

            if (!hasVisited) {
                modal.style.display = 'flex';
                localStorage.setItem('shopvivaliz_visited', 'true');
            }
        });

        function closeWelcome() {
            document.getElementById('welcomeModal').classList.add('hidden');
        }

        function goToMonitor() {
            window.location.href = '/admin/monitor/';
        }

        // Fechar modal ao clicar fora
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('welcomeModal');
            if (event.target === modal) {
                closeWelcome();
            }
        });

        // Carregar dados do monitor
        async function loadWelcomeStats() {
            try {
                const response = await fetch('/api/monitor/tasks-api.php?action=summary');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('welcome-total').textContent = data.total;
                    document.getElementById('welcome-completed').textContent = data.completed;
                    document.getElementById('welcome-pending').textContent = data.pending;
                    document.getElementById('welcome-progress').textContent = Math.round(data.percentage) + '%';
                }
            } catch (error) {
                console.error('Erro ao carregar stats:', error);
            }
        }

        // Carregar stats quando modal aparecer
        window.addEventListener('load', loadWelcomeStats);
    </script>
</head>
<body>
    <!-- MODAL DE BOAS-VINDAS -->
    <div id="welcomeModal" class="welcome-modal">
        <div class="welcome-content">
            <button class="welcome-close" onclick="closeWelcome()">&times;</button>
            <div class="welcome-header">
                <h1>Bem-vindo ao ShopVivaliz!</h1>
                <p class="welcome-subtitle">Ecommerce Inteligente com Agentes IA 24/7</p>
            </div>
            <div class="welcome-body">
                <p>Olá! Você está em um ecommerce revolucionário operado por <strong>3 Agentes IA</strong> autônomos:</p>

                <div class="agents-grid">
                    <div class="agent-card">
                        <div class="agent-icon">🧠</div>
                        <h3>Gemini</h3>
                        <p>Arquitetura & Design</p>
                    </div>
                    <div class="agent-card">
                        <div class="agent-icon">⚡</div>
                        <h3>Claude</h3>
                        <p>Implementação & Backend</p>
                    </div>
                    <div class="agent-card">
                        <div class="agent-icon">✓</div>
                        <h3>ChatGPT</h3>
                        <p>Validação & QA</p>
                    </div>
                </div>

                <h2 style="margin-top: 25px; margin-bottom: 10px;">O que você pode fazer?</h2>
                <ul class="welcome-features">
                    <li><strong>Visualizar tarefas:</strong> <a href="/admin/monitor/">Acesse o Monitor</a> para ver todas as 41 tarefas e seus status</li>
                    <li><strong>Conversar com agentes:</strong> Chat em tempo real com os agentes IA</li>
                    <li><strong>Ver logs:</strong> Acompanhe detalhes de cada tarefa executada</li>
                    <li><strong>Monitoramento 24/7:</strong> Sistema funcionando continuamente</li>
                </ul>

                <h2 style="margin-top: 25px; margin-bottom: 10px;">Status Atual</h2>
                <div class="welcome-stats">
                    <div class="stat">
                        <span class="stat-number" id="welcome-total">41</span>
                        <span class="stat-label">Total de Tarefas</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number" id="welcome-completed">19</span>
                        <span class="stat-label">Completadas</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number" id="welcome-pending">22</span>
                        <span class="stat-label">Pendentes</span>
                    </div>
                    <div class="stat">
                        <span class="stat-number" id="welcome-progress">46%</span>
                        <span class="stat-label">Progresso</span>
                    </div>
                </div>
            </div>
            <div class="welcome-footer">
                <button class="btn-primary" onclick="goToMonitor()">Ir para Monitor</button>
                <button class="btn-secondary" onclick="closeWelcome()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- Navegação -->
    <nav class="navbar">
        <div class="container">
            <div class="navbar-brand">
                <h1><?php echo APP_NAME; ?></h1>
                <span class="version">v<?php echo APP_VERSION; ?></span>
            </div>
            <div class="navbar-menu">
                <a href="/">Home</a>
                <a href="/catalogo">Catálogo</a>
                <a href="/sobre">Sobre</a>
                <a href="/admin/monitor/">Monitor</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h1>Ecommerce Inteligente com Agentes IA</h1>
                <p>Sistema autônomo operando 24/7 com Gemini, Claude e ChatGPT</p>

                <div class="cta-buttons">
                    <a href="/catalogo" class="btn btn-primary">Ver Catálogo</a>
                    <a href="/admin/monitor/" class="btn btn-secondary">Acessar Monitor</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Status dos Agentes -->
    <section class="agents-status">
        <div class="container">
            <h2>Status dos Agentes</h2>

            <div class="agents-grid">
                <div class="agent-card">
                    <h3>Gemini</h3>
                    <p class="role">Arquitetura</p>
                    <div class="status online">Ativo</div>
                    <p>Analisando requisitos e desenha arquitetura das soluções</p>
                </div>

                <div class="agent-card">
                    <h3>Claude</h3>
                    <p class="role">Implementação</p>
                    <div class="status online">Ativo</div>
                    <p>Desenvolve código PHP/JavaScript e otimiza performance</p>
                </div>

                <div class="agent-card">
                    <h3>ChatGPT</h3>
                    <p class="role">Validação</p>
                    <div class="status online">Ativo</div>
                    <p>Valida qualidade, executa testes e garante conformidade</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Estatísticas -->
    <section class="stats">
        <div class="container">
            <div class="stat-item">
                <div class="stat-number" id="tasks-completed">0</div>
                <div class="stat-label">Tarefas Completadas</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="uptime">99.9%</div>
                <div class="stat-label">Uptime</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="response-time">42ms</div>
                <div class="stat-label">Tempo de Resposta</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="products-count">0</div>
                <div class="stat-label">Produtos em Catálogo</div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Sistema autônomo de desenvolvimento com IA.</p>
            <p>Versão <?php echo APP_VERSION; ?> | <a href="/admin/monitor/">Admin</a></p>
        </div>
    </footer>

    <!-- Scripts -->
    <script>
        // Carregar dados em tempo real da API Monitor
        document.addEventListener('DOMContentLoaded', function() {
            fetch('/api/monitor/api.php?action=status')
                .then(r => r.json())
                .then(data => {
                    if (data.queue) {
                        document.getElementById('tasks-completed').textContent = data.queue.completed || '0';
                        document.getElementById('uptime').textContent = (data.queue.completion_rate || 0) + '%';
                    }
                })
                .catch(e => console.log('Monitor desconectado (esperado em desenvolvimento)'));
        });
    </script>
</body>
</html>
