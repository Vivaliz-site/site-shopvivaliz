#!/bin/bash
# Script completo para configurar TODOS os GitHub Secrets

echo "╔════════════════════════════════════════════════════════════════════╗"
echo "║                                                                    ║"
echo "║     🔐 CONFIGURADOR COMPLETO DE GITHUB SECRETS - TODOS OS ITENS   ║"
echo "║                                                                    ║"
echo "║     Vamos configurar TODAS as 15 credenciais necessárias         ║"
echo "║                                                                    ║"
echo "╚════════════════════════════════════════════════════════════════════╝"
echo ""

# Verificar autenticação GitHub
echo "🔍 Verificando autenticação GitHub..."
if ! gh auth status > /dev/null 2>&1; then
    echo "❌ Não autenticado no GitHub. Execute: gh auth login"
    exit 1
fi
echo "✅ Autenticado no GitHub"
echo ""

# Lista de TODOS os secrets (15 total)
declare -A secrets_config=(
    ["OPENAI_API_KEY"]="🤖 OpenAI API Key|https://platform.openai.com/api-keys|sk-proj-"
    ["ANTHROPIC_API_KEY"]="🧠 Anthropic API Key|https://console.anthropic.com|sk-ant-"
    ["SHOPEE_PARTNER_ID"]="🛍️  Shopee Partner ID|https://partner.shopee.com.br|1237032"
    ["SHOPEE_PARTNER_KEY"]="🛍️  Shopee Partner Key|https://partner.shopee.com.br|shpk_"
    ["TIKTOK_CLIENT_ID"]="🎵 TikTok Client ID|https://seller.tiktok.com|7"
    ["TIKTOK_CLIENT_SECRET"]="🎵 TikTok Client Secret|https://seller.tiktok.com|secret_"
    ["FTP_SERVER"]="📤 FTP Server|seu provedor|ftp.shopvivaliz.com.br"
    ["FTP_USERNAME"]="📤 FTP Username|seu provedor|usuario"
    ["FTP_PASSWORD"]="📤 FTP Password|seu provedor|senha"
    ["FTP_PORT"]="📤 FTP Port|padrão|21"
    ["EMAIL_FROM"]="📧 Email From|padrão|noreply@shopvivaliz.com.br"
    ["EMAIL_TO"]="📧 Email To|padrão|fredmourao@gmail.com"
    ["EMAIL_SMTP_HOST"]="📧 Email SMTP Host|padrão|smtp.gmail.com"
    ["EMAIL_SMTP_PORT"]="📧 Email SMTP Port|padrão|587"
    ["EMAIL_USER"]="📧 Email User|https://myaccount.google.com/app-passwords|seu-email@gmail.com"
)

echo "════════════════════════════════════════════════════════════════════"
echo "📝 CREDENCIAIS A CONFIGURAR (15 TOTAL):"
echo "════════════════════════════════════════════════════════════════════"
echo ""

counter=1
configured=0
skipped=0

for secret_name in "${!secrets_config[@]}"; do
    IFS='|' read -r description link example <<< "${secrets_config[$secret_name]}"

    echo "$counter. $description"
    echo "   Nome no GitHub: $secret_name"
    echo "   Link: $link"
    echo "   Exemplo: $example"
    echo "   Valor: "
    read -r secret_value
    echo ""

    if [ -z "$secret_value" ]; then
        echo "   ⏭️  PULADO (sem valor)"
        ((skipped++))
    else
        echo "   ⏳ Configurando no GitHub..."
        echo "$secret_value" | gh secret set $secret_name --repo fredmourao-ai/site-shopvivaliz

        if [ $? -eq 0 ]; then
            echo "   ✅ CONFIGURADO"
            ((configured++))
        else
            echo "   ❌ ERRO ao configurar"
        fi
    fi

    echo "────────────────────────────────────────────────────────────────"
    ((counter++))
done

echo ""
echo "════════════════════════════════════════════════════════════════════"
echo "✅ RESULTADO FINAL:"
echo "════════════════════════════════════════════════════════════════════"
echo "   Configurados: $configured"
echo "   Pulados: $skipped"
echo "   Total: $((configured + skipped))/15"
echo ""

# Listar todos os secrets configurados
echo "📋 SECRETS CONFIGURADOS NO GITHUB:"
echo "────────────────────────────────────────────────────────────────"
gh secret list --repo fredmourao-ai/site-shopvivaliz || echo "Erro ao listar"
echo ""

echo "════════════════════════════════════════════════════════════════════"
echo "🎉 PRÓXIMO PASSO:"
echo "════════════════════════════════════════════════════════════════════"
echo ""
echo "1. Fazer push para disparar o workflow automático:"
echo "   $ git push origin main"
echo ""
echo "2. Monitorar execução em:"
echo "   https://github.com/fredmourao-ai/site-shopvivaliz/actions"
echo ""
echo "3. Dashboard:"
echo "   https://shopvivaliz.com.br/admin/monitor/"
echo ""
echo "════════════════════════════════════════════════════════════════════"
echo "✨ SISTEMA COMEÇARÁ AUTOMATICAMENTE A FAZER UPLOAD! 🚀"
echo "════════════════════════════════════════════════════════════════════"
