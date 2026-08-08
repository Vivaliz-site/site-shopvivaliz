<?php
/**
 * Sincroniza imagens Olist com o catálogo local usando identidade estrita.
 *
 * Regras de segurança:
 * 1. Olist ID é a identidade primária.
 * 2. SKU só pode ser fallback quando existir UMA única correspondência local.
 * 3. Se Olist ID e SKU apontarem para produtos diferentes, o item é rejeitado.
 * 4. Depois de resolvido, qualquer UPDATE usa apenas o id local exato.
 * 5. Imagens são deduplicadas por hash e associadas ao product_local_id exato.
 */
header('Content-Type: application/json; charset=utf-8');

log_msg('=== SINCRONIZAR IMAGENS PARA SITE / STRICT IDENTITY ===');

try {
    $mappingFile = __DIR__ . '/../logs/olist-images-mapping.json';
    if (!is_file($mappingFile)) {
        exit_error('Mapping não encontrado. Execute /olist/download-images.php primeiro');
    }

    $mappingRaw = file_get_contents($mappingFile);
    $mapping = json_decode((string) $mappingRaw, true);
    if (!is_array($mapping)) {
        exit_error('Mapping de imagens inválido ou corrompido');
    }

    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance();

    $stats = [
        'mapping_total' => count($mapping),
        'products_updated' => 0,
        'images_inserted' => 0,
        'images_updated' => 0,
        'skipped_without_images' => 0,
        'rejected_identity' => 0,
        'errors' => 0,
    ];

    foreach ($mapping as $mappingOlistId => $data) {
        if (!is_array($data)) {
            $stats['errors']++;
            continue;
        }

        $olistId = normalize_positive_int($data['olist_id'] ?? $data['id'] ?? $mappingOlistId);
        $sku = trim((string) ($data['sku'] ?? ''));
        $name = trim((string) ($data['nome'] ?? $data['name'] ?? 'Produto'));
        $images = array_values(array_filter((array) ($data['imagens'] ?? $data['images'] ?? []), 'is_array'));

        if ($images === []) {
            $stats['skipped_without_images']++;
            continue;
        }

        $product = resolve_product_identity($db, $olistId, $sku);
        if ($product === null) {
            $stats['rejected_identity']++;
            log_msg("[REJEITADO] {$name} | olist_id={$olistId} sku={$sku} | identidade ausente/ambígua/incompatível");
            continue;
        }

        $usableImages = [];
        foreach ($images as $idx => $image) {
            $ref = trim((string) ($image['local_path'] ?? $image['url'] ?? $image['image_url'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $usableImages[] = [
                'ref' => $ref,
                'position' => count($usableImages) + 1,
                'is_primary' => count($usableImages) === 0 ? 1 : 0,
                'raw' => $image,
            ];
        }

        if ($usableImages === []) {
            $stats['skipped_without_images']++;
            continue;
        }

        $db->begin_transaction();
        try {
            $primary = $usableImages[0]['ref'];
            $count = count($usableImages);
            $localId = (int) $product['id'];

            $update = $db->prepare(
                "UPDATE olist_products
                 SET primary_image_url = ?, images_count = ?, last_image_sync_at = NOW(), image_sync_status = 'linked_strict'
                 WHERE id = ?"
            );
            if (!$update) {
                throw new RuntimeException('Falha ao preparar UPDATE de olist_products: ' . $db->error);
            }
            $update->bind_param('sii', $primary, $count, $localId);
            if (!$update->execute()) {
                throw new RuntimeException('Falha ao atualizar produto: ' . $update->error);
            }
            $update->close();

            foreach ($usableImages as $image) {
                $result = upsert_product_image(
                    $db,
                    $localId,
                    (int) $product['olist_id'],
                    (string) $product['sku'],
                    $image['ref'],
                    (int) $image['position'],
                    (int) $image['is_primary'],
                    $image['raw']
                );
                $stats[$result === 'inserted' ? 'images_inserted' : 'images_updated']++;
            }

            $db->commit();
            $stats['products_updated']++;
            log_msg("[OK] {$name} | local_id={$localId} olist_id={$product['olist_id']} sku={$product['sku']} | {$count} imagens");
        } catch (Throwable $e) {
            $db->rollback();
            $stats['errors']++;
            log_msg("[ERRO] {$name}: " . $e->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'mode' => 'strict_identity',
        'stats' => $stats,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    log_msg('EXCEPTION: ' . $e->getMessage());
    exit_error('Erro: ' . $e->getMessage());
}

function resolve_product_identity(mysqli $db, ?int $olistId, string $sku): ?array
{
    $byOlist = null;

    if ($olistId !== null) {
        $stmt = $db->prepare('SELECT id, olist_id, sku FROM olist_products WHERE olist_id = ? LIMIT 2');
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar busca por Olist ID: ' . $db->error);
        }
        $stmt->bind_param('i', $olistId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        if (count($rows) > 1) {
            return null;
        }
        if (count($rows) === 1) {
            $byOlist = $rows[0];
            $dbSku = trim((string) ($byOlist['sku'] ?? ''));
            if ($sku !== '' && $dbSku !== '' && strcasecmp($sku, $dbSku) !== 0) {
                return null;
            }
            return $byOlist;
        }
    }

    // Fallback por SKU apenas se Olist ID não encontrou e o SKU for unívoco.
    if ($sku === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT id, olist_id, sku FROM olist_products WHERE sku = ? LIMIT 2');
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar busca por SKU: ' . $db->error);
    }
    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    if (count($rows) !== 1) {
        return null;
    }

    // Se o mapping informou Olist ID, fallback por SKU só é aceito quando o banco
    // não possui um ID conflitante para o registro encontrado.
    $rowOlistId = normalize_positive_int($rows[0]['olist_id'] ?? null);
    if ($olistId !== null && $rowOlistId !== null && $olistId !== $rowOlistId) {
        return null;
    }

    return $rows[0];
}

function upsert_product_image(
    mysqli $db,
    int $productLocalId,
    int $olistProductId,
    string $sku,
    string $imageRef,
    int $position,
    int $isPrimary,
    array $raw
): string {
    $hash = hash('sha256', normalize_image_identity($imageRef));

    $find = $db->prepare('SELECT id FROM olist_product_images WHERE product_local_id = ? AND image_hash = ? LIMIT 1');
    if (!$find) {
        throw new RuntimeException('Falha ao preparar busca de imagem: ' . $db->error);
    }
    $find->bind_param('is', $productLocalId, $hash);
    $find->execute();
    $existing = $find->get_result()?->fetch_assoc();
    $find->close();

    $rawJson = json_encode([
        'source' => 'olist_mapping_strict',
        'original' => $raw,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $external = preg_match('#^https?://#i', $imageRef) === 1 ? 1 : 0;

    if (is_array($existing)) {
        $id = (int) $existing['id'];
        $stmt = $db->prepare(
            "UPDATE olist_product_images
             SET olist_product_id = ?, sku = ?, image_url = ?, position = ?, is_primary = ?,
                 source = 'olist_mapping_strict', externo = ?, status = 'active', raw_json = ?, updated_at = NOW()
             WHERE id = ? AND product_local_id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar UPDATE de imagem: ' . $db->error);
        }
        $stmt->bind_param('issiiisii', $olistProductId, $sku, $imageRef, $position, $isPrimary, $external, $rawJson, $id, $productLocalId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Falha ao atualizar imagem: ' . $stmt->error);
        }
        $stmt->close();
        return 'updated';
    }

    $stmt = $db->prepare(
        "INSERT INTO olist_product_images
         (product_local_id, olist_product_id, sku, image_url, image_hash, position, is_primary, source, externo, status, raw_json, imported_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'olist_mapping_strict', ?, 'active', ?, NOW(), NOW())"
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar INSERT de imagem: ' . $db->error);
    }
    $stmt->bind_param('iisssiiis', $productLocalId, $olistProductId, $sku, $imageRef, $hash, $position, $isPrimary, $external, $rawJson);
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao inserir imagem: ' . $stmt->error);
    }
    $stmt->close();
    return 'inserted';
}

function normalize_image_identity(string $ref): string
{
    $parts = parse_url($ref);
    if (!is_array($parts) || empty($parts['host'])) {
        return str_replace('\\', '/', $ref);
    }

    $host = strtolower((string) $parts['host']);
    $path = (string) ($parts['path'] ?? '');
    // CDN VNDA: tamanho derivado não deve criar uma "nova" identidade da mesma foto.
    $path = preg_replace('#^/(?:150x|300x|600x)/#', '/', $path);
    return $host . $path;
}

function normalize_positive_int($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    $value = (int) $value;
    return $value > 0 ? $value : null;
}

function log_msg(string $msg): void
{
    $logFile = __DIR__ . '/../logs/olist-sync-images-to-site.log';
    @mkdir(dirname($logFile), 0755, true);
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND);
    error_log('[Sync] ' . $msg);
}

function exit_error(string $msg): never
{
    log_msg('ERRO: ' . $msg);
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => $msg], JSON_UNESCAPED_UNICODE);
    exit(1);
}
