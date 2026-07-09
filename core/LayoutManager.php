<?php
declare(strict_types=1);

namespace Core;

/**
 * Gerenciador de layouts no banco de dados
 */
class LayoutManager {
    private \PDO $db;
    private int $userId = 0;

    public function __construct(\PDO $db, int $userId = 0) {
        $this->db = $db;
        $this->userId = $userId;
    }

    /**
     * Salvar ou atualizar um layout
     */
    public function save(
        string $pageId,
        array $layoutConfig,
        string $pageType = 'homepage',
        string $viewport = 'both',
        bool $publish = false
    ): bool {
        $configJson = json_encode($layoutConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $metaTitle = $layoutConfig['meta']['title'] ?? '';
        $metaDesc = $layoutConfig['meta']['description'] ?? '';
        $metaOg = $layoutConfig['meta']['og_image'] ?? '';
        $publishedAt = $publish ? date('Y-m-d H:i:s') : null;

        try {
            // Verificar se existe
            $existing = $this->getByPageId($pageId);

            if ($existing) {
                // Atualizar
                $stmt = $this->db->prepare(
                    'UPDATE page_layouts
                    SET config = ?, page_type = ?, viewport = ?, meta_title = ?,
                        meta_description = ?, meta_og_image = ?, published = ?,
                        published_at = ?, updated_by = ?, updated_at = NOW()
                    WHERE page_id = ?'
                );

                return $stmt->execute([
                    $configJson,
                    $pageType,
                    $viewport,
                    $metaTitle,
                    $metaDesc,
                    $metaOg,
                    $publish ? 1 : 0,
                    $publishedAt,
                    $this->userId,
                    $pageId
                ]);
            } else {
                // Inserir novo
                $stmt = $this->db->prepare(
                    'INSERT INTO page_layouts
                    (page_id, page_type, viewport, config, meta_title, meta_description,
                     meta_og_image, published, published_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $success = $stmt->execute([
                    $pageId,
                    $pageType,
                    $viewport,
                    $configJson,
                    $metaTitle,
                    $metaDesc,
                    $metaOg,
                    $publish ? 1 : 0,
                    $publishedAt,
                    $this->userId,
                    $this->userId
                ]);

                // Se inseriu, criar primeira entrada no histórico
                if ($success) {
                    $layoutId = (int)$this->db->lastInsertId();
                    $this->createHistorySnapshot($layoutId, 1, 'Initial version');
                }

                return $success;
            }
        } catch (\PDOException $e) {
            error_log("LayoutManager::save error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obter layout por page_id
     */
    public function getByPageId(string $pageId): ?array {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM page_layouts WHERE page_id = ? LIMIT 1'
            );
            $stmt->execute([$pageId]);

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$result) {
                return null;
            }

            // Decodificar JSON
            $result['config'] = json_decode($result['config'], true) ?? [];
            return $result;
        } catch (\PDOException $e) {
            error_log("LayoutManager::getByPageId error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obter todos os layouts
     */
    public function getAll(int $limit = 100, int $offset = 0): array {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, page_id, page_type, viewport, published, updated_at, created_at
                FROM page_layouts
                ORDER BY updated_at DESC
                LIMIT ? OFFSET ?'
            );
            $stmt->execute([$limit, $offset]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log("LayoutManager::getAll error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Publicar/Despublicar layout
     */
    public function publish(string $pageId, bool $publish = true): bool {
        try {
            $publishedAt = $publish ? date('Y-m-d H:i:s') : null;

            $stmt = $this->db->prepare(
                'UPDATE page_layouts
                SET published = ?, published_at = ?, updated_by = ?, updated_at = NOW()
                WHERE page_id = ?'
            );

            return $stmt->execute([
                $publish ? 1 : 0,
                $publishedAt,
                $this->userId,
                $pageId
            ]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::publish error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Duplicar um layout
     */
    public function duplicate(string $sourcePageId, string $newPageId): bool {
        try {
            $source = $this->getByPageId($sourcePageId);
            if (!$source) {
                return false;
            }

            return $this->save(
                $newPageId,
                $source['config'],
                $source['page_type'],
                $source['viewport'],
                false
            );
        } catch (\Exception $e) {
            error_log("LayoutManager::duplicate error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletar layout
     */
    public function delete(string $pageId): bool {
        try {
            // Histórico será deletado automaticamente por FK CASCADE
            $stmt = $this->db->prepare('DELETE FROM page_layouts WHERE page_id = ?');
            return $stmt->execute([$pageId]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Criar snapshot no histórico
     */
    private function createHistorySnapshot(int $layoutId, int $version, string $summary = ''): bool {
        try {
            $layout = $this->db->query(
                "SELECT config FROM page_layouts WHERE id = $layoutId"
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$layout) {
                return false;
            }

            $stmt = $this->db->prepare(
                'INSERT INTO page_layouts_history (layout_id, config, version, changed_by, change_summary)
                VALUES (?, ?, ?, ?, ?)'
            );

            return $stmt->execute([
                $layoutId,
                $layout['config'],
                $version,
                $this->userId,
                $summary
            ]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::createHistorySnapshot error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obter histórico de um layout
     */
    public function getHistory(string $pageId, int $limit = 20): array {
        try {
            $stmt = $this->db->prepare(
                'SELECT h.* FROM page_layouts_history h
                INNER JOIN page_layouts l ON h.layout_id = l.id
                WHERE l.page_id = ?
                ORDER BY h.created_at DESC
                LIMIT ?'
            );
            $stmt->execute([$pageId, $limit]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log("LayoutManager::getHistory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Reverter para uma versão anterior
     */
    public function revertToVersion(string $pageId, int $versionNumber): bool {
        try {
            // Obter layout antigo
            $stmt = $this->db->prepare(
                'SELECT h.config FROM page_layouts_history h
                INNER JOIN page_layouts l ON h.layout_id = l.id
                WHERE l.page_id = ? AND h.version = ?
                LIMIT 1'
            );
            $stmt->execute([$pageId, $versionNumber]);

            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$result) {
                return false;
            }

            $oldConfig = json_decode($result['config'], true);
            return $this->save(
                $pageId,
                $oldConfig,
                'homepage',
                'both',
                false
            );
        } catch (\PDOException $e) {
            error_log("LayoutManager::revertToVersion error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Exportar layout como arquivo JSON
     */
    public function export(string $pageId): ?string {
        $layout = $this->getByPageId($pageId);
        if (!$layout) {
            return null;
        }

        return json_encode($layout['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Importar layout de arquivo JSON
     */
    public function import(string $pageId, string $jsonContent, string $pageType = 'homepage'): bool {
        $config = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $this->save($pageId, $config, $pageType);
    }

    // ── A/B TESTING ──

    /**
     * Criar uma variante de um layout
     */
    public function createVariant(string $pageId, string $variantName, array $config, float $trafficPercent = 50.0): ?int {
        try {
            $layout = $this->getByPageId($pageId);
            if (!$layout) {
                return null;
            }

            $layoutId = $layout['id'];
            $variantKey = "ab_variant_" . strtolower(preg_replace('/[^a-z0-9]/i', '_', $pageId . '_' . $variantName));
            $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            $stmt = $this->db->prepare(
                'INSERT INTO page_layout_variants (layout_id, page_id, variant_name, variant_key, traffic_percent, config, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $result = $stmt->execute([
                $layoutId,
                $pageId,
                $variantName,
                $variantKey,
                $trafficPercent,
                $configJson,
                $this->userId
            ]);

            return $result ? $this->db->lastInsertId() : null;
        } catch (\PDOException $e) {
            error_log("LayoutManager::createVariant error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obter todas as variantes de um layout
     */
    public function getVariants(string $pageId, bool $activeOnly = false): array {
        try {
            $sql = 'SELECT * FROM page_layout_variants WHERE page_id = ?';
            if ($activeOnly) {
                $sql .= ' AND active = 1';
            }
            $sql .= ' ORDER BY traffic_percent DESC, created_at DESC';

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$pageId]);

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['config'] = json_decode($row['config'], true);
                $row['ctr'] = $row['impressions'] > 0 ? round(($row['conversions'] / $row['impressions']) * 100, 2) : 0;
            }

            return $rows;
        } catch (\PDOException $e) {
            error_log("LayoutManager::getVariants error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Selecionar variante para um visitante (determinístico via hash)
     * Retorna: {variant_id, variant_name, config}
     */
    public function selectVariantForRequest(string $pageId, string $sessionHash): ?array {
        try {
            $variants = $this->getVariants($pageId, true);

            if (empty($variants)) {
                return null;
            }

            // Hash determinístico (sempre mesmo visitor → sempre mesma variante)
            $hashValue = (crc32($sessionHash) & 0x7fffffff) % 10000;
            $accumulated = 0;

            foreach ($variants as $variant) {
                $accumulated += ($variant['traffic_percent'] * 100);
                if ($hashValue < $accumulated) {
                    return [
                        'variant_id' => $variant['id'],
                        'variant_name' => $variant['variant_name'],
                        'variant_key' => $variant['variant_key'],
                        'config' => $variant['config']
                    ];
                }
            }

            // Fallback (nunca deve acontecer)
            return [
                'variant_id' => $variants[0]['id'],
                'variant_name' => $variants[0]['variant_name'],
                'variant_key' => $variants[0]['variant_key'],
                'config' => $variants[0]['config']
            ];
        } catch (\PDOException $e) {
            error_log("LayoutManager::selectVariantForRequest error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Registrar impressão de variante
     */
    public function recordImpression(int $variantId): bool {
        try {
            $stmt = $this->db->prepare(
                'UPDATE page_layout_variants SET impressions = impressions + 1 WHERE id = ?'
            );
            return $stmt->execute([$variantId]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::recordImpression error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registrar conversão (pedido realizado)
     */
    public function recordConversion(int $variantId, float $value = 0): bool {
        try {
            $stmt = $this->db->prepare(
                'UPDATE page_layout_variants
                SET conversions = conversions + 1,
                    revenue = revenue + ?
                WHERE id = ?'
            );
            return $stmt->execute([$value, $variantId]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::recordConversion error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualizar percentual de tráfego
     */
    public function updateVariantTraffic(int $variantId, float $trafficPercent): bool {
        try {
            $stmt = $this->db->prepare(
                'UPDATE page_layout_variants SET traffic_percent = ? WHERE id = ?'
            );
            return $stmt->execute([$trafficPercent, $variantId]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::updateVariantTraffic error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletar variante
     */
    public function deleteVariant(int $variantId): bool {
        try {
            $stmt = $this->db->prepare('DELETE FROM page_layout_variants WHERE id = ?');
            return $stmt->execute([$variantId]);
        } catch (\PDOException $e) {
            error_log("LayoutManager::deleteVariant error: " . $e->getMessage());
            return false;
        }
    }
}
