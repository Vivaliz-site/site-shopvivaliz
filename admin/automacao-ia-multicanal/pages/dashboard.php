<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/admin-guard.php';
?>
<div class="dashboard-grid">
    <section class="section" style="background:linear-gradient(135deg,#f8fbff,#f4fbf7)">
        <h2 style="margin-bottom:8px">Cockpit operacional</h2>
        <p style="margin:0;color:#64748b;line-height:1.65;max-width:900px">
            Esta central não fabrica contadores, execuções ou taxas de sucesso. O estado real fica nos módulos canônicos abaixo, cada um com seus próprios logs, gates e evidências. Use esta tela para navegar e validar saúde antes de operar.
        </p>
    </section>

    <div class="kpi-row" aria-label="Atalhos operacionais">
        <a class="kpi-card" href="/admin/catalog-optimization/admin_catalog.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">📝</div>
            <div class="kpi-info"><h3>Otimização de cadastro</h3><p style="margin:0;color:#475569;line-height:1.5">Título, descrição e SEO por canal com quality gate.</p></div>
        </a>
        <a class="kpi-card" href="/admin/ai-image-studio/admin_dashboard.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">🖼️</div>
            <div class="kpi-info"><h3>AI Image Studio</h3><p style="margin:0;color:#475569;line-height:1.5">Gerar imagens a partir da foto real e acompanhar os lotes.</p></div>
        </a>
        <a class="kpi-card" href="/admin/ai-image-studio/admin_validate.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">✅</div>
            <div class="kpi-info"><h3>Revisão de imagens</h3><p style="margin:0;color:#475569;line-height:1.5">Comparar origem, validar fidelidade e decidir publicação.</p></div>
        </a>
        <a class="kpi-card" href="/admin/ai-provider-audit.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">🧪</div>
            <div class="kpi-info"><h3>Saúde dos provedores</h3><p style="margin:0;color:#475569;line-height:1.5">Falhas, cooldown e disponibilidade das integrações de IA.</p></div>
        </a>
    </div>

    <section class="section">
        <h2>Integrações e segurança operacional</h2>
        <div class="kpi-row">
            <a class="kpi-card" href="/admin/connections.php" style="text-decoration:none;color:inherit">
                <div class="kpi-icon">🔐</div><div class="kpi-info"><h3>Conexões e OAuth</h3><p style="margin:0;color:#475569">Verifique credenciais e integrações sem expor valores.</p></div>
            </a>
            <a class="kpi-card" href="/admin/audit-dashboard.php" style="text-decoration:none;color:inherit">
                <div class="kpi-icon">📋</div><div class="kpi-info"><h3>Auditoria</h3><p style="margin:0;color:#475569">Consulte evidências e falhas registradas pelo ambiente.</p></div>
            </a>
            <a class="kpi-card" href="/admin/monitor/" style="text-decoration:none;color:inherit">
                <div class="kpi-icon">📡</div><div class="kpi-info"><h3>Monitor principal</h3><p style="margin:0;color:#475569">Estado geral dos serviços e rotinas administrativas.</p></div>
            </a>
            <a class="kpi-card" href="/admin/orchestrator.php" style="text-decoration:none;color:inherit">
                <div class="kpi-icon">🧠</div><div class="kpi-info"><h3>Orchestrator</h3><p style="margin:0;color:#475569">Coordene tarefas reais; nenhuma execução é simulada aqui.</p></div>
            </a>
        </div>
    </section>

    <section class="section">
        <h2>Regra de confiança</h2>
        <div class="activity-feed">
            <div class="activity-item success"><span class="activity-icon">✓</span><div class="activity-content"><p><strong>Sem métricas fictícias:</strong> só mostramos estado obtido do runtime ou links para a fonte autoritativa.</p></div></div>
            <div class="activity-item success"><span class="activity-icon">✓</span><div class="activity-content"><p><strong>Sem publicação implícita:</strong> imagem, conteúdo, preço e estoque continuam sujeitos aos gates do módulo responsável.</p></div></div>
            <div class="activity-item success"><span class="activity-icon">✓</span><div class="activity-content"><p><strong>Falha visível:</strong> o botão “Testar saúde” no topo consulta o health endpoint e informa erro em vez de simular conexão.</p></div></div>
        </div>
    </section>
</div>
