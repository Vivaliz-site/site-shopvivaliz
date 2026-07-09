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
}
