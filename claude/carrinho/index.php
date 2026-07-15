<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vivaliz - Carrinho</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .cart-shell {
            padding: 36px 0 64px;
        }
        .cart-header {
            margin-bottom: 24px;
        }
        .cart-header h1 {
            color: #1F3A70;
            margin-bottom: 8px;
        }
        .cart-layout {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
            gap: 24px;
        }
        .cart-panel,
        .summary-panel {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .cart-panel {
            overflow: hidden;
        }
        .cart-table {
            width: 100%;
            border-collapse: collapse;
        }
        .cart-table th,
        .cart-table td {
            padding: 16px;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: middle;
        }
        .cart-table th {
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .item-line {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .item-thumb {
            width: 72px;
            height: 72px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            object-fit: cover;
            flex-shrink: 0;
        }
        .item-title {
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .item-sku {
            font-size: 12px;
            color: #64748b;
        }
        .qty-input {
            width: 84px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font: inherit;
        }
        .remove-btn,
        .primary-btn,
        .ghost-btn {
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font: inherit;
            font-weight: 600;
            transition: transform 0.2s ease, opacity 0.2s ease, background 0.2s ease;
        }
        .remove-btn {
            background: #fee2e2;
            color: #b91c1c;
            padding: 10px 12px;
        }
        .primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            background: #1F3A70;
            color: white;
            padding: 14px 18px;
            text-decoration: none;
        }
        .ghost-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            background: white;
            color: #1F3A70;
            border: 1px solid #cbd5e1;
            padding: 14px 18px;
            text-decoration: none;
        }
        .summary-panel {
            padding: 22px;
            height: fit-content;
        }
        .summary-panel h2 {
            color: #1F3A70;
            margin-bottom: 16px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 0;
            color: #475569;
            border-bottom: 1px solid #eef2f7;
        }
        .summary-total {
            font-size: 24px;
            color: #0f172a;
            font-weight: 700;
            padding-top: 18px;
        }
        .summary-actions {
            display: grid;
            gap: 12px;
            margin-top: 22px;
        }
        .empty-state {
            padding: 48px 24px;
            text-align: center;
            color: #64748b;
        }
        .empty-state h2 {
            color: #1F3A70;
            margin-bottom: 10px;
        }
        .toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            background: #0f172a;
            color: white;
            padding: 12px 16px;
            border-radius: 12px;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        @media (max-width: 900px) {
            .cart-layout {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 720px) {
            .cart-table thead {
                display: none;
            }
            .cart-table,
            .cart-table tbody,
            .cart-table tr,
            .cart-table td {
                display: block;
                width: 100%;
            }
            .cart-table tr {
                border-bottom: 1px solid #eef2f7;
            }
            .cart-table td {
                padding: 12px 16px;
                border-bottom: none;
            }
            .cart-table td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 12px;
                text-transform: uppercase;
                color: #64748b;
                margin-bottom: 6px;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>
<main class="cart-shell">
    <div class="container">
        <div class="cart-header">
            <h1>Carrinho</h1>
            <p>Revise os itens escolhidos antes de seguir para a finalizacao do pedido.</p>
        </div>

        <div id="cart-root"></div>
    </div>
</main>

<div class="toast" id="cart-toast"></div>

<script>
    (function () {
        const root = document.getElementById('cart-root');
        const toast = document.getElementById('cart-toast');

        function money(value) {
            return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }

        function showToast(message) {
            if (!toast) return;
            toast.textContent = message;
            toast.classList.add('show');
            window.clearTimeout(showToast._timer);
            showToast._timer = window.setTimeout(function () {
                toast.classList.remove('show');
            }, 2200);
        }

        function readCart() {
            try {
                const value = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
                return Array.isArray(value) ? value : [];
            } catch (error) {
                return [];
            }
        }

        function writeCart(items) {
            localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
            render();
        }

        function renderEmpty() {
            root.innerHTML = `
                <section class="cart-panel empty-state">
                    <h2>Seu carrinho esta vazio</h2>
                    <p>Adicione produtos no catalogo para continuar a compra.</p>
                    <div class="summary-actions" style="max-width:320px;margin:24px auto 0;">
                        <a class="primary-btn" href="catalogo">Ir para o catalogo</a>
                    </div>
                </section>
            `;
        }

        function render() {
            const items = readCart();
            if (!items.length) {
                renderEmpty();
                return;
            }

            const subtotal = items.reduce(function (sum, item) {
                return sum + (Number(item.price || 0) * Number(item.quantity || 1));
            }, 0);

            root.innerHTML = `
                <div class="cart-layout">
                    <section class="cart-panel">
                        <table class="cart-table">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th>Preco</th>
                                    <th>Quantidade</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${items.map(function (item, index) {
                                    const image = item.image_url || item.image || '/favicon.ico';
                                    const quantity = Math.max(1, Number(item.quantity || 1));
                                    return `
                                        <tr>
                                            <td data-label="Produto">
                                                <div class="item-line">
                                                    <img class="item-thumb" src="${image}" alt="${item.name || 'Produto'}" onerror="this.src='/favicon.ico'">
                                                    <div>
                                                        <div class="item-title">${item.name || 'Produto ShopVivaliz'}</div>
                                                        <div class="item-sku">${item.sku || 'sem-sku'}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Preco">${money(item.price || 0)}</td>
                                            <td data-label="Quantidade">
                                                <input class="qty-input" type="number" min="1" value="${quantity}" data-index="${index}" data-role="quantity">
                                            </td>
                                            <td data-label="Subtotal">${money((item.price || 0) * quantity)}</td>
                                            <td data-label="Remover">
                                                <button class="remove-btn" type="button" data-index="${index}" data-role="remove">Remover</button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </section>

                    <aside class="summary-panel">
                        <h2>Resumo do pedido</h2>
                        <div class="summary-row">
                            <span>Itens</span>
                            <strong>${items.reduce(function (sum, item) { return sum + Number(item.quantity || 1); }, 0)}</strong>
                        </div>
                        <div class="summary-row">
                            <span>Subtotal</span>
                            <strong>${money(subtotal)}</strong>
                        </div>
                        <div class="summary-row">
                            <span>Frete</span>
                            <strong>A calcular</strong>
                        </div>
                        <div class="summary-row summary-total">
                            <span>Total</span>
                            <span>${money(subtotal)}</span>
                        </div>
                        <div class="summary-actions">
                            <a class="primary-btn" href="checkout">Ir para checkout</a>
                            <a class="ghost-btn" href="catalogo">Continuar comprando</a>
                        </div>
                    </aside>
                </div>
            `;

            root.querySelectorAll('[data-role="quantity"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    const next = readCart();
                    const index = Number(input.getAttribute('data-index'));
                    const quantity = Math.max(1, Number(input.value || 1));
                    if (!next[index]) return;
                    next[index].quantity = quantity;
                    writeCart(next);
                    showToast('Quantidade atualizada.');
                });
            });

            root.querySelectorAll('[data-role="remove"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    const next = readCart();
                    const index = Number(button.getAttribute('data-index'));
                    if (!next[index]) return;
                    next.splice(index, 1);
                    writeCart(next);
                    showToast('Item removido do carrinho.');
                });
            });
        }

        render();
    })();
</script>
</body>
</html>
