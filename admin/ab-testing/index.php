<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/admin-helpers.php';

// Obter páginas ativas
$pages = AdminHelpers::getActivePagesWithTests();
$selectedPage = $_GET['page'] ?? ($pages[0]['page_id'] ?? 'homepage');

// Obter variantes da página selecionada
$variants = AdminHelpers::getPageVariants($selectedPage);
$winner = AdminHelpers::getWinnerVariant($selectedPage);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A/B Testing Dashboard - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin-charts.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">ShopVivaliz Admin</a>
            <div class="navbar-menu">
                <a href="/">Loja</a>
                <a href="/admin/">Dashboard</a>
                <a href="/admin/ab-testing/">A/B Testing</a>
                <a href="/admin/monitor/">Monitor</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="container dashboard-header">
            <div>
                <h1 class="dashboard-title">A/B Testing Dashboard</h1>
                <p class="dashboard-subtitle">Analise variantes de páginas, CTR, conversões e receita em tempo real</p>
            </div>
        </section>

        <section class="container filter-bar">
            <div class="filter-group">
                <label for="page-select">Página</label>
                <select id="page-select">
                    <?php foreach ($pages as $page): ?>
                        <option value="<?= htmlspecialchars($page['page_id'], ENT_QUOTES) ?>"
                                <?= $page['page_id'] === $selectedPage ? 'selected' : '' ?>>
                            <?= htmlspecialchars($page['page_id'], ENT_QUOTES) ?>
                            (<?= (int)$page['variant_count'] ?> variantes)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="days-select">Período</label>
                <select id="days-select">
                    <option value="1">Último dia</option>
                    <option value="7" selected>Últimos 7 dias</option>
                    <option value="30">Últimos 30 dias</option>
                    <option value="90">Últimos 90 dias</option>
                </select>
            </div>

            <div style="margin-top: 32px; display: flex; gap: 8px;">
                <button class="btn btn-secondary" id="btn-export">Exportar CSV</button>
                <button class="btn btn-secondary" id="btn-refresh">Atualizar</button>
            </div>
        </section>

        <?php if (empty($variants)): ?>
            <section class="container empty-state">
                <div class="empty-state-icon">📊</div>
                <h2 class="empty-state-title">Nenhuma variante encontrada</h2>
                <p class="empty-state-message">
                    Comece a criar variantes de página para começar a rastrear performance.
                </p>
            </section>
        <?php else: ?>

        <section class="container metrics-grid" id="metrics-container">
            <!-- Métricas serão preenchidas via JavaScript -->
        </section>

        <?php if ($winner): ?>
            <section class="container" style="margin: 24px 0;">
                <div class="winner-badge">
                    Variante vencedora: <strong><?= htmlspecialchars($winner['variant_name'], ENT_QUOTES) ?></strong>
                    (<?= (float)$winner['conversion_rate'] ?>% conversão)
                </div>
            </section>
        <?php endif; ?>

        <section class="container charts-section">
            <h2 class="section-heading">📊 Comparação de Variantes</h2>
            <div class="chart-container large">
                <div class="chart-canvas large">
                    <canvas id="chart-ctr"></canvas>
                </div>
                <div class="chart-legend">
                    <div class="legend-item">
                        <div class="legend-box" style="background: #173B63;"></div>
                        Variante 1
                    </div>
                    <div class="legend-item">
                        <div class="legend-box" style="background: #059669;"></div>
                        Variante 2
                    </div>
                </div>
            </div>
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">💰 Receita por Variante</h2>
            <div class="chart-container large">
                <div class="chart-canvas large">
                    <canvas id="chart-revenue"></canvas>
                </div>
            </div>
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">📈 Histórico de Eventos</h2>
            <div class="chart-container large">
                <div class="chart-canvas large">
                    <canvas id="chart-events"></canvas>
                </div>
            </div>
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">📋 Variantes Detalhadas</h2>
            <div class="chart-container">
                <table class="variants-table" id="variants-table">
                    <thead>
                        <tr>
                            <th>Variante</th>
                            <th>Tipo</th>
                            <th>Impressões</th>
                            <th>Cliques</th>
                            <th>CTR %</th>
                            <th>Conversões</th>
                            <th>Conv. %</th>
                            <th>Receita (R$)</th>
                            <th>AOV (R$)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="variants-tbody">
                        <!-- Preenchido via JavaScript -->
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>
    </main>

    <script>
        // Estado global
        const state = {
            charts: {},
            variants: [],
            winner: null,
            selectedPage: '<?= htmlspecialchars($selectedPage, ENT_QUOTES) ?>',
            days: 7
        };

        // Inicializar ao carregar
        document.addEventListener('DOMContentLoaded', () => {
            loadData();
            setupEventListeners();
        });

        // Setup de listeners
        function setupEventListeners() {
            document.getElementById('page-select').addEventListener('change', (e) => {
                state.selectedPage = e.target.value;
                window.location.href = '?page=' + state.selectedPage;
            });

            document.getElementById('days-select').addEventListener('change', (e) => {
                state.days = parseInt(e.target.value);
                loadEventHistory();
            });

            document.getElementById('btn-export').addEventListener('click', exportToCSV);
            document.getElementById('btn-refresh').addEventListener('click', loadData);
        }

        // Carregar todos os dados
        async function loadData() {
            try {
                // Mostrar loading
                document.getElementById('metrics-container').innerHTML = '<p>Carregando dados...</p>';

                const response = await fetch('/api/admin/ab-testing-data.php?action=variants&page_id=' + state.selectedPage);
                const data = await response.json();

                if (!data.ok) {
                    throw new Error(data.error || 'Erro ao carregar dados');
                }

                state.variants = data.data;
                state.winner = data.winner;

                renderMetrics();
                renderChartsData(data.chartData);
                renderVariantsTable();
                loadEventHistory();

            } catch (error) {
                console.error('Erro:', error);
                document.getElementById('metrics-container').innerHTML = `<p style="color: red;">Erro ao carregar dados: ${error.message}</p>`;
            }
        }

        // Renderizar cards de métricas
        function renderMetrics() {
            const container = document.getElementById('metrics-container');
            let html = '';

            if (state.variants.length === 0) {
                container.innerHTML = '<p>Nenhuma variante encontrada.</p>';
                return;
            }

            // Totais
            const totals = state.variants.reduce((acc, v) => ({
                impressions: acc.impressions + parseInt(v.impressions || 0),
                clicks: acc.clicks + parseInt(v.clicks || 0),
                conversions: acc.conversions + parseInt(v.conversions || 0),
                revenue: acc.revenue + parseFloat(v.revenue || 0)
            }), { impressions: 0, clicks: 0, conversions: 0, revenue: 0 });

            const avgCtr = totals.impressions > 0 ? (totals.clicks * 100 / totals.impressions).toFixed(2) : '0.00';
            const avgConv = totals.clicks > 0 ? (totals.conversions * 100 / totals.clicks).toFixed(2) : '0.00';

            html += createMetricCard('Total de Impressões', totals.impressions.toLocaleString('pt-BR'), 'highlight');
            html += createMetricCard('Total de Cliques', totals.clicks.toLocaleString('pt-BR'));
            html += createMetricCard('CTR Médio', avgCtr + '%');
            html += createMetricCard('Taxa de Conversão', avgConv + '%', 'highlight');
            html += createMetricCard('Receita Total', 'R$ ' + totals.revenue.toLocaleString('pt-BR', { minimumFractionDigits: 2 })), 'highlight';
            html += createMetricCard('Conversões Totais', totals.conversions.toLocaleString('pt-BR'));

            container.innerHTML = html;
        }

        // Criar card de métrica
        function createMetricCard(label, value, highlight = '') {
            const highlightClass = highlight === 'highlight' ? ' highlighted' : '';
            return `
                <div class="metric-card${highlightClass}">
                    <div class="metric-label">${label}</div>
                    <div class="metric-value">${value}</div>
                </div>
            `;
        }

        // Renderizar gráficos
        function renderChartsData(chartData) {
            destroyCharts();

            // Gráfico CTR
            const ctxCtr = document.getElementById('chart-ctr').getContext('2d');
            state.charts.ctr = new Chart(ctxCtr, {
                type: 'bar',
                data: chartData.ctr,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'CTR (%) por Variante' }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100 }
                    }
                }
            });

            // Gráfico Receita
            const ctxRevenue = document.getElementById('chart-revenue').getContext('2d');
            state.charts.revenue = new Chart(ctxRevenue, {
                type: 'bar',
                data: chartData.revenue,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'Receita (R$) por Variante' }
                    },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        // Carregar histórico de eventos
        async function loadEventHistory() {
            try {
                const response = await fetch(`/api/admin/ab-testing-data.php?action=events&page_id=${state.selectedPage}&days=${state.days}`);
                const data = await response.json();

                if (!data.ok) {
                    throw new Error(data.error || 'Erro ao carregar eventos');
                }

                renderEventChart(data.chartData);

            } catch (error) {
                console.error('Erro ao carregar eventos:', error);
            }
        }

        // Renderizar gráfico de eventos
        function renderEventChart(chartData) {
            if (state.charts.events) {
                state.charts.events.destroy();
            }

            const ctxEvents = document.getElementById('chart-events').getContext('2d');
            state.charts.events = new Chart(ctxEvents, {
                type: 'line',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true },
                        title: { display: true, text: 'Histórico de Eventos' }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Renderizar tabela de variantes
        function renderVariantsTable() {
            const tbody = document.getElementById('variants-tbody');
            let html = '';

            state.variants.forEach(v => {
                const isBest = state.winner && state.winner.id === v.id ? ' metric-positive' : '';
                const badge = v.variant_type === 'control' ? 'badge-control' : 'badge-treatment';

                html += `
                    <tr>
                        <td class="variant-name">${v.variant_name}</td>
                        <td><span class="badge ${badge}">${v.variant_type.toUpperCase()}</span></td>
                        <td>${v.impressions.toLocaleString('pt-BR')}</td>
                        <td>${v.clicks.toLocaleString('pt-BR')}</td>
                        <td class="${isBest}">${v.ctr_percentage}%</td>
                        <td>${v.conversions.toLocaleString('pt-BR')}</td>
                        <td class="${isBest}">${v.conversion_rate}%</td>
                        <td>R$ ${parseFloat(v.revenue).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                        <td>R$ ${parseFloat(v.avg_order_value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                        <td>${v.status}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        // Destruir gráficos antigos
        function destroyCharts() {
            Object.values(state.charts).forEach(chart => {
                if (chart) chart.destroy();
            });
            state.charts = {};
        }

        // Exportar para CSV
        function exportToCSV() {
            const csv = buildCSV();
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);

            link.setAttribute('href', url);
            link.setAttribute('download', `ab-testing-${state.selectedPage}-${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Construir CSV
        function buildCSV() {
            let csv = 'Page,Variant,Type,Impressions,Clicks,CTR %,Conversions,Conv %,Revenue,AOV\n';

            state.variants.forEach(v => {
                csv += `${state.selectedPage},${v.variant_name},${v.variant_type},${v.impressions},${v.clicks},${v.ctr_percentage},${v.conversions},${v.conversion_rate},${v.revenue},${v.avg_order_value}\n`;
            });

            return csv;
        }
    </script>
</body>
</html>
