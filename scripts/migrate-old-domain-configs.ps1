# Script de Migração de Configurações: ecommerceolist.com.br → shopvivaliz.com.br
# Data: 2026-07-19

$OLD_DOMAIN = "ecommerceolist.com.br"
$NEW_DOMAIN = "shopvivaliz.com.br"
$OLD_URL = "https://$OLD_DOMAIN"
$NEW_URL = "https://$NEW_DOMAIN"

Write-Host "🔄 Iniciando migração de configs: $OLD_DOMAIN → $NEW_DOMAIN"
Write-Host ""

# Passo 1: Baixar arquivo robots.txt do site antigo
Write-Host "[1/5] Processando robots.txt..."
try {
    $robotsOld = Invoke-WebRequest -Uri "$OLD_URL/robots.txt" -ErrorAction Stop
    $robotsContent = $robotsOld.Content -replace $OLD_DOMAIN, $NEW_DOMAIN
    $robotsContent | Set-Content -Path "C:\site-shopvivaliz\public_html\robots.txt" -Encoding UTF8
    Write-Host "✅ robots.txt migrado com sucesso"
} catch {
    Write-Host "⚠️ Não foi possível baixar robots.txt: $_"
}

# Passo 2: Atualizar tracking code do Google Analytics
Write-Host "[2/5] Atualizando Google Analytics tracking code..."
try {
    $indexFile = "C:\site-shopvivaliz\index.php"
    $layoutFile = "C:\site-shopvivaliz\includes\layout.php"

    # Procurar arquivo que tem o tracking code
    @($indexFile, $layoutFile) | ForEach-Object {
        if (Test-Path $_) {
            $content = Get-Content $_ -Raw
            # Padrão: G-XXXXXXXXXX (Google Analytics 4)
            if ($content -match "G-[A-Z0-9]{10}") {
                Write-Host "  ✓ Encontrado tracking code em: $_"
                # Será atualizado manualmente após obter ID do novo GA
            }
        }
    }
} catch {
    Write-Host "⚠️ Erro ao processar Analytics: $_"
}

# Passo 3: Atualizar meta tags (Open Graph, etc)
Write-Host "[3/5] Atualizando Open Graph e meta tags..."
try {
    $layoutFile = "C:\site-shopvivaliz\includes\layout.php"
    if (Test-Path $layoutFile) {
        $content = Get-Content $layoutFile -Raw

        # Substituir URLs
        $content = $content -replace $OLD_DOMAIN, $NEW_DOMAIN
        $content = $content -replace $OLD_URL, $NEW_URL

        Set-Content -Path $layoutFile -Value $content -Encoding UTF8
        Write-Host "✅ Meta tags atualizadas em layout.php"
    }
} catch {
    Write-Host "⚠️ Erro ao atualizar meta tags: $_"
}

# Passo 4: Gerar Sitemap com URLs do banco
Write-Host "[4/5] Gerando sitemap.xml dinâmico..."

$sitemapContent = @"
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <!-- URLs Estáticas -->
  <url>
    <loc>$NEW_URL/</loc>
    <lastmod>$(Get-Date -Format 'yyyy-MM-dd')</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>$NEW_URL/sobre</loc>
    <lastmod>$(Get-Date -Format 'yyyy-MM-dd')</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>
  <url>
    <loc>$NEW_URL/contato</loc>
    <lastmod>$(Get-Date -Format 'yyyy-MM-dd')</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>
  <url>
    <loc>$NEW_URL/politica-privacidade</loc>
    <lastmod>$(Get-Date -Format 'yyyy-MM-dd')</lastmod>
    <changefreq>yearly</changefreq>
    <priority>0.5</priority>
  </url>
  <url>
    <loc>$NEW_URL/termos-servico</loc>
    <lastmod>$(Get-Date -Format 'yyyy-MM-dd')</lastmod>
    <changefreq>yearly</changefreq>
    <priority>0.5</priority>
  </url>
  <!-- Produtos serão adicionados via script PHP dinâmico -->
</urlset>
"@

$sitemapContent | Set-Content -Path "C:\site-shopvivaliz\public_html\sitemap.xml" -Encoding UTF8
Write-Host "✅ sitemap.xml base criado (produtos serão adicionados dinamicamente)"

# Passo 5: Criar sitemap dinâmico em PHP
Write-Host "[5/5] Criando gerador dinâmico de sitemap.php..."

$sitemapPhp = @"
<?php
// Gerador dinâmico de sitemap.xml
// Localização: /public_html/sitemap.php
// Acesso: https://shopvivaliz.com.br/sitemap.xml (via .htaccess rewrite)

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // Cache 24h

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

// URLs estáticas
\$static_urls = [
    ['url' => '/', 'priority' => '1.0', 'freq' => 'weekly'],
    ['url' => '/sobre', 'priority' => '0.7', 'freq' => 'monthly'],
    ['url' => '/contato', 'priority' => '0.7', 'freq' => 'monthly'],
    ['url' => '/politica-privacidade', 'priority' => '0.5', 'freq' => 'yearly'],
    ['url' => '/termos-servico', 'priority' => '0.5', 'freq' => 'yearly'],
];

foreach (\$static_urls as \$url) {
    echo '  <url>' . PHP_EOL;
    echo '    <loc>https://shopvivaliz.com.br' . \$url['url'] . '</loc>' . PHP_EOL;
    echo '    <lastmod>' . date('Y-m-d') . '</lastmod>' . PHP_EOL;
    echo '    <changefreq>' . \$url['freq'] . '</changefreq>' . PHP_EOL;
    echo '    <priority>' . \$url['priority'] . '</priority>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}

// URLs dinâmicas de produtos (conectar ao seu banco de dados)
// Exemplo - Você precisa adaptar a query de produtos:
// \$conn = new mysqli(...);
// \$result = \$conn->query("SELECT id, slug, updated_at FROM produtos WHERE ativo = 1");
// while (\$row = \$result->fetch_assoc()) {
//     echo '  <url>' . PHP_EOL;
//     echo '    <loc>https://shopvivaliz.com.br/produto/' . \$row['slug'] . '</loc>' . PHP_EOL;
//     echo '    <lastmod>' . \$row['updated_at'] . '</lastmod>' . PHP_EOL;
//     echo '    <changefreq>daily</changefreq>' . PHP_EOL;
//     echo '    <priority>0.8</priority>' . PHP_EOL;
//     echo '  </url>' . PHP_EOL;
// }

echo '</urlset>' . PHP_EOL;
?>
"@

$sitemapPhp | Set-Content -Path "C:\site-shopvivaliz\public_html\sitemap.php" -Encoding UTF8
Write-Host "✅ sitemap.php (gerador dinâmico) criado"

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════════"
Write-Host "✅ MIGRAÇÃO CONCLUÍDA"
Write-Host "═══════════════════════════════════════════════════════════"
Write-Host ""
Write-Host "Próximas ações MANUAIS (não automatizáveis):"
Write-Host "  1. Copiar Google Analytics ID (G-XXXXX) e colar em layout.php"
Write-Host "  2. Copiar Open Graph image URL para novo domínio"
Write-Host "  3. Adaptar schema.org JSON-LD com dados da marca"
Write-Host "  4. Configurar .htaccess para rewrite sitemap.php → sitemap.xml"
Write-Host "  5. Submeter sitemap em Google Search Console"
Write-Host ""
Write-Host "Arquivo .htaccess Rewrite (adicionar se não existir):"
Write-Host "  RewriteRule ^sitemap\.xml$ sitemap.php [L]"
Write-Host ""
