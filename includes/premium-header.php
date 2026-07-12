<?php
/**
 * Header Premium - Incluir em todas as páginas
 * <?php include __DIR__ . '/premium-header.php'; ?>
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/premium-ui-framework.css">
    <style>
        /* Estilos específicos da página */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1a1f35 100%);
            color: #f1f5f9;
        }

        .navbar-premium {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.95) 0%, rgba(15, 23, 42, 0.95) 100%);
            border-bottom: 2px solid #3b82f6;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .navbar-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.8em;
            font-weight: 700;
            background: linear-gradient(135deg, #3b82f6, #10b981);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-menu {
            display: flex;
            gap: 2rem;
            list-style: none;
            align-items: center;
        }

        .navbar-menu a {
            color: #f1f5f9;
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            position: relative;
        }

        .navbar-menu a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: #3b82f6;
            transition: width 0.3s ease;
        }

        .navbar-menu a:hover {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .navbar-menu a:hover::after {
            width: 100%;
        }

        .navbar-badge {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            padding: 0.25rem 0.6rem;
            border-radius: 50px;
            font-size: 0.8em;
            font-weight: 700;
            margin-left: -0.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        @media (max-width: 768px) {
            .navbar-menu {
                flex-direction: column;
                gap: 1rem;
                width: 100%;
            }

            .navbar-container {
                flex-direction: column;
                gap: 1rem;
            }
        }

        .main-content {
            min-height: calc(100vh - 100px);
            padding: 3rem 2rem;
        }
    </style>
</head>
<body>
    <!-- NAVBAR PREMIUM -->
    <nav class="navbar-premium">
        <div class="navbar-container">
            <a href="/" class="navbar-brand">
                <span>✓</span> Vivaliz
            </a>
            <ul class="navbar-menu">
                <li><a href="/">🏠 Home</a></li>
                <li><a href="/catalogo/">📦 Catálogo</a></li>
                <li><a href="/sobre/">ℹ️ Sobre</a></li>
                <li><a href="/contato/">📧 Contato</a></li>
                <li><a href="/carrinho/">🛒 Carrinho <span class="navbar-badge">2</span></a></li>
                <li><a class="btn btn-primary" href="/checkout/">💳 Comprar</a></li>
            </ul>
        </div>
    </nav>

    <!-- CONTEÚDO PRINCIPAL -->
    <main class="main-content">
