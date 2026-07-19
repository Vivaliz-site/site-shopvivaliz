#!/bin/bash

echo "=========================================="
echo "DIAGNOSTICO DE PAGINAS REMOTAS"
echo "=========================================="
echo ""

# 1. Testar conexão com servidor
echo "1. TESTANDO CONEXAO COM SERVIDOR..."
URL="https://shopvivaliz.com.br"

echo "  Testando $URL..."
status=$(curl -s -o /dev/null -w "%{http_code}" "$URL")
echo "  HTTP Status: $status"

if [ "$status" = "000" ]; then
    echo "  [ERRO] Servidor nao responde"
    echo "  Problemas possiveis:"
    echo "    - Servidor fora"
    echo "    - DNS nao resolvendo"
    echo "    - Firewall bloqueando"
elif [ "$status" = "404" ]; then
    echo "  [ERRO 404] Arquivo nao encontrado"
    echo "  Problemas possiveis:"
    echo "    - index.php nao foi deployado"
    echo "    - FTP upload falhou"
    echo "    - Caminho do FTP errado"
elif [ "$status" = "500" ]; then
    echo "  [ERRO 500] Erro no servidor"
    echo "  Problemas possiveis:"
    echo "    - Erro de PHP nos arquivos"
    echo "    - Banco de dados nao conecta"
    echo "    - Falta de permissoes"
elif [ "$status" = "200" ]; then
    echo "  [OK] Servidor respondendo!"
else
    echo "  [AVISO] Status desconhecido"
fi

echo ""
echo "2. VERIFICANDO URLS CRITICAS..."
echo ""

urls=(
    "$URL/"
    "$URL/index.php"
    "$URL/admin/monitor/"
    "$URL/api/monitor/api.php"
)

for url in "${urls[@]}"; do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    if [ "$status" = "200" ]; then
        echo "  [OK] $url - HTTP $status"
    else
        echo "  [ERRO] $url - HTTP $status"
    fi
done

echo ""
echo "=========================================="
echo "SOLUCOES:"
echo "=========================================="
echo ""
echo "1. VERIFICAR FTP DEPLOYMENT:"
echo "   - Conectar via FTP"
echo "   - Verificar se index.php esta em /home1/shopv506/public_html/dev/"
echo "   - Verificar se config/ foi copiado"
echo ""
echo "2. SE ARQUIVO NAO ESTA:"
echo "   - Fazer commit: git push"
echo "   - Disparar deploy workflow no GitHub"
echo "   - Ou fazer deploy manual via FTP"
echo ""
echo "3. SE ARQUIVO ESTA MAS 500 ERROR:"
echo "   - Verificar file permissions (755)"
echo "   - Verificar se .env existe"
echo "   - Verificar se MySQL conecta"
echo "   - Ver error_log do servidor"
echo ""
