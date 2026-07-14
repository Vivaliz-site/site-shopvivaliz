<?php
/**
 * Integração MP.js v2 Client-side
 * Documentação: https://www.mercadopago.com.br/developers/pt/docs/sdks-library/client-side/mp-js-v2
 */

// Load .env para public key
$env = [];
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

$publicKey = $env['MERCADOPAGO_PUBLIC_KEY'] ?? '';
?>

<!-- Mercado Pago SDK v2 - Client-side -->
<script src="https://sdk.mercadopago.com/js/v2"></script>

<script>
// Inicializar Mercado Pago
const mp = new MercadoPago('<?php echo htmlspecialchars($publicKey, ENT_QUOTES, 'UTF-8'); ?>', {
    locale: 'pt-BR'
});

// Variáveis globais
let createdOrderId = null;

/**
 * Criar Order no servidor e obter Order ID
 */
async function createOrderOnServer(orderData) {
    try {
        console.log('📡 Criando order no servidor...');

        const response = await fetch('/api/mercadopago-orders-sdk.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(orderData)
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (data.success && data.order_id) {
            console.log('✅ Order criada:', data.order_id);
            createdOrderId = data.order_id;
            return data;
        } else {
            throw new Error(data.error || 'Falha ao criar order');
        }
    } catch (error) {
        console.error('❌ Erro ao criar order:', error);
        throw error;
    }
}

/**
 * Renderizar formulário de pagamento com Payment Brick
 */
async function renderPaymentBrick() {
    try {
        console.log('🎨 Renderizando Payment Brick...');

        const brickBuilder = mp.bricks();

        const settings = {
            initialization: {
                amount: parseFloat(document.getElementById('order-total').value || 0)
            },
            customization: {
                paymentMethods: {
                    wallet: 'all',
                    credit_card: 'all',
                    debit_card: 'all',
                    ticket: 'all',
                    atm: 'all',
                    maxInstallments: 6
                },
                visual: {
                    hideFormTitle: false,
                    hideAmountSummary: false
                }
            },
            callbacks: {
                onReady: () => {
                    console.log('✅ Payment Brick pronto');
                },
                onSubmit: async (cardFormData) => {
                    console.log('📤 Processando pagamento...');
                    return await processPayment(cardFormData);
                },
                onFetching: (resource) => {
                    console.log('⏳ Buscando:', resource);
                },
                onError: (error) => {
                    console.error('❌ Erro no Payment Brick:', error);
                    showError('Erro ao processar pagamento: ' + error.message);
                }
            }
        };

        const brickController = await brickBuilder.create('payment', 'paymentBrick_container', settings);
        return brickController;
    } catch (error) {
        console.error('❌ Erro ao renderizar Payment Brick:', error);
        showError('Erro ao carregar formulário de pagamento');
        throw error;
    }
}

/**
 * Processar pagamento
 */
async function processPayment(paymentData) {
    try {
        console.log('💳 Iniciando pagamento...');
        console.log('Order ID:', createdOrderId);

        // Adicionar order ID aos dados de pagamento
        paymentData.order_id = createdOrderId;

        // Aqui você envia os dados para seu servidor
        // que processará o pagamento via API do Mercado Pago
        const response = await fetch('/api/process-payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(paymentData)
        });

        const result = await response.json();

        if (result.success) {
            console.log('✅ Pagamento processado:', result.payment_id);
            showSuccess('Pagamento processado com sucesso!');
            return {
                success: true,
                payment_id: result.payment_id
            };
        } else {
            console.error('❌ Erro no pagamento:', result.error);
            showError('Erro ao processar pagamento: ' + result.error);
            return {
                success: false,
                error: result.error
            };
        }
    } catch (error) {
        console.error('❌ Erro ao processar pagamento:', error);
        showError('Erro: ' + error.message);
        return {
            success: false,
            error: error.message
        };
    }
}

/**
 * Inicializar formulário ao submeter checkout
 */
async function initializePaymentFlow() {
    try {
        console.log('🚀 Iniciando fluxo de pagamento...');

        // 1. Coletar dados do formulário
        const orderData = collectCheckoutData();
        console.log('📋 Dados coletados:', orderData);

        // 2. Criar order no servidor
        const orderResult = await createOrderOnServer(orderData);
        console.log('🆔 Order ID recebido:', orderResult.order_id);

        // 3. Renderizar Payment Brick
        await renderPaymentBrick();

        // 4. Mostrar container de pagamento
        document.getElementById('paymentBrick_container').style.display = 'block';
        document.getElementById('checkout-form').style.display = 'none';

        return true;
    } catch (error) {
        console.error('❌ Erro no fluxo de pagamento:', error);
        showError('Erro ao iniciar pagamento');
        return false;
    }
}

/**
 * Coletar dados do checkout
 */
function collectCheckoutData() {
    return {
        external_reference: document.getElementById('pedido-id').value,
        total_amount: parseFloat(document.getElementById('order-total').value),
        items: JSON.parse(document.getElementById('cart-items').value || '[]'),
        payer: {
            email: document.getElementById('email').value,
            first_name: document.getElementById('nome').value.split(' ')[0],
            last_name: document.getElementById('nome').value.split(' ').slice(1).join(' '),
            phone: document.getElementById('telefone').value
        }
    };
}

/**
 * Mostrar mensagens ao usuário
 */
function showError(message) {
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger';
    alert.textContent = message;
    document.getElementById('payment-messages').appendChild(alert);
}

function showSuccess(message) {
    const alert = document.createElement('div');
    alert.className = 'alert alert-success';
    alert.textContent = message;
    document.getElementById('payment-messages').appendChild(alert);
}

// Exportar função global
window.initializePaymentFlow = initializePaymentFlow;
</script>

<style>
#paymentBrick_container {
    display: none;
    margin-top: 20px;
}

.alert {
    padding: 15px;
    margin-bottom: 15px;
    border: 1px solid transparent;
    border-radius: 4px;
}

.alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}
</style>
