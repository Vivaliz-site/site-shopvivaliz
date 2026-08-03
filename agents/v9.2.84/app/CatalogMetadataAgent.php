<?php

declare(strict_types=1);

final class ShopvivalizCatalogMetadataAgent
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root !== null ? rtrim($root, '/\\') : dirname(__DIR__, 3);
    }

    public function run(array $options = []): array
    {
        $startedAt = date('c');
        $cachePath = $this->root . '/storage/products-cache-ativos.json';
        $limit = max(1, min(500, (int)($options['catalog_metadata_limit'] ?? 100)));
        $actions = [];
        $errors = [];

        if (!is_file($cachePath) || !is_readable($cachePath)) {
            return $this->result(false, $startedAt, [], [['error' => 'catalog_cache_unavailable']]);
        }

        try {
            $raw = file_get_contents($cachePath);
            if (!is_string($raw) || $raw === '') {
                throw new RuntimeException('catalog_cache_empty');
            }
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('catalog_cache_invalid');
            }

            $sensitiveBefore = $this->sensitiveHash($payload);
            $items =& $this->itemsReference($payload);
            if (!is_array($items)) {
                throw new RuntimeException('catalog_items_not_found');
            }

            $changes = [];
            foreach ($items as $index => &$item) {
                if (count($changes) >= $limit) break;
                if (!is_array($item)) continue;

                $nameField = $this->firstField($item, ['descricao', 'nome', 'name']);
                if ($nameField === null) continue;
                $originalName = trim((string)$item[$nameField]);
                if ($originalName === '') continue;

                $normalizedName = $this->normalizeName($originalName);
                $currentCategory = $this->category($item);
                $targetCategory = $this->inferCategory($normalizedName);
                $itemChanges = [];

                if ($normalizedName !== $originalName) {
                    $item[$nameField] = $normalizedName;
                    $itemChanges['name'] = ['from' => $originalName, 'to' => $normalizedName];
                }

                if ($targetCategory !== null && $this->normalizeText($currentCategory) !== $this->normalizeText($targetCategory)) {
                    $this->setCategory($item, $targetCategory);
                    $itemChanges['category'] = ['from' => $currentCategory, 'to' => $targetCategory];
                }

                $descriptionField = $this->firstField($item, ['descricaoComplementar', 'descricao_complementar', 'description']);
                if ($descriptionField !== null) {
                    $originalDescription = (string)$item[$descriptionField];
                    $cleanDescription = $this->cleanDescription($originalDescription);
                    if ($cleanDescription !== $originalDescription) {
                        $item[$descriptionField] = $cleanDescription;
                        $itemChanges['description'] = ['status' => 'normalized'];
                    }
                }

                if ($itemChanges !== []) {
                    $changes[] = [
                        'index' => $index,
                        'sku' => trim((string)($item['sku'] ?? $item['codigo'] ?? $item['code'] ?? '')),
                        'changes' => $itemChanges,
                    ];
                }
            }
            unset($item);

            $sensitiveAfterMutation = $this->sensitiveHash($payload);
            if (!hash_equals($sensitiveBefore, $sensitiveAfterMutation)) {
                throw new RuntimeException('sensitive_fields_changed_in_memory');
            }

            if ($changes === []) {
                $actions[] = [
                    'action' => 'repair_catalog_metadata',
                    'status' => 'verified_no_action_needed',
                    'verified' => true,
                    'checked_items' => count($items),
                    'changed_items' => 0,
                    'sensitive_hash_before' => $sensitiveBefore,
                    'sensitive_hash_after' => $sensitiveAfterMutation,
                ];
                return $this->result(true, $startedAt, $actions, []);
            }

            $backup = $this->backup($cachePath, $raw);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $temporary = $cachePath . '.agent-' . getmypid() . '.tmp';
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                throw new RuntimeException('catalog_cache_write_failed');
            }
            if (!rename($temporary, $cachePath)) {
                @unlink($temporary);
                throw new RuntimeException('catalog_cache_atomic_replace_failed');
            }

            $readback = json_decode((string)file_get_contents($cachePath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($readback)) {
                throw new RuntimeException('catalog_cache_readback_invalid');
            }
            $sensitiveAfterWrite = $this->sensitiveHash($readback);
            if (!hash_equals($sensitiveBefore, $sensitiveAfterWrite)) {
                copy($backup, $cachePath);
                throw new RuntimeException('sensitive_fields_changed_after_write');
            }

            $actions[] = [
                'action' => 'repair_catalog_metadata',
                'status' => 'completed',
                'verified' => true,
                'checked_items' => count($items),
                'changed_items' => count($changes),
                'changes' => $changes,
                'backup' => basename($backup),
                'sensitive_hash_before' => $sensitiveBefore,
                'sensitive_hash_after' => $sensitiveAfterWrite,
                'cache_sha256' => hash_file('sha256', $cachePath),
            ];
        } catch (Throwable $e) {
            $errors[] = ['action' => 'repair_catalog_metadata', 'error' => $e->getMessage()];
        }

        return $this->result($errors === [], $startedAt, $actions, $errors);
    }

    private function result(bool $ok, string $startedAt, array $actions, array $errors): array
    {
        return [
            'ok' => $ok,
            'agent' => 'catalog_metadata',
            'execution_status' => $ok ? 'completed' : 'failed',
            'execution_completed' => $ok,
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'evidence_count' => count($actions),
            'changed_items' => array_sum(array_map(static fn(array $action): int => (int)($action['changed_items'] ?? 0), $actions)),
            'actions' => $actions,
            'errors' => $errors,
        ];
    }

    private function &itemsReference(array &$payload): mixed
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['itens', 'items', 'produtos', 'products'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            if (array_is_list($payload['data'])) {
                return $payload['data'];
            }
            foreach (['itens', 'items', 'produtos', 'products'] as $key) {
                if (isset($payload['data'][$key]) && is_array($payload['data'][$key])) {
                    return $payload['data'][$key];
                }
            }
        }

        $null = null;
        return $null;
    }

    private function firstField(array $item, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $item) && is_scalar($item[$field])) return $field;
        }
        return null;
    }

    private function category(array $item): string
    {
        if (is_array($item['categoria'] ?? null)) {
            return trim((string)($item['categoria']['nome'] ?? $item['categoria']['caminhoCompleto'] ?? ''));
        }
        if (isset($item['categoria']) && is_scalar($item['categoria'])) return trim((string)$item['categoria']);
        return trim((string)($item['category'] ?? ''));
    }

    private function setCategory(array &$item, string $category): void
    {
        if (is_array($item['categoria'] ?? null)) {
            $item['categoria']['nome'] = $category;
            if (array_key_exists('caminhoCompleto', $item['categoria'])) {
                $item['categoria']['caminhoCompleto'] = $category;
            }
            return;
        }
        if (array_key_exists('categoria', $item)) {
            $item['categoria'] = $category;
            return;
        }
        $item['category'] = $category;
    }

    private function inferCategory(string $name): ?string
    {
        $normalized = $this->normalizeText($name);
        $rules = [
            ['~\bassento\s+sanitari~u', 'Banheiro'],
            ['~\b(ducha|chuveiro)\b~u', 'Banheiro'],
            ['~\b(cachepot|floreira|vaso\s+decor|vaso\s+para\s+planta)\b~u', 'Vasos Decorativos'],
            ['~\b(caixa\s+(?:de\s+)?ferramentas|carrinho\s+fechado\s+ferramentas|bau\s+reto|sanfonada\s+\d+\s+gavetas)\b~u', 'Caixas de Ferramentas'],
            ['~\b(rodizio|roda\s+para\s+carrinho|roda\s+giratoria)\b~u', 'Rodas para Carrinhos e Móveis'],
            ['~\bcomedouro\b~u', 'Comedouros'],
            ['~\birrigador\b~u', 'Irrigação'],
            ['~\balicate\b~u', 'Alicates'],
            ['~\b(chave\s+teste|soquete)\b~u', 'Chaves e Soquetes'],
            ['~\b(tomada|plugue|interruptor|extensao\s+eletrica)\b~u', 'Elétrica'],
            ['~\b(trena|paquimetro|medidor)\b~u', 'Medição'],
            ['~\b(cadeado|luva\s+de\s+protecao|oculos\s+de\s+protecao)\b~u', 'Segurança'],
        ];
        foreach ($rules as [$pattern, $category]) {
            if (preg_match($pattern, $normalized) === 1) return $category;
        }
        return null;
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\b(Gatos?)v(\d+(?:[,.]\d+)?)\b/iu', '$1 $2', $name) ?? $name;
        $name = preg_replace('/\s{2,}/u', ' ', trim($name)) ?? trim($name);
        return $name;
    }

    private function cleanDescription(string $description): string
    {
        $clean = str_ireplace(
            ['Comprar Pronto!', 'Características adicionales:', 'Caracteristicas adicionales:'],
            ['', 'Características:', 'Características:'],
            $description
        );
        $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
        $value = strtr($value, $map);
        return preg_replace('/[^a-z0-9]+/u', ' ', $value) !== null
            ? trim((string)preg_replace('/[^a-z0-9]+/u', ' ', $value))
            : trim($value);
    }

    private function sensitiveHash(array $payload): string
    {
        $snapshot = [];
        $this->collectSensitive($payload, '', $snapshot);
        ksort($snapshot);
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function collectSensitive(mixed $value, string $path, array &$snapshot): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            $keyString = (string)$key;
            $childPath = $path === '' ? $keyString : $path . '.' . $keyString;
            $normalizedKey = $this->normalizeText($keyString);
            if (preg_match('/\b(price|pricing|preco|precos|stock|estoque|inventory|quantity|quantidade|saldo)\b/u', $normalizedKey) === 1) {
                $snapshot[$childPath] = $child;
                continue;
            }
            if (is_array($child)) $this->collectSensitive($child, $childPath, $snapshot);
        }
    }

    private function backup(string $cachePath, string $raw): string
    {
        $directory = $this->root . '/storage/agent-evidence/catalog-cache-backups';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('catalog_backup_directory_unavailable');
        }
        $path = $directory . '/catalog-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', $raw), 0, 12) . '.json';
        if (file_put_contents($path, $raw, LOCK_EX) === false) {
            throw new RuntimeException('catalog_backup_failed');
        }
        $backups = glob($directory . '/catalog-*.json') ?: [];
        usort($backups, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($backups, 5) as $old) @unlink($old);
        return $path;
    }
}
