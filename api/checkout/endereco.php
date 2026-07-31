<?php
/**
 * Formulário de Endereço com Busca CEP
 * Integra com ViaCEP para auto-preenchimento
 */

session_start();

require_once __DIR__ . '/../includes/viacep-integration.php';

header('Content-Type: application/json; charset=utf-8');

// Processar busca de CEP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'buscar_cep') {
        $cep = trim($_POST['cep'] ?? '');

        if (empty($cep)) {
            echo json_encode(['success' => false, 'error' => 'CEP não informado']);
            exit;
        }

        $endereco = buscar_endereco_por_cep($cep);

        if ($endereco && !$endereco['erro']) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'rua' => $endereco['rua'],
                    'bairro' => $endereco['bairro'],
                    'cidade' => $endereco['cidade'],
                    'estado' => $endereco['estado'],
                    'cep' => formatar_cep($endereco['cep']),
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'CEP não encontrado']);
        }
        exit;
    }

    // Salvar endereço na sessão
    if ($action === 'salvar_endereco') {
        $_SESSION['endereco'] = [
            'cep' => trim($_POST['cep'] ?? ''),
            'rua' => trim($_POST['rua'] ?? ''),
            'numero' => trim($_POST['numero'] ?? ''),
            'complemento' => trim($_POST['complemento'] ?? ''),
            'bairro' => trim($_POST['bairro'] ?? ''),
            'cidade' => trim($_POST['cidade'] ?? ''),
            'estado' => trim($_POST['estado'] ?? ''),
        ];

        echo json_encode(['success' => true, 'message' => 'Endereço salvo']);
        exit;
    }
}

// Se não for API, exibir formulário HTML
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Endereço de Entrega - ShopVivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-bottom: 30px; font-size: 24px; }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .cep-busca {
            display: flex;
            gap: 10px;
        }
        .cep-busca input {
            flex: 1;
        }
        .cep-busca button {
            flex: 0 0 auto;
            padding: 12px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        .cep-busca button:hover {
            background: #5568d3;
        }
        .loading {
            display: none;
            color: #667eea;
            font-size: 12px;
            margin-top: 5px;
        }
        .error {
            display: none;
            background: #ffebee;
            color: #c33;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 13px;
        }
        .success {
            display: none;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 13px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        button[type="submit"] {
            width: 100%;
            padding: 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        button[type="submit"]:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📍 Endereço de Entrega</h1>

        <form id="formEndereco">
            <!-- Busca CEP -->
            <div class="form-group">
                <label for="cep">CEP *</label>
                <div class="cep-busca">
                    <input type="text" id="cep" name="cep" placeholder="00000-000" maxlength="9" required>
                    <button type="button" id="btnBuscarCep">Buscar</button>
                </div>
                <div class="loading" id="loadingCep">Buscando CEP...</div>
                <div class="error" id="errorCep"></div>
                <div class="success" id="successCep"></div>
            </div>

            <!-- Rua -->
            <div class="form-group">
                <label for="rua">Rua / Logradouro *</label>
                <input type="text" id="rua" name="rua" required>
            </div>

            <!-- Número e Complemento -->
            <div class="form-row">
                <div class="form-group">
                    <label for="numero">Número *</label>
                    <input type="text" id="numero" name="numero" required>
                </div>
                <div class="form-group">
                    <label for="complemento">Complemento</label>
                    <input type="text" id="complemento" name="complemento" placeholder="Apto, bloco, etc">
                </div>
            </div>

            <!-- Bairro -->
            <div class="form-group">
                <label for="bairro">Bairro *</label>
                <input type="text" id="bairro" name="bairro" required>
            </div>

            <!-- Cidade e Estado -->
            <div class="form-row">
                <div class="form-group">
                    <label for="cidade">Cidade *</label>
                    <input type="text" id="cidade" name="cidade" required>
                </div>
                <div class="form-group">
                    <label for="estado">Estado *</label>
                    <input type="text" id="estado" name="estado" maxlength="2" required>
                </div>
            </div>

            <button type="submit">✅ Confirmar Endereço</button>
        </form>
    </div>

    <script>
        // Formatar CEP enquanto digita
        document.getElementById('cep').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0, 5) + '-' + value.substring(5, 8);
            }
            e.target.value = value;
        });

        // Buscar CEP
        document.getElementById('btnBuscarCep').addEventListener('click', async function() {
            const cep = document.getElementById('cep').value.replace(/\D/g, '');

            if (cep.length !== 8) {
                showError('CEP deve ter 8 dígitos');
                return;
            }

            showLoading(true);

            try {
                const response = await fetch('?action=buscar_cep', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'cep=' + cep
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('rua').value = data.data.rua;
                    document.getElementById('bairro').value = data.data.bairro;
                    document.getElementById('cidade').value = data.data.cidade;
                    document.getElementById('estado').value = data.data.estado;
                    showSuccess('CEP encontrado! Preencha o restante.');
                } else {
                    showError(data.error || 'CEP não encontrado');
                }
            } catch (e) {
                showError('Erro ao buscar CEP: ' + e.message);
            } finally {
                showLoading(false);
            }
        });

        // Enviar formulário
        document.getElementById('formEndereco').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'salvar_endereco');

            try {
                const response = await fetch('?', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('✅ Endereço salvo! Redirecionando...');
                    setTimeout(() => {
                        window.location.href = '/checkout/pagamento.php';
                    }, 1500);
                } else {
                    showError(data.error || 'Erro ao salvar endereço');
                }
            } catch (e) {
                showError('Erro: ' + e.message);
            }
        });

        function showLoading(show) {
            document.getElementById('loadingCep').style.display = show ? 'block' : 'none';
        }

        function showError(msg) {
            const el = document.getElementById('errorCep');
            el.textContent = msg;
            el.style.display = 'block';
            document.getElementById('successCep').style.display = 'none';
        }

        function showSuccess(msg) {
            const el = document.getElementById('successCep');
            el.textContent = msg;
            el.style.display = 'block';
            document.getElementById('errorCep').style.display = 'none';
        }
    </script>
</body>
</html>
