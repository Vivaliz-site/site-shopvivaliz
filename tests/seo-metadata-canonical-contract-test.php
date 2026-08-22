<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helper = $root . '/includes/blog-seo.php';

if (!is_file($helper)) {
    fwrite(STDERR, "FALHOU: helper central de SEO do blog precisa existir\n");
    exit(1);
}

require_once $helper;

function sv_seo_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

$title = sv_blog_seo_title('Como medir espaços antes de comprar acessórios para casa | ShopVivaliz');
$description = sv_blog_seo_description('Guia curto.', 'Aprenda a medir espaços antes de comprar acessórios para casa e evite incompatibilidades.', 'Como medir espaços antes de comprar acessórios para casa');

sv_seo_assert(mb_strlen($title, 'UTF-8') <= 60, 'titulo do blog deve ter no maximo 60 caracteres');
sv_seo_assert(mb_strlen($description, 'UTF-8') >= 110, 'descricao do blog deve ter pelo menos 110 caracteres');
sv_seo_assert(mb_strlen($description, 'UTF-8') <= 155, 'descricao do blog deve ter no maximo 155 caracteres');
sv_seo_assert(sv_blog_seo_origin() === 'https://shopvivaliz.com.br', 'origem canonica do blog deve ser HTTPS oficial');

$reviews = file_get_contents($root . '/avaliacoes.php') ?: '';
sv_seo_assert(str_contains($reviews, 'rel="canonical"'), 'pagina de avaliacoes precisa de canonical');

fwrite(STDOUT, "seo-metadata-canonical-contract: ok\n");
