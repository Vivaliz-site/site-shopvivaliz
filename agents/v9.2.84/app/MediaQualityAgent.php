<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/catalog-runtime.php';

final class ShopvivalizMediaQualityAgent
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root !== null ? rtrim($root, '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $options = []): array
    {
        $startedAt = gmdate('c');
        $actions = [];
        $errors = [];

        try {
            $products = svcr_products();
            if ($products === []) {
                throw new RuntimeException('storefront_catalog_empty');
            }

            $missingPrimary = 0;
            $invalidPrimary = 0;
            $genericAssets = 0;
            $withoutGallery = 0;
            $totalImages = 0;
            $findings = [];

            foreach ($products as $product) {
                if (!is_array($product)) continue;
                $sku = trim((string)($product['sku'] ?? ''));
                $primary = trim((string)($product['image_url'] ?? ''));
                $images = array_values(array_filter(
                    (array)($product['images'] ?? []),
                    static fn(mixed $value): bool => is_string($value) && trim($value) !== ''
                ));
                $totalImages += count($images);

                $reasons = [];
                if ($primary === '') {
                    $missingPrimary++;
                    $reasons[] = 'primary_image_missing';
                } elseif (preg_match('~^https://~i', $primary) !== 1) {
                    $invalidPrimary++;
                    $reasons[] = 'primary_image_not_https';
                }

                $lower = strtolower($primary);
                if ($lower !== '' && (
                    str_contains($lower, 'placeholder')
                    || str_contains($lower, 'default')
                    || str_contains($lower, 'example.com')
                    || str_contains($lower, 'unsplash.com')
                )) {
                    $genericAssets++;
                    $reasons[] = 'generic_or_external_placeholder';
                }

                if (count($images) === 0) {
                    $withoutGallery++;
                    $reasons[] = 'gallery_empty';
                }

                if ($reasons !== [] && count($findings) < 100) {
                    $findings[] = ['sku' => $sku, 'reasons' => $reasons];
                }
            }

            $summary = [
                'products_checked' => count($products),
                'images_checked' => $totalImages,
                'missing_primary' => $missingPrimary,
                'invalid_primary' => $invalidPrimary,
                'generic_assets' => $genericAssets,
                'without_gallery' => $withoutGallery,
                'findings_count' => $missingPrimary + $invalidPrimary + $genericAssets + $withoutGallery,
            ];

            $actions[] = [
                'action' => 'audit_storefront_media_quality',
                'status' => 'completed',
                'verified' => true,
                'source' => 'svcr_products',
                'summary' => $summary,
                'findings' => $findings,
                'changed_items' => 0,
            ];
        } catch (Throwable $error) {
            $errors[] = ['action' => 'audit_storefront_media_quality', 'error' => $error->getMessage()];
        }

        $result = $this->result($errors === [], $startedAt, $actions, $errors);
        try {
            $this->persist($result);
        } catch (Throwable $error) {
            $result['ok'] = false;
            $result['execution_status'] = 'failed';
            $result['execution_completed'] = false;
            $result['errors'][] = ['action' => 'persist_media_quality_evidence', 'error' => $error->getMessage()];
        }
        return $result;
    }

    private function result(bool $ok, string $startedAt, array $actions, array $errors): array
    {
        return [
            'ok' => $ok,
            'agent' => 'media_quality',
            'execution_status' => $ok ? 'completed' : 'failed',
            'execution_completed' => $ok,
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'actions' => $actions,
            'changed_items' => 0,
            'errors' => $errors,
        ];
    }

    private function persist(array $result): void
    {
        $directory = $this->root . '/storage/agent-evidence';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('media_quality_evidence_directory_unavailable');
        }
        $path = $directory . '/latest-media-quality.json';
        $temporary = $path . '.tmp-' . getmypid();
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('media_quality_evidence_write_failed');
        }
        $readback = json_decode((string)file_get_contents($path), true);
        if (!is_array($readback) || ($readback['agent'] ?? '') !== 'media_quality') {
            throw new RuntimeException('media_quality_evidence_readback_failed');
        }
    }
}
