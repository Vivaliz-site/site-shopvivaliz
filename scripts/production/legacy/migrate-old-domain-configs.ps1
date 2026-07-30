# Script de Migracao: ecommerceolist.com.br -> shopvivaliz.com.br
# Data: 2026-07-19

$OLD_DOMAIN = "ecommerceolist.com.br"
$NEW_DOMAIN = "shopvivaliz.com.br"
$OLD_URL = "https://$OLD_DOMAIN"
$NEW_URL = "https://$NEW_DOMAIN"

Write-Host "Iniciando migracao de configs: $OLD_DOMAIN -> $NEW_DOMAIN"
Write-Host ""

# Passo 1: Baixar arquivo robots.txt do site antigo
Write-Host "[1/4] Processando robots.txt..."
try {
    $robotsOld = Invoke-WebRequest -Uri "$OLD_URL/robots.txt" -ErrorAction Stop
    $robotsContent = $robotsOld.Content -replace $OLD_DOMAIN, $NEW_DOMAIN
    $robotsContent | Set-Content -Path "C:\site-shopvivaliz\public_html\robots.txt" -Encoding UTF8
    Write-Host "OK: robots.txt migrado"
} catch {
    Write-Host "AVISO: Nao foi possivel baixar robots.txt"
}

# Passo 2: Criar sitemap.xml base
Write-Host "[2/4] Criando sitemap.xml..."

$sitemapXML = '<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://shopvivaliz.com.br/</loc>
    <lastmod>' + (Get-Date -Format "yyyy-MM-dd") + '</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://shopvivaliz.com.br/sobre</loc>
    <lastmod>' + (Get-Date -Format "yyyy-MM-dd") + '</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>
  <url>
    <loc>https://shopvivaliz.com.br/contato</loc>
    <lastmod>' + (Get-Date -Format "yyyy-MM-dd") + '</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>
  <url>
    <loc>https://shopvivaliz.com.br/politica-privacidade</loc>
    <lastmod>' + (Get-Date -Format "yyyy-MM-dd") + '</lastmod>
    <changefreq>yearly</changefreq>
    <priority>0.5</priority>
  </url>
</urlset>'

$sitemapXML | Set-Content -Path "C:\site-shopvivaliz\public_html\sitemap.xml" -Encoding UTF8
Write-Host "OK: sitemap.xml criado"

# Passo 3: Atualizar referencias de URL em arquivos PHP
Write-Host "[3/4] Atualizando referencias de URL..."
try {
    $files = @(
        "C:\site-shopvivaliz\includes\layout.php",
        "C:\site-shopvivaliz\includes\header.php",
        "C:\site-shopvivaliz\includes\footer.php"
    )

    foreach ($file in $files) {
        if (Test-Path $file) {
            $content = Get-Content $file -Raw
            $content = $content -replace $OLD_DOMAIN, $NEW_DOMAIN
            $content = $content -replace $OLD_URL, $NEW_URL
            Set-Content -Path $file -Value $content -Encoding UTF8
            Write-Host "  OK: $file atualizado"
        }
    }
} catch {
    Write-Host "AVISO: Erro ao atualizar arquivos PHP"
}

# Passo 4: Criar .htaccess com regras de SEO
Write-Host "[4/4] Atualizando .htaccess..."

$htaccess = 'RewriteEngine On
RewriteBase /

# HTTPS e WWW normalization
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

RewriteCond %{HTTP_HOST} ^www\.(.*)$ [NC]
RewriteRule ^(.*)$ https://%1%{REQUEST_URI} [L,R=301]

# Sitemap rewrite
RewriteRule ^sitemap\.xml$ sitemap.php [L]

# Remove .php extension
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^\.]+)$ $1.php [NC,L]

# Security headers
<FilesMatch "\.php$">
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
</FilesMatch>

# Cache control
<FilesMatch "\.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2)$">
    Header set Cache-Control "max-age=31536000, public"
</FilesMatch>'

$htaccess | Set-Content -Path "C:\site-shopvivaliz\public_html\.htaccess" -Encoding UTF8
Write-Host "OK: .htaccess criado"

Write-Host ""
Write-Host "========================================"
Write-Host "MIGRACAO CONCLUIDA"
Write-Host "========================================"
Write-Host ""
Write-Host "Proximas acoes:"
Write-Host "  1. Commitar alteracoes: git add . && git commit"
Write-Host "  2. Fazer push: git push origin main"
Write-Host "  3. Submeter sitemap no Google Search Console"
Write-Host ""
