<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/admin-helpers.php';

$pages = AdminHelpers::getActivePagesWithTests();
$selectedPage = $_GET['page'] ?? ($pages[0]['page_id'] ?? 'homepage');
$variants = AdminHelpers::getPageVariants($selectedPage);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Analytics - ShopVivaliz</title>
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
                <a href="/admin/performance/">Performance</a>
                <a href="/admin/monitor/">Monitor</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="container dashboard-header">
            <div>
                <h1 class="dashboard-title">Performance Analytics</h1>
                <p class="dashboard-subtitle">Análise de ROI, correlações e performance de variantes</p>
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
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-top: 32px;">
                <button class="btn btn-secondary" id="btn-refresh">Atualizar</button>
            </div>
        </section>

        <?php if (empty($variants)): ?>
            <section class="container empty-state">
                <div class="empty-state-icon">📊</div>
                <h2 class="empty-state-title">Nenhuma variante encontrada</h2>
                <p class="empty-state-message">
                    Comece a criar variantes para ver análises de performance.
                </p>
            </section>
        <?php else: ?>

        <section class="container metrics-grid" id="metrics-container">
            <!-- Métricas via JS -->
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">📈 Correlation: Clicks vs Conversions</h2>
            <div class="chart-container large">
                <div class="chart-canvas large">
                    <canvas id="chart-correlation"></canvas>
                </div>
            </div>
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">💵 ROI por Variante</h2>
            <div class="chart-container large">
                <div class="chart-canvas large">
                    <canvas id="chart-roi"></canvas>
                </div>
            </div>
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">⚡ Eficiência de Conversão</h2>
            <div class="chart-container large">
                <div class="chart-canvas large">
                    <canvas id="chart-efficiency"></canvas>
                </div>
            </div>
        </section>

        <section class="container charts-section">
            <h2 class="section-heading">📊 Ranking de Performance</h2>
            <div class="chart-container">
                <table class="variants-table" id="performance-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Variante</th>
                            <th>Impressões</th>
                            <th>Cliques</th>
                            <th>Conv.</th>
                            <th>Conv. %</th>
                            <th>Receita</th>
                            <th>AOV</th>
                            <th>ROI</th>
                        </tr>
                    </thead>
                    <tbody id="performance-tbody">
                        <!-- Via JS -->
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>
    </main>

    <script>
        const state = {
            charts: {},
            variants: [],
            selectedPage: '<?= htmlspecialchars($selectedPage, ENT_QUOTES) ?>'
        };

        document.addEventListener('DOMContentLoaded', () => {
            loadData();
            setupEventListeners();
        });

        function setupEventListeners() {
            document.getElementById('page-select').addEventListener('change', (e) => {
                state.selectedPage = e.target.value;
                window.location.href = '?page=' + state.selectedPage;
            });

            document.getElementById('btn-refresh').addEventListener('click', loadData);
        }

        async function loadData() {
            try {
                const response = await fetch('/api/admin/ab-testing-data.php?action=variants&page_id=' + state.selectedPage);
                const data = await response.json();

                if (!data.ok) throw new Error(data.error);

                state.variants = data.data;
                renderMetrics();
                renderPerformanceCharts();
                renderPerformanceTable();

            } catch (error) {
                console.error('Erro:', error);
            }
        }

        function renderMetrics() {
            const container = document.getElementById('metrics-container');
            let html = '';

            const totals = state.variants.reduce((acc, v) => ({
                clicks: acc.clicks + (v.clicks || 0),
                conversions: acc.conversions + (v.conversions || 0),
                revenue: acc.revenue + (parseFloat(v.revenue) || 0)
            }), { clicks: 0, conversions: 0, revenue: 0 });

            const avgAOV = totals.conversions > 0 ? (totals.revenue / totals.conversions).toFixed(2) : '0.00';
            const avgROI = totals.clicks > 0 ? (totals.conversions * 100 / totals.clicks).toFixed(2) : '0.00';

            html += createMetricCard('Cliques Totais', totals.clicks.toLocaleString('pt-BR'), 'highlight');
            html += createMetricCard('Conversões Totais', totals.conversions.toLocaleString('pt-BR'));
            html += createMetricCard('Receita Total', 'R$ ' + totals.revenue.toLocaleString('pt-BR', { minimumFractionDigits: 2 }), 'highlight');
            html += createMetricCard('AOV Médio', 'R$ ' + avgAOV);
            html += createMetricCard('ROI Médio', avgROI + '%', 'highlight');

            container.innerHTML = html;
        }

        function createMetricCard(label, value, highlight = '') {
            const highlightClass = highlight === 'highlight' ? ' highlighted' : '';
            return `
                <div class="metric-card${highlightClass}">
                    <div class="metric-label">${label}</div>
                    <div class="metric-value">${value}</div>
                </div>
            `;
        }

        function renderPerformanceCharts() {
            destroyCharts();

            const names = state.variants.map(v => v.variant_name);
            const clicks = state.variants.map(v => v.clicks || 0);
            const conversions = state.variants.map(v => v.conversions || 0);
            const revenue = state.variants.map(v => parseFloat(v.revenue) || 0);
            const colors = ['#173B63', '#059669', '#d97706', '#7c3aed'];

            // Correlation chart
            const ctxCorr = document.getElementById('chart-correlation').getContext('2d');
            state.charts.correlation = new Chart(ctxCorr, {
                type: 'scatter',
                data: {
                    datasets: state.variants.map((v, i) => ({
                        label: v.variant_name,
                        data: [{ x: v.clicks || 0, y: v.conversions || 0 }],
                        backgroundColor: colors[i % colors.length],
                        pointRadius: 8,
                        pointHoverRadius: 10
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true },
                        title: { display: true, text: 'Relação Cliques vs Conversões' }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Cliques' } },
                        y: { title: { display: true, text: 'Conversões' } }
                    }
                }
            });

            // ROI chart
            const ctxRoi = document.getElementById('chart-roi').getContext('2d');
            const roi = state.variants.map(v => {
                if (v.conversions === 0) return 0;
                return (parseFloat(v.revenue) / v.conversions).toFixed(2);
            });

            state.charts.roi = new Chart(ctxRoi, {
                type: 'bar',
                data: {
                    labels: names,
                    datasets: [{
                        label: 'Receita por Conversão (R$)',
                        data: roi,
                        backgroundColor: colors.slice(0, names.length)
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });

            // Efficiency chart
            const ctxEff = document.getElementById('chart-efficiency').getContext('2d');
            const efficiency = state.variants.map(v => {
                if (v.clicks === 0) return 0;
                return ((v.conversions * 100 / v.clicks)).toFixed(2);
            });

            state.charts.efficiency = new Chart(ctxEff, {
                type: 'bar',
                data: {
                    labels: names,
                    datasets: [{
                        label: 'Taxa de Conversão (%)',
                        data: efficiency,
                        backgroundColor: colors.slice(0, names.length)
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        }

        function renderPerformanceTable() {
            const tbody = document.getElementById('performance-tbody');
            let html = '';

            // Ordenar por conversões
            const sorted = [...state.variants].sort((a, b) => b.conversions - a.conversions);

            sorted.forEach((v, idx) => {
                const aov = v.conversions > 0 ? (parseFloat(v.revenue) / v.conversions).toFixed(2) : '0.00';
                const roi = v.conversions > 0 ? (parseFloat(v.revenue) / (v.clicks || 1)).toFixed(2) : '0.00';
                const convRate = v.clicks > 0 ? (v.conversions * 100 / v.clicks).toFixed(2) : '0.00';

                html += `
                    <tr style="${idx === 0 ? 'background: #fef08a;' : ''}">
                        <td><strong>${idx + 1}</strong></td>
                        <td class="variant-name">${v.variant_name}</td>
                        <td>${v.impressions.toLocaleString('pt-BR')}</td>
                        <td>${v.clicks.toLocaleString('pt-BR')}</td>
                        <td>${v.conversions.toLocaleString('pt-BR')}</td>
                        <td class="metric-positive">${convRate}%</td>
                        <td>R$ ${parseFloat(v.revenue).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</td>
                        <td>R$ ${aov}</td>
                        <td class="metric-positive">R$ ${roi}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function destroyCharts() {
            Object.values(state.charts).forEach(chart => {
                if (chart) chart.destroy();
            });
            state.charts = {};
        }
    </script>
</body>
</html>
