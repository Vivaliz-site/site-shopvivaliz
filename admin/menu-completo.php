<?php
/**
 * Menu Completo de Admin - Todas as rotinas em um só lugar
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Admin Completo - ShopVivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto; }
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1.5rem; color: white; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .navbar-content { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .navbar-title { font-size: 1.5rem; font-weight: bold; }
        .logout-btn { background: rgba(255,255,255,0.2); border: 2px solid white; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; cursor: pointer; }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 1rem; }
        .page-title { font-size: 2.5rem; margin-bottom: 0.5rem; color: #333; }
        .subtitle { color: #666; margin-bottom: 2rem; }
        .section { background: white; border-radius: 12px; margin-bottom: 2rem; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .section-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; font-size: 1.2rem; font-weight: bold; }
        .section-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; padding: 1.5rem; }
        .menu-item { display: block; padding: 1.5rem; background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 8px; text-decoration: none; color: #333; transition: all 0.3s; }
        .menu-item:hover { background: white; border-color: #667eea; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2); transform: translateY(-2px); }
        .menu-item-title { font-weight: 600; font-size: 1rem; margin-bottom: 0.5rem; }
        .menu-item-desc { font-size: 0.85rem; color: #666; }
        .badge { display: inline-block; background: #667eea; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="navbar-content">
            <div class="navbar-title">🛍️ ShopVivaliz Admin - Menu Completo</div>
            <a href="/auth/logout.php" class="logout-btn">🚪 Sair</a>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">Painel Administrativo</h1>
        <p class="subtitle">Acesse todas as rotinas operacionais do sistema</p>

        <!-- LOJA PÚBLICA -->
        <div class="section">
            <div class="section-header">🏪 Loja Pública</div>
            <div class="section-grid">
                <a href="/" class="menu-item">
                    <div class="menu-item-title">Home</div>
                    <div class="menu-item-desc">Página inicial da loja</div>
                </a>
                <a href="/catalogo.php" class="menu-item">
                    <div class="menu-item-title">Catálogo</div>
                    <div class="menu-item-desc">Visualizar produtos</div>
                </a>
                <a href="/checkout" class="menu-item">
                    <div class="menu-item-title">Checkout</div>
                    <div class="menu-item-desc">Testar fluxo de compra</div>
                </a>
                <a href="/carrinho" class="menu-item">
                    <div class="menu-item-title">Carrinho</div>
                    <div class="menu-item-desc">Verificar carrinho</div>
                </a>
            </div>
        </div>

        <!-- GESTÃO OPERACIONAL -->
        <div class="section">
            <div class="section-header">📊 Gestão Operacional</div>
            <div class="section-grid">
                <a href="/admin/produtos.php" class="menu-item">
                    <div class="menu-item-title">Produtos</div>
                    <div class="menu-item-desc">Criar, editar, deletar produtos</div>
                </a>
                <a href="/admin/pedidos.php" class="menu-item">
                    <div class="menu-item-title">Pedidos</div>
                    <div class="menu-item-desc">Gerenciar pedidos do sistema</div>
                </a>
                <a href="/admin/clientes.php" class="menu-item">
                    <div class="menu-item-title">Clientes</div>
                    <div class="menu-item-desc">Base de clientes</div>
                </a>
                <a href="/admin/cupons.php" class="menu-item">
                    <div class="menu-item-title">Cupons</div>
                    <div class="menu-item-desc">Cadastrar e gerenciar cupons</div>
                </a>
                <a href="/admin/company-profile.php" class="menu-item">
                    <div class="menu-item-title">Perfil Empresa</div>
                    <div class="menu-item-desc">Dados da empresa</div>
                </a>
            </div>
        </div>

        <!-- INTEGRAÇÕES -->
        <div class="section">
            <div class="section-header">🔗 Integrações ERP</div>
            <div class="section-grid">
                <a href="/olist/sync-products.php" class="menu-item">
                    <div class="menu-item-title">Sync Olist</div>
                    <div class="menu-item-desc">Sincronizar produtos Olist/Tiny</div>
                </a>
                <a href="/admin/sync-olist-para-products.php" class="menu-item">
                    <div class="menu-item-title">Sync Avançado</div>
                    <div class="menu-item-desc">Migração de dados Olist</div>
                </a>
                <a href="/admin/olist-images-audit.php" class="menu-item">
                    <div class="menu-item-title">Auditoria Imagens</div>
                    <div class="menu-item-desc">Verificar imagens do Olist</div>
                </a>
                <a href="/admin/reparar-catalogo-olist.php" class="menu-item">
                    <div class="menu-item-title">Reparar Catálogo</div>
                    <div class="menu-item-desc">Corrigir inconsistências</div>
                </a>
                <a href="/admin/integrations.php" class="menu-item">
                    <div class="menu-item-title">Config Integrações</div>
                    <div class="menu-item-desc">Configurar conexões ERP</div>
                </a>
            </div>
        </div>

        <!-- MARKETPLACE -->
        <div class="section">
            <div class="section-header">📱 Marketplaces</div>
            <div class="section-grid">
                <a href="/admin/mercadolivre.php" class="menu-item">
                    <div class="menu-item-title">Mercado Livre</div>
                    <div class="menu-item-desc">Gerenciar integração ML</div>
                </a>
            </div>
        </div>

        <!-- MONITORAMENTO -->
        <div class="section">
            <div class="section-header">📊 Monitoramento & Análise</div>
            <div class="section-grid">
                <a href="/admin/monitor/" class="menu-item">
                    <div class="menu-item-title">Monitor Principal</div>
                    <div class="menu-item-desc">Dashboard de saúde do site</div>
                </a>
                <a href="/admin/monitor-completo.php" class="menu-item">
                    <div class="menu-item-title">Monitor Completo</div>
                    <div class="menu-item-desc">Análise detalhada de métricas</div>
                </a>
                <a href="/admin/audit-dashboard.php" class="menu-item">
                    <div class="menu-item-title">Audit Dashboard</div>
                    <div class="menu-item-desc">Auditoria de operações</div>
                </a>
                <a href="/admin/sla-dashboard.php" class="menu-item">
                    <div class="menu-item-title">SLA Dashboard</div>
                    <div class="menu-item-desc">Indicadores de performance</div>
                </a>
                <a href="/admin/agents-monitor.php" class="menu-item">
                    <div class="menu-item-title">Monitor Agentes</div>
                    <div class="menu-item-desc">Status de agentes autônomos</div>
                </a>
            </div>
        </div>

        <!-- AUTOMAÇÃO -->
        <div class="section">
            <div class="section-header">🤖 Automação & IA</div>
            <div class="section-grid">
                <a href="/admin/automacao-ia-multicanal/" class="menu-item">
                    <div class="menu-item-title">Automação IA</div>
                    <div class="menu-item-desc">Gerenciar automações multicanal</div>
                </a>
                <a href="/admin/orchestrator.php" class="menu-item">
                    <div class="menu-item-title">Orchestrator</div>
                    <div class="menu-item-desc">Orquestração de workflows</div>
                </a>
                <a href="/admin/squad-chat.php" class="menu-item">
                    <div class="menu-item-title">Squad Chat</div>
                    <div class="menu-item-desc">Chat com agentes IA</div>
                </a>
            </div>
        </div>

        <!-- DIAGNÓSTICO & MANUTENÇÃO -->
        <div class="section">
            <div class="section-header">🔧 Diagnóstico & Manutenção</div>
            <div class="section-grid">
                <a href="/admin/diagnostico-banco.php" class="menu-item">
                    <div class="menu-item-title">Diag Banco Dados</div>
                    <div class="menu-item-desc">Verificar banco de dados</div>
                </a>
                <a href="/admin/teste-banco.php" class="menu-item">
                    <div class="menu-item-title">Teste Banco</div>
                    <div class="menu-item-desc">Testar conexão BD</div>
                </a>
                <a href="/api/health.php" class="menu-item">
                    <div class="menu-item-title">Health Check</div>
                    <div class="menu-item-desc">Status da API</div>
                    <span class="badge">JSON</span>
                </a>
                <a href="/admin/force-git-pull.php" class="menu-item">
                    <div class="menu-item-title">Force Git Pull</div>
                    <div class="menu-item-desc">Forçar sincronização</div>
                </a>
            </div>
        </div>

        <!-- FERRAMENTAS AVANÇADAS -->
        <div class="section">
            <div class="section-header">⚡ Ferramentas Avançadas</div>
            <div class="section-grid">
                <a href="/admin/visual-editor.php" class="menu-item">
                    <div class="menu-item-title">Visual Editor</div>
                    <div class="menu-item-desc">Editar conteúdo visual</div>
                </a>
                <a href="/api/catalog/products.php?limit=200" class="menu-item">
                    <div class="menu-item-title">Produtos JSON</div>
                    <div class="menu-item-desc">Ver API de produtos</div>
                    <span class="badge">JSON</span>
                </a>
                <a href="/api/ml/products" class="menu-item">
                    <div class="menu-item-title">ML Produtos JSON</div>
                    <div class="menu-item-desc">Produtos Mercado Livre</div>
                    <span class="badge">JSON</span>
                </a>
            </div>
        </div>

        <!-- LEGADO -->
        <div class="section">
            <div class="section-header">🗂️ Legado & Compatibilidade</div>
            <div class="section-grid">
                <a href="/admin/admin-back.php" class="menu-item">
                    <div class="menu-item-title">Admin Back</div>
                    <div class="menu-item-desc">Agrupa painéis antigos e rotas de suporte</div>
                </a>
            </div>
        </div>

        <div style="text-align: center; padding: 2rem; color: #666;">
            <p>✅ Total: <strong>27 rotinas</strong> disponíveis</p>
        </div>
    </div>
</body>
</html>
