<?php
require_once __DIR__ . '/../../../includes/admin-guard.php';
/**
 * Dashboard - Visão Geral das Automações
 */
?>
<div class="dashboard-grid">
    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-icon">⚙️</div>
            <div class="kpi-info">
                <h3>Automações Ativas</h3>
                <p class="kpi-value">5</p>
                <small>3 rodando agora</small>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">📦</div>
            <div class="kpi-info">
                <h3>Produtos Processados</h3>
                <p class="kpi-value">247</p>
                <small>Hoje: 42</small>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">✅</div>
            <div class="kpi-info">
                <h3>Taxa de Sucesso</h3>
                <p class="kpi-value">94.3%</p>
                <small>15 falharam</small>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">🎯</div>
            <div class="kpi-info">
                <h3>Canais Conectados</h3>
                <p class="kpi-value">4</p>
                <small>Make, APIs ativas</small>
            </div>
        </div>
    </div>

    <!-- Automações em Execução -->
    <div class="section">
        <h2>Automações em Execução</h2>
        <div class="automation-list">
            <div class="automation-card running">
                <div class="automation-header">
                    <h3>TikTok - Descrições Otimizadas</h3>
                    <span class="status-badge running">🟢 Executando</span>
                </div>
                <div class="automation-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 65%"></div>
                    </div>
                    <p>65% (26/40 produtos)</p>
                </div>
                <div class="automation-details">
                    <small>Última execução: há 3 minutos</small>
                    <small>Tempo estimado: 8 minutos</small>
                </div>
            </div>

            <div class="automation-card running">
                <div class="automation-header">
                    <h3>Amazon - Otimização SEO</h3>
                    <span class="status-badge running">🟢 Executando</span>
                </div>
                <div class="automation-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 42%"></div>
                    </div>
                    <p>42% (21/50 produtos)</p>
                </div>
                <div class="automation-details">
                    <small>Última execução: há 5 minutos</small>
                    <small>Tempo estimado: 12 minutos</small>
                </div>
            </div>

            <div class="automation-card">
                <div class="automation-header">
                    <h3>Mercado Livre - Descrições Padrão</h3>
                    <span class="status-badge">🟡 Agendada</span>
                </div>
                <div class="automation-details">
                    <small>Próxima execução: em 45 minutos</small>
                    <small>Frequência: a cada 2 horas</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Últimas Atividades -->
    <div class="section">
        <h2>Últimas Atividades</h2>
        <div class="activity-feed">
            <div class="activity-item success">
                <span class="activity-icon">✅</span>
                <div class="activity-content">
                    <p><strong>Produto processado:</strong> "Rodízio 35mm Giratório"</p>
                    <small>TikTok + Amazon - há 2 minutos</small>
                </div>
            </div>

            <div class="activity-item success">
                <span class="activity-icon">✅</span>
                <div class="activity-content">
                    <p><strong>Lote concluído:</strong> 10 produtos enviados para Mercado Livre</p>
                    <small>há 15 minutos</small>
                </div>
            </div>

            <div class="activity-item warning">
                <span class="activity-icon">⚠️</span>
                <div class="activity-content">
                    <p><strong>Falha ao processar:</strong> Produto SKU #12845 - Imagem não encontrada</p>
                    <small>há 23 minutos</small>
                </div>
            </div>

            <div class="activity-item success">
                <span class="activity-icon">✅</span>
                <div class="activity-content">
                    <p><strong>Automação iniciada:</strong> "TikTok - Descrições Otimizadas"</p>
                    <small>há 1 hora</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos de Desempenho -->
    <div class="section">
        <h2>Desempenho por Canal</h2>
        <div class="chart-container">
            <canvas id="channelChart"></canvas>
        </div>
    </div>
</div>

<script>
// Gráfico de desempenho
const ctx = document.getElementById('channelChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['TikTok', 'Amazon', 'Mercado Livre', 'Shopify'],
        datasets: [{
            label: 'Produtos Processados',
            data: [45, 38, 52, 22],
            backgroundColor: '#173B63'
        },
        {
            label: 'Taxa de Sucesso (%)',
            data: [96, 92, 89, 100],
            backgroundColor: '#059669'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' }
        }
    }
});
</script>
