<?php
/**
 * Callback OAuth do Olist
 * Recebe o authorization code e o troca por um token de acesso
 */

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    echo "Erro na autorização: " . htmlspecialchars($error);
    exit;
}

if (!$code) {
    echo "Nenhum código recebido";
    exit;
}

// Mostrar o código para o usuário copiar
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorização Olist - ShopVivaliz</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #2ECC71 0%, #1F3A70 100%);
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            max-width: 500px;
            text-align: center;
        }
        h1 {
            color: #2ECC71;
            margin-bottom: 20px;
        }
        .code-box {
            background: #f5f5f5;
            border: 2px solid #2ECC71;
            padding: 20px;
            border-radius: 6px;
            margin: 20px 0;
            word-break: break-all;
            font-family: monospace;
            font-size: 14px;
            color: #333;
        }
        .btn {
            background: #2ECC71;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        .btn:hover {
            background: #27ae60;
        }
        .instructions {
            color: #666;
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ Autorização Bem-Sucedida!</h1>

        <p>Sua autorização foi aceita. Copie o código abaixo e envie para o Claude:</p>

        <div class="code-box" id="codeBox">
            <?php echo htmlspecialchars($code); ?>
        </div>

        <button class="btn" onclick="copyCode()">Copiar Código</button>

        <div class="instructions">
            <p><strong>O que fazer agora:</strong></p>
            <ol style="text-align: left;">
                <li>Copie o código acima</li>
                <li>Volte ao Claude Code</li>
                <li>Cole o código na mensagem</li>
                <li>Aguarde a sincronização de 198 produtos</li>
            </ol>
        </div>
    </div>

    <script>
        function copyCode() {
            const codeBox = document.getElementById('codeBox');
            const code = codeBox.textContent;
            navigator.clipboard.writeText(code).then(() => {
                alert('Código copiado! Cole no Claude agora.');
            });
        }
    </script>
</body>
</html>
