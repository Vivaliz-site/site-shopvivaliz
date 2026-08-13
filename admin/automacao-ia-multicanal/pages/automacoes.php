<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/admin-guard.php';
?>
<div class="automacoes-container">
    <section class="section" style="margin-bottom:18px">
        <h2>Rotinas canônicas</h2>
        <p style="margin:0;color:#64748b;line-height:1.65;max-width:920px">
            A listagem antiga desta página continha automações demonstrativas, números fixos e botões sem backend persistente. Ela foi substituída por atalhos para as rotinas reais do ShopVivaliz. Assim, nenhuma tela administrativa apresenta execução fictícia como se fosse estado de produção.
        </p>
    </section>

    <div class="kpi-row">
        <a class="kpi-card" href="/admin/catalog-optimization/admin_catalog.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">📝</div>
            <div class="kpi-info"><h3>Conteúdo multicanal</h3><p style="margin:0;color:#475569;line-height:1.5">Otimização assistida de cadastro com revisão e quality gate.</p></div>
        </a>
        <a class="kpi-card" href="/admin/ai-image-studio/admin_dashboard.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">🖼️</div>
            <div class="kpi-info"><h3>Geração de imagens</h3><p style="margin:0;color:#475569;line-height:1.5">Lotes rastreáveis usando a foto real como referência visual.</p></div>
        </a>
        <a class="kpi-card" href="/admin/orchestrator.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">🧠</div>
            <div class="kpi-info"><h3>Orchestrator</h3><p style="margin:0;color:#475569;line-height:1.5">Fila e coordenação das rotinas autônomas disponíveis.</p></div>
        </a>
        <a class="kpi-card" href="/admin/connections.php" style="text-decoration:none;color:inherit">
            <div class="kpi-icon">🔐</div>
            <div class="kpi-info"><h3>Conexões</h3><p style="margin:0;color:#475569;line-height:1.5">Estado de OAuth, credenciais e integrações externas.</p></div>
        </a>
    </div>

    <section class="section" style="margin-top:18px">
        <h2>Princípios operacionais</h2>
        <div class="activity-feed">
            <div class="activity-item success"><span class="activity-icon">1</span><div class="activity-content"><p><strong>Dados reais:</strong> status e contadores devem vir da fonte autoritativa da rotina, nunca de HTML estático.</p></div></div>
            <div class="activity-item success"><span class="activity-icon">2</span><div class="activity-content"><p><strong>Revisão explícita:</strong> geração de conteúdo ou imagem não equivale a publicação.</p></div></div>
            <div class="activity-item success"><span class="activity-icon">3</span><div class="activity-content"><p><strong>Falha fechada:</strong> se uma integração não puder ser comprovada, a interface deve mostrar o bloqueio em vez de assumir sucesso.</p></div></div>
        </div>
    </section>
</div>
