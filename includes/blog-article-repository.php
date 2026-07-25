<?php
declare(strict_types=1);

require_once __DIR__ . '/../blog/content.php';

final class BlogArticleRepository
{
    private ?mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db;
    }

    public static function fromApplicationDatabase(): self
    {
        try {
            require_once __DIR__ . '/../config/constants.php';
            require_once __DIR__ . '/../config/database.php';
            return new self(Database::getInstance()->getConnection());
        } catch (Throwable $e) {
            error_log('Blog repository fallback ativo: ' . $e->getMessage());
            return new self(null);
        }
    }

    public function isDatabaseAvailable(): bool
    {
        return $this->db instanceof mysqli;
    }

    public function published(string $query = '', string $category = '', int $limit = 100): array
    {
        if (!$this->db) {
            return sv_blog_search_articles($query, $category, $limit);
        }

        $sql = "SELECT * FROM blog_articles WHERE status = 'published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP())";
        $types = '';
        $params = [];

        if ($query !== '') {
            $sql .= ' AND (title LIKE ? OR excerpt LIKE ? OR keywords_json LIKE ?)';
            $term = '%' . $query . '%';
            $types .= 'sss';
            array_push($params, $term, $term, $term);
        }
        if ($category !== '') {
            $sql .= ' AND category = ?';
            $types .= 's';
            $params[] = $category;
        }

        $sql .= ' ORDER BY featured DESC, published_at DESC, id DESC LIMIT ?';
        $types .= 'i';
        $params[] = max(1, min(200, $limit));

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return sv_blog_search_articles($query, $category, $limit);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $articles = [];
        while ($row = $result->fetch_assoc()) {
            $articles[] = $this->hydrate($row);
        }
        $stmt->close();

        return $articles ?: sv_blog_search_articles($query, $category, $limit);
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        if (!$this->db) {
            foreach (sv_blog_articles() as $article) {
                if (($article['slug'] ?? '') === $slug) {
                    return $article;
                }
            }
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM blog_articles WHERE slug = ? AND status = 'published' AND (published_at IS NULL OR published_at <= UTC_TIMESTAMP()) LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $this->hydrate($row) : null;
    }

    public function publishDue(): int
    {
        if (!$this->db) {
            return 0;
        }
        $stmt = $this->db->prepare("UPDATE blog_articles SET status = 'published', published_at = COALESCE(published_at, scheduled_at, UTC_TIMESTAMP()) WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP()");
        if (!$stmt) {
            return 0;
        }
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }

    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'title' => (string)$row['title'],
            'excerpt' => (string)$row['excerpt'],
            'category' => (string)$row['category'],
            'image' => (string)($row['image_url'] ?? ''),
            'image_alt' => (string)($row['image_alt'] ?? ''),
            'content' => $this->decodeJson($row['content_json'] ?? '[]'),
            'faq' => $this->decodeJson($row['faq_json'] ?? '[]'),
            'keywords' => $this->decodeJson($row['keywords_json'] ?? '[]'),
            'meta_title' => (string)($row['meta_title'] ?? ''),
            'meta_description' => (string)($row['meta_description'] ?? ''),
            'related_products_url' => (string)($row['related_products_url'] ?? ''),
            'author' => (string)($row['author'] ?? 'Equipe ShopVivaliz'),
            'status' => (string)$row['status'],
            'featured' => (bool)$row['featured'],
            'reading_time' => (int)$row['reading_time'],
            'published_at' => $row['published_at'] ? substr((string)$row['published_at'], 0, 10) : null,
            'updated_at' => $row['updated_at'] ? substr((string)$row['updated_at'], 0, 10) : null,
        ];
    }

    private function decodeJson(?string $value): array
    {
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
