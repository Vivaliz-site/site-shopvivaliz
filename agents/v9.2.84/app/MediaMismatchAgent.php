<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/catalog-runtime.php';

final class ShopvivalizMediaMismatchAgent
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root !== null ? rtrim($root, '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $options = []): array
    {
        $startedAt = gmdate('c');
        $limit = max(10, min(500, (int)($options['media_mismatch_limit'] ?? 200)));
        $actions = [];
        $errors = [];

        try {
            $products = array_slice(svcr_products(), 0, $limit);
            if ($products === []) {
                throw new RuntimeException('storefront_catalog_empty');
            }

            $flagged = [];
            $imageOwners = [];
            foreach ($products as $product) {
                if (!is_array($product)) continue;
                $sku = trim((string)($product['sku'] ?? ''));
                $name = trim((string)($product['name'] ?? ''));
                $category = trim((string)($product['category'] ?? ''));
                $primary = trim((string)($product['image_url'] ?? ''));
                $score = 0;
                $reasons = [];

                if ($primary === '') {
                    $score += 100;
                    $reasons[] = 'primary_image_missing';
                } else {
                    $normalizedImage = strtolower(preg_replace('~[?#].*$~', '', $primary) ?? $primary);
                    if (isset($imageOwners[$normalizedImage]) && $imageOwners[$normalizedImage] !== $sku) {
                        $score += 55;
                        $reasons[] = 'primary_image_reused_by_other_sku';
                    } else {
                        $imageOwners[$normalizedImage] = $sku;
                    }

                    $tokens = $this->tokens($name . ' ' . $category . ' ' . $sku);
                    if (count($tokens) >= 2) {
                        $hits = 0;
                        foreach ($tokens as $token) {
                            if (str_contains($normalizedImage, $token)) $hits++;
                        }
                        if ($hits === 0) {
                            $score += 25;
                            $reasons[] = 'low_product_image_name_match';
                        }
                    }

                    if (
                        str_contains($normalizedImage, 'placeholder')
                        || str_contains($normalizedImage, 'default')
                        || str_contains($normalizedImage, 'example.com')
                        || str_contains($normalizedImage, 'unsplash.com')
                    ) {
                        $score += 60;
                        $reasons[] = 'generic_or_external_placeholder';
                    }
                }

                if ($score >= 35) {
                    $flagged[] = [
                        'sku' => $sku,
                        'score' => min(100, $score),
                        'reasons' => array_values(array_unique($reasons)),
                    ];
                }
            }

            $actions[] = [
                'action' => 'audit_storefront_media_mismatch',
                'status' => 'completed',
                'verified' => true,
                'source' => 'svcr_products',
                'checked_items' => count($products),
                'flagged_items' => count($flagged),
                'items' => array_slice($flagged, 0, 100),
                'changed_items' => 0,
            ];
        } catch (Throwable $error) {
            $errors[] = ['action' => 'audit_storefront_media_mismatch', 'error' => $error->getMessage()];
        }

        $result = $this->result($errors === [], $startedAt, $actions, $errors);
        try {
            $this->persist($result);
        } catch (Throwable $error) {
            $result['ok'] = false;
            $result['execution_status'] = 'failed';
            $result['execution_completed'] = false;
            $result['errors'][] = ['action' => 'persist_media_mismatch_evidence', 'error' => $error->getMessage()];
        }
        return $result;
    }

    private function tokens(string $text): array
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c'];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
        $raw = preg_split('/\s+/', trim($text)) ?: [];
        $stop = ['para','com','sem','das','dos','kit','und','azul','preto','branco','rosa','produto','shopvivaliz'];
        return array_values(array_filter(
            array_unique($raw),
            static fn(string $token): bool => strlen($token) >= 4 && !in_array($token, $stop, true)
        ));
    }

    private function result(bool $ok, string $startedAt, array $actions, array $errors): array
    {
        return [
            'ok' => $ok,
            'agent' => 'media_mismatch',
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
            throw new RuntimeException('media_mismatch_evidence_directory_unavailable');
        }
        $path = $directory . '/latest-media-mismatch.json';
        $temporary = $path . '.tmp-' . getmypid();
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('media_mismatch_evidence_write_failed');
        }
        $readback = json_decode((string)file_get_contents($path), true);
        if (!is_array($readback) || ($readback['agent'] ?? '') !== 'media_mismatch') {
            throw new RuntimeException('media_mismatch_evidence_readback_failed');
        }
    }
}
