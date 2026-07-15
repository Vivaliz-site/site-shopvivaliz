<?php
declare(strict_types=1);

/**
 * Performance Optimization Utilities
 *
 * Provides helpers for caching, lazy loading, asset optimization, etc.
 */

/**
 * Cache helper using file-based storage or memory
 */
class SimpleCache
{
    private string $cacheDir;
    private int $defaultTTL;

    public function __construct(?string $cacheDir = null, int $defaultTTL = 3600)
    {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/sv-cache';
        $this->defaultTTL = $defaultTTL;

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get cached value
     */
    public function get(string $key): mixed
    {
        $path = $this->getPath($key);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }

        // Check if expired
        if (isset($data['expires']) && time() > $data['expires']) {
            @unlink($path);
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Set cached value
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTTL;
        $path = $this->getPath($key);

        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time(),
        ];

        $json = json_encode($data);
        if ($json === false) {
            return false;
        }

        return file_put_contents($path, $json, LOCK_EX) !== false;
    }

    /**
     * Delete cached value
     */
    public function delete(string $key): bool
    {
        $path = $this->getPath($key);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    /**
     * Clear all cache
     */
    public function flush(): bool
    {
        $files = @glob("{$this->cacheDir}/*.json");
        if (!is_array($files)) {
            return true;
        }

        foreach ($files as $file) {
            @unlink($file);
        }

        return true;
    }

    /**
     * Generate cache file path
     */
    private function getPath(string $key): string
    {
        $hash = hash('sha256', $key);
        return $this->cacheDir . '/' . $hash . '.json';
    }
}

/**
 * Generate responsive image tag with lazy loading
 *
 * @param string $src Main image source
 * @param string $alt Alt text (required)
 * @param array $srcset Additional image sizes
 * @param array $attributes HTML attributes
 * @return string HTML image tag
 */
function lazy_image(
    string $src,
    string $alt,
    array $srcset = [],
    array $attributes = []
): string {
    $attrs = array_merge([
        'class' => 'lazy-image',
        'loading' => 'lazy',
        'decoding' => 'async',
    ], $attributes);

    // Build attributes string
    $attrStr = '';
    foreach ($attrs as $name => $value) {
        if (is_string($value)) {
            $attrStr .= ' ' . htmlspecialchars($name) . '="' . htmlspecialchars($value) . '"';
        }
    }

    // Build srcset if provided
    $srcsetStr = '';
    if (!empty($srcset)) {
        $sizes = array_map(
            fn($size, $url) => htmlspecialchars($url) . ' ' . htmlspecialchars($size),
            array_keys($srcset),
            array_values($srcset)
        );
        $srcsetStr = ' srcset="' . implode(', ', $sizes) . '"';
    }

    return sprintf(
        '<img src="%s" alt="%s"%s%s>',
        htmlspecialchars($src),
        htmlspecialchars($alt),
        $srcsetStr,
        $attrStr
    );
}

/**
 * Convert image to WebP format suggestion
 *
 * Checks if browser supports WebP and returns appropriate image URL
 *
 * @param string $originalUrl Original image URL
 * @param string $webpUrl WebP version URL
 * @return string Most appropriate image URL
 */
function get_optimized_image(string $originalUrl, string $webpUrl = ''): string
{
    // Check browser support from HTTP headers
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');

    if (strpos($accept, 'image/webp') !== false && $webpUrl !== '') {
        return $webpUrl;
    }

    return $originalUrl;
}

/**
 * Generate srcset for responsive images
 *
 * @param string $basePath Base path to image (without extension)
 * @param array $widths Array of widths to generate
 * @param string $extension File extension (.jpg, .png, etc.)
 * @return string srcset attribute value
 */
function generate_srcset(string $basePath, array $widths = [], string $extension = '.jpg'): string
{
    if (empty($widths)) {
        $widths = [320, 640, 960, 1280, 1920];
    }

    $sizes = array_map(
        fn($w) => $basePath . '-' . $w . $extension . ' ' . $w . 'w',
        $widths
    );

    return implode(', ', $sizes);
}

/**
 * Enable gzip output compression
 *
 * Should be called early in request, before any output
 */
function enable_output_compression(): void
{
    if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', '1');
    }
}

/**
 * Set appropriate cache headers for static assets
 *
 * @param int $maxAge Cache duration in seconds
 * @param string $type Asset type: 'image', 'font', 'script', 'style'
 */
function set_asset_cache_headers(int $maxAge = 31536000, string $type = 'image'): void
{
    header("Cache-Control: public, max-age={$maxAge}, immutable");

    // Set appropriate content type for fonts
    if ($type === 'font') {
        header('Access-Control-Allow-Origin: *');
    }
}

/**
 * Create inline CSS from file
 *
 * Useful for critical CSS in head
 *
 * @param string $filePath Path to CSS file
 * @return string Inline CSS
 */
function inline_critical_css(string $filePath): string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return '';
    }

    $css = file_get_contents($filePath);
    if ($css === false) {
        return '';
    }

    // Minify CSS
    $css = preg_replace('/\s+/', ' ', $css) ?? $css;
    $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css) ?? $css;

    return trim($css);
}

/**
 * Check if request is for an API endpoint
 *
 * @return bool True if request is for API
 */
function is_api_request(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return strpos($path, '/api') !== false;
}

/**
 * Get frontend performance metrics
 *
 * Should be called at end of page load
 *
 * @return array Performance metrics
 */
function get_performance_metrics(): array
{
    global $sv_start_time;

    $endTime = microtime(true);
    $duration = $endTime - ($sv_start_time ?? $endTime);

    return [
        'page_load_time' => round($duration * 1000, 2),
        'memory_usage' => memory_get_usage(),
        'memory_peak' => memory_get_peak_usage(),
        'database_queries' => $GLOBALS['db_query_count'] ?? 0,
    ];
}

/**
 * Global performance timer start
 * Place at top of index.php: $GLOBALS['sv_start_time'] = microtime(true);
 */
if (!isset($GLOBALS['sv_start_time'])) {
    $GLOBALS['sv_start_time'] = microtime(true);
}
