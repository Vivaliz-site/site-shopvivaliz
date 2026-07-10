<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../core/GitVersioning.php';

use Core\GitVersioning;

$git = new GitVersioning();
$selectedPage = $_GET['page'] ?? 'homepage';
$history = $git->getHistory($selectedPage, 50);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layout History - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin-charts.css">
</head>
<body>
    <nav class="navbar">
        <div class="container nav-inner">
            <a class="brand-link" href="/">ShopVivaliz Admin</a>
            <div class="navbar-menu">
                <a href="/">Loja</a>
                <a href="/admin/">Dashboard</a>
                <a href="/admin/ab-testing/">A/B Testing</a>
                <a href="/admin/layout-history/">Layout History</a>
            </div>
        </div>
    </nav>

    <main class="catalog-page">
        <section class="container dashboard-header">
            <div>
                <h1 class="dashboard-title">Layout History</h1>
                <p class="dashboard-subtitle">Histórico de commits Git e versionamento de layouts</p>
            </div>
        </section>

        <section class="container filter-bar">
            <div class="filter-group">
                <label for="page-select">Página</label>
                <select id="page-select">
                    <option value="homepage" <?= $selectedPage === 'homepage' ? 'selected' : '' ?>>Homepage</option>
                    <option value="categoria" <?= $selectedPage === 'categoria' ? 'selected' : '' ?>>Categoria</option>
                    <option value="produto" <?= $selectedPage === 'produto' ? 'selected' : '' ?>>Produto</option>
                    <option value="checkout" <?= $selectedPage === 'checkout' ? 'selected' : '' ?>>Checkout</option>
                </select>
            </div>

            <div style="margin-top: 32px;">
                <button class="btn btn-secondary" id="btn-refresh">Atualizar</button>
            </div>
        </section>

        <?php if (!$git->isEnabled()): ?>
            <section class="container empty-state">
                <div class="empty-state-icon">⚠️</div>
                <h2 class="empty-state-title">Git não está disponível</h2>
                <p class="empty-state-message">
                    O repositório Git não foi encontrado neste servidor.
                </p>
            </section>
        <?php elseif (empty($history)): ?>
            <section class="container empty-state">
                <div class="empty-state-icon">📝</div>
                <h2 class="empty-state-title">Nenhum histórico encontrado</h2>
                <p class="empty-state-message">
                    Nenhum commit foi feito para este layout ainda.
                </p>
            </section>
        <?php else: ?>

        <section class="container" style="margin: 40px 0;">
            <div class="timeline" id="history-timeline">
                <?php foreach ($history as $commit): ?>
                    <div class="timeline-item" data-hash="<?= htmlspecialchars($commit['hash'], ENT_QUOTES) ?>">
                        <div class="timeline-dot"></div>
                        <div class="timeline-content">
                            <div class="timeline-date">
                                <?= htmlspecialchars($commit['date'], ENT_QUOTES) ?>
                            </div>
                            <div class="timeline-title">
                                <?= htmlspecialchars(substr($commit['hash'], 0, 7), ENT_QUOTES) ?>
                                — <?= htmlspecialchars($commit['message'], ENT_QUOTES) ?>
                            </div>
                            <div class="timeline-description">
                                Por <strong><?= htmlspecialchars($commit['author'], ENT_QUOTES) ?></strong>
                            </div>
                            <div style="margin-top: 12px; display: flex; gap: 8px;">
                                <button class="btn btn-secondary btn-small" onclick="showCommitDetails('<?= htmlspecialchars($commit['hash'], ENT_QUOTES) ?>')">
                                    Ver detalhes
                                </button>
                                <button class="btn btn-secondary btn-small" onclick="revertToCommit('<?= htmlspecialchars($commit['hash'], ENT_QUOTES) ?>')">
                                    Reverter para esta versão
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="container" id="commit-details" style="display: none; margin: 40px 0;">
            <div class="chart-container" style="background: #f3f4f6;">
                <h3 style="margin-top: 0;">Detalhes do Commit</h3>
                <div id="details-content" style="font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-word;"></div>
                <button class="btn btn-secondary" onclick="document.getElementById('commit-details').style.display = 'none';" style="margin-top: 16px;">Fechar</button>
            </div>
        </section>

        <?php endif; ?>
    </main>

    <script>
        // Page selection
        document.getElementById('page-select').addEventListener('change', (e) => {
            window.location.href = '?page=' + e.target.value;
        });

        // Refresh
        document.getElementById('btn-refresh').addEventListener('click', () => {
            location.reload();
        });

        // Show commit details
        async function showCommitDetails(hash) {
            try {
                const response = await fetch(`/api/admin/layout-history.php?page_id=<?= htmlspecialchars($selectedPage, ENT_QUOTES) ?>&hash=${hash}`);
                const data = await response.json();

                if (data.ok && data.content) {
                    const details = document.getElementById('details-content');
                    details.textContent = JSON.stringify(data.content, null, 2);
                    document.getElementById('commit-details').style.display = 'block';
                } else {
                    alert('Não foi possível carregar os detalhes do commit.');
                }
            } catch (error) {
                alert('Erro ao carregar detalhes: ' + error.message);
            }
        }

        // Revert to commit
        async function revertToCommit(hash) {
            if (!confirm('Tem certeza que deseja reverter para esta versão? Esta ação não pode ser desfeita.')) {
                return;
            }

            try {
                const response = await fetch('/api/admin/layout-revert.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        page_id: '<?= htmlspecialchars($selectedPage, ENT_QUOTES) ?>',
                        hash: hash
                    })
                });

                const data = await response.json();

                if (data.ok) {
                    alert('Layout revertido com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + (data.error || 'Não foi possível reverter'));
                }
            } catch (error) {
                alert('Erro ao reverter: ' + error.message);
            }
        }
    </script>

    <style>
        .btn-small {
            padding: 6px 12px !important;
            font-size: 12px !important;
        }
    </style>
</body>
</html>
