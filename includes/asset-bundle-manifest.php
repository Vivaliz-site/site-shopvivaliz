<?php
declare(strict_types=1);

/**
 * Lista única de fontes usada tanto para emitir a tag <link> da home quanto
 * para servir o bundle concatenado em css/home-bundle.php. Mantém a MESMA
 * ordem em que os arquivos eram carregados individualmente antes desta
 * consolidação (2026-08-05) — a ordem importa porque várias camadas usam
 * !important para vencer a cascata da camada anterior. Não reordenar sem
 * revisar visualmente a home antes e depois.
 *
 * Cada entrada é relativa à raiz do projeto. Entradas marcadas como
 * "optional" só existem em algumas releases (ex: hotfixes pontuais) e são
 * puladas silenciosamente se o arquivo não existir, replicando o
 * comportamento anterior em includes/load-custom-css.php.
 */
function sv_home_css_bundle_manifest(): array
{
    // IMPORTANTE: este bundle só pode conter arquivos que já renderizavam
    // ANTES do bloco de CSS customizado do admin (storage/css-custom/*.css)
    // em includes/load-custom-css.php. Os arquivos visual-polish-v5/v6/
    // v6-hotfix/visual-audit-v1/accessibility-hardening-v1/cls-stability-v1
    // são intencionalmente carregados DEPOIS do CSS do admin para vencer a
    // cascata ("vencer conflitos visuais medidos") — não incluí-los aqui,
    // ou a ordem de override quebra silenciosamente. Ver load-custom-css.php.
    return [
        ['path' => 'css/shopvivaliz-core-consolidated.css', 'optional' => false],
        ['path' => 'css/shopvivaliz-premium-consolidated.css', 'optional' => false],
        ['path' => 'css/shopvivaliz-inline-to-classes.css', 'optional' => false],
        ['path' => 'css/shopvivaliz-webp-optimization.css', 'optional' => false],
        ['path' => 'css/zoom-responsive.css', 'optional' => false],
        ['path' => 'css/layout-polish-v1.css', 'optional' => false],
        ['path' => 'css/home-mobile-compact.css', 'optional' => false],
        ['path' => 'css/home-mobile-final.css', 'optional' => false],
        ['path' => 'css/visual-polish-v4.css', 'optional' => false],
        ['path' => 'css/home-mobile-corrections-v1.css', 'optional' => false],
    ];
}

/**
 * Resolve o manifesto para caminhos absolutos existentes, na ordem definida,
 * pulando entradas opcionais ausentes. Usado tanto para calcular a versão
 * (hash) quanto para efetivamente concatenar o conteúdo.
 *
 * @return array<int, mixed> caminhos absolutos (string) em ordem, ou
 *   ['__missing__' => 'css/x.css'] para obrigatórios ausentes (não deveria
 *   acontecer em produção, mas evita fatal error se acontecer)
 */
function sv_resolve_home_css_bundle_files(string $projectRoot): array
{
    $resolved = [];
    foreach (sv_home_css_bundle_manifest() as $entry) {
        $absolute = $projectRoot . '/' . $entry['path'];
        if (is_file($absolute) && is_readable($absolute)) {
            $resolved[] = $absolute;
        } elseif (!$entry['optional']) {
            $resolved[] = ['__missing__' => $entry['path']];
        }
    }

    return $resolved;
}

/**
 * Versão determinística do bundle: derivada do maior filemtime entre todos
 * os arquivos presentes E do próprio gerador. Assim uma mudança na forma de
 * servir/minificar o bundle invalida clientes com Cache-Control immutable,
 * mesmo quando os CSS-fontes não foram editados.
 */
function sv_home_css_bundle_version(string $projectRoot): string
{
    $maxMtime = 0;
    foreach (sv_resolve_home_css_bundle_files($projectRoot) as $file) {
        if (is_string($file) && is_file($file)) {
            $maxMtime = max($maxMtime, (int) filemtime($file));
        }
    }

    $generator = $projectRoot . '/css/home-bundle.php';
    if (is_file($generator)) {
        $maxMtime = max($maxMtime, (int) filemtime($generator));
    }

    return (string) $maxMtime;
}
