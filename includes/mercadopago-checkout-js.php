<?php
declare(strict_types=1);

// Carregar secrets de forma segura
$runtimeSecretsFile = dirname(__DIR__) . '/config/runtime-secrets.php';
$secrets = (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile))
    ? (array)require $runtimeSecretsFile
    : [];

function mp_get_secret(string $key, array $secrets): string {
    $value = getenv($key);
    if (is_string($value) && $value !== '') return $value;
    if (isset($secrets[$key])) return (string)$secrets[$key];
    if (isset($_ENV[$key])) return (string)$_ENV[$key];
    return '';
}

$publicKey = mp_get_secret('MERCADOPAGO_PUBLIC_KEY', $secrets);
if (!$publicKey) {
    echo '<!-- Mercado Pago não configurado -->';
    exit;
}
?>

<script src="https://sdk.mercadopago.com/js/v2"></script>
<script>
    // Inicializar MercadoPago com chave pública
    const mp = new MercadoPago('<?php echo htmlspecialchars($publicKey, ENT_QUOTES, 'UTF-8'); ?>');

    // Estado global
    let currentPaymentBrick = null;

    /**
     * Criar Order no servidor e retornar Order ID válido
     */
    async function createOrderOnServer(cartItems, customerData) {
        try {
            const response = await fetch('/api/orders/create-validated.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    items: cartItems,
                    customer: customerData,
                    external_reference: 'order-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9)
                })
            });

            const data = await response.json();
            if (!data.ok) throw new Error(data.error || 'Order creation failed');
            return data.order_id;
        } catch (error) {
            console.error('Order creation error:', error);
            throw error;
        }
    }

    /**
     * Renderizar Payment Brick
     */
    async function renderPaymentBrick(orderId, totalAmount) {
        const brickContainer = document.getElementById('payment-brick-container');
        if (!brickContainer) {
            console.error('Payment brick container not found');
            return;
        }

        try {
            const settings = {
                initialization: {
                    amount: totalAmount,
                    payer: {
                        email: document.getElementById('email')?.value || ''
                    }
                },
                customization: {
                    visual: {
                        style: 'default'
                    },
                    paymentMethods: {
                        visa: 'all',
                        mastercard: 'all',
                        amex: 'all',
                        pix: 'all',
                        boleto: 'all'
                    }
                },
                callbacks: {
                    onReady: () => console.log('Payment Brick ready'),
                    onError: (error) => console.error('Payment Brick error:', error),
                    onSubmit: async (formData) => {
                        await processPayment(orderId, formData);
                    }
                }
            };

            if (currentPaymentBrick) {
                currentPaymentBrick.unmount();
            }

            currentPaymentBrick = await mp.bricks.create('payment', 'payment-brick-container', settings);
        } catch (error) {
            console.error('Payment brick render error:', error);
            alert('Erro ao carregar formulário de pagamento');
        }
    }

    /**
     * Processar pagamento (enviar token para servidor)
     */
    async function processPayment(orderId, formData) {
        try {
            const response = await fetch('/api/process-payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_id: orderId,
                    external_reference: formData.external_reference || orderId,
                    payment_token: formData.token,
                    installments: formData.installments || 1
                })
            });

            const data = await response.json();

            if (!data.ok) {
                alert('Erro ao processar pagamento: ' + (data.error || 'Tente novamente'));
                return;
            }

            // Redirecionar para confirmação
            window.location.href = '/checkout/success?payment_id=' + encodeURIComponent(data.payment_id) + '&order_id=' + encodeURIComponent(orderId);
        } catch (error) {
            console.error('Payment processing error:', error);
            alert('Erro ao processar pagamento');
        }
    }

    /**
     * Iniciar fluxo de checkout
     */
    async function startCheckout() {
        const cartItems = collectCartItems();
        if (!cartItems || cartItems.length === 0) {
            alert('Carrinho vazio');
            return;
        }

        const customerData = collectCustomerData();
        if (!customerData) {
            alert('Preencha todos os dados do cliente');
            return;
        }

        const totalAmount = cartItems.reduce((sum, item) => sum + (item.price * item.quantity), 0);

        try {
            // Criar order no servidor
            const orderId = await createOrderOnServer(cartItems, customerData);
            console.log('Order created:', orderId);

            // Renderizar Payment Brick
            await renderPaymentBrick(orderId, totalAmount);

            // Mostrar container
            const container = document.getElementById('payment-brick-container');
            if (container) {
                container.style.display = 'block';
                container.scrollIntoView({ behavior: 'smooth' });
            }
        } catch (error) {
            console.error('Checkout error:', error);
            alert('Erro ao iniciar checkout: ' + error.message);
        }
    }

    /**
     * Coletar dados do cliente
     */
    function collectCustomerData() {
        const nome = document.getElementById('nome')?.value?.trim();
        const email = document.getElementById('email')?.value?.trim();
        const telefone = document.getElementById('telefone')?.value?.trim();
        const endereco = document.getElementById('endereco')?.value?.trim();
        const numero = document.getElementById('numero')?.value?.trim();
        const cidade = document.getElementById('cidade')?.value?.trim();
        const cep = document.getElementById('cep')?.value?.trim();

        if (!nome || !email || !telefone || !endereco || !numero || !cidade || !cep) {
            return null;
        }

        return {
            nome, email, telefone, endereco, numero, cidade, cep
        };
    }

    /**
     * Coletar itens do carrinho
     */
    function collectCartItems() {
        // Implementar conforme estrutura do seu carrinho
        // Exemplo: const items = JSON.parse(document.getElementById('cart-payload')?.value || '[]');
        return [];
    }

    // Exportar funções globais
    window.mpCheckout = {
        startCheckout,
        renderPaymentBrick,
        processPayment,
        createOrderOnServer
    };
</script>
