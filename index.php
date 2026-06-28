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
</head>
<body>
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
