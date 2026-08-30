<?php
declare(strict_types=1);

require_once __DIR__ . '/../blog/content.php';
require_once __DIR__ . '/blog-editorial-autopilot.php';

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
            return $this->normalizeArticles(sv_blog_search_articles($query, $category, $limit));
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

        $sql .= ' ORDER BY published_at DESC, id DESC LIMIT ?';
        $types .= 'i';
        $params[] = max(1, min(200, $limit));

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return $this->normalizeArticles(sv_blog_search_articles($query, $category, $limit));
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $articles = [];
        while ($row = $result->fetch_assoc()) {
            $articles[] = $this->hydrate($row);
        }
        $stmt->close();

        return $articles ?: $this->normalizeArticles(sv_blog_search_articles($query, $category, $limit));
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        if (!$this->db) {
            foreach (sv_blog_articles() as $article) {
                if (($article['slug'] ?? '') === $slug) {
                    return $this->normalizeArticle($article);
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

    public function ensureAutonomousQueue(int $minimumFuture = 9): array
    {
        if (!$this->db) {
            return ['queued' => 0, 'future' => 0, 'target' => max(1, $minimumFuture), 'errors' => ['database_unavailable']];
        }

        $minimumFuture = max(3, min(36, $minimumFuture));
        $futureSlots = $this->futureScheduledSlots();
        $futureCount = count($futureSlots);
        if ($futureCount >= $minimumFuture) {
            return ['queued' => 0, 'future' => $futureCount, 'target' => $minimumFuture, 'errors' => []];
        }

        $existingSlugs = $this->existingSlugs();
        $agenda = [];
        foreach (sv_blog_editorial_agenda() as $weekday => $titles) {
            $agenda[$weekday] = [];
            foreach ($titles as $title) {
                $slug = sv_blog_editorial_topic_slug($title);
                if (!isset($existingSlugs[$slug])) {
                    $agenda[$weekday][] = $title;
                }
            }
        }

        $occupiedSlots = array_fill_keys($futureSlots, true);
        $nowLocal = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $slots = sv_blog_editorial_upcoming_slots($nowLocal, $minimumFuture + 18);
        $queued = 0;
        $errors = [];

        foreach ($slots as $slot) {
            if (($futureCount + $queued) >= $minimumFuture) {
                break;
            }

            $scheduledAtUtc = $slot['scheduled_at_utc'];
            if (isset($occupiedSlots[$scheduledAtUtc])) {
                continue;
            }

            $weekday = $slot['weekday'];
            $titles = $agenda[$weekday] ?? [];
            $title = array_shift($titles);
            $agenda[$weekday] = $titles;
            if (!is_string($title) || $title === '') {
                $errors[] = 'agenda_exaurida_' . $weekday;
                continue;
            }

            $article = sv_blog_editorial_build_article($title, $weekday);
            $validationErrors = sv_blog_editorial_validate_article($article);
            if ($validationErrors !== []) {
                $errors[] = $article['slug'] . ':' . implode(',', $validationErrors);
                continue;
            }

            if ($this->insertScheduledArticle($article, $scheduledAtUtc)) {
                $queued++;
                $occupiedSlots[$scheduledAtUtc] = true;
                $existingSlugs[$article['slug']] = true;
            }
        }

        return [
            'queued' => $queued,
            'future' => $futureCount + $queued,
            'target' => $minimumFuture,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function hydrate(array $row): array
    {
        return $this->normalizeArticle([
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
        ]);
    }

    private function normalizeArticles(array $articles): array
    {
        return array_map(fn(array $article): array => $this->normalizeArticle($article), $articles);
    }

    private function normalizeArticle(array $article): array
    {
        $currentImage = (string)($article['image'] ?? $article['image_url'] ?? '');
        $resolvedImage = $this->resolveImage($currentImage, $article);
        $article['image'] = $resolvedImage;
        if (isset($article['related_products_url'])) {
            $article['related_products_url'] = str_replace(
                ['/catalogo?busca=', '/catalogo/?busca='],
                ['/catalogo/?q=', '/catalogo/?q='],
                (string)$article['related_products_url']
            );
        }
        if (array_key_exists('image_url', $article)) {
            $article['image_url'] = $resolvedImage;
        }
        return $article;
    }

    private function resolveImage(string $image, array $article): string
    {
        $slug = trim((string)($article['slug'] ?? ''));
        if ($slug !== '') {
            foreach (['svg', 'webp', 'jpg', 'jpeg', 'png'] as $extension) {
                $candidate = '/public/assets/blog/' . $slug . '.' . $extension;
                if (is_file(dirname(__DIR__) . $candidate)) {
                    return $candidate;
                }
            }
        }

        $image = trim($image);
        if ($image !== '' && (str_starts_with($image, 'http://') || str_starts_with($image, 'https://') || str_starts_with($image, '//'))) {
            return $image;
        }

        if ($image !== '') {
            $path = (string)(parse_url($image, PHP_URL_PATH) ?: $image);
            $absolute = dirname(__DIR__) . '/' . ltrim($path, '/');
            if (is_file($absolute)) {
                return $image;
            }
        }

        return $this->fallbackImageFor($article);
    }

    private function fallbackImageFor(array $article): string
    {
        $keywords = $article['keywords'] ?? [];
        $keywordText = is_array($keywords) ? implode(' ', array_map('strval', $keywords)) : (string)$keywords;
        $haystack = implode(' ', [
            (string)($article['title'] ?? ''),
            (string)($article['category'] ?? ''),
            $keywordText,
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $haystack);
        $haystack = strtolower(is_string($ascii) && $ascii !== '' ? $ascii : $haystack);

        $rules = [
            '/rodizio|rodizios|roda|rodas/' => '/public/assets/category-images/cat-rodizios.jpg',
            '/jardim|jardinagem|planta|plantas/' => '/public/assets/category-images/cat-jardim.jpg',
            '/ferramenta|ferramentas|reparo|reparos|conserto|consertos/' => '/public/assets/category-images/cat-ferramentas.jpg',
            '/ferragem|ferragens|fixador|fixadores|bucha|buchas|parafuso|parafusos|cadeado|cadeados|dobradica|puxador|gancho|suporte/' => '/public/assets/category-images/cat-ferragens.jpg',
            '/organizacao|organizar|organizador|organizadores|armario|cozinha|lavanderia|garagem|estoque|caixa|quarto|limpeza|acessorio|acessorios/' => '/public/assets/category-images/cat-organizacao.jpg',
        ];
        foreach ($rules as $pattern => $fallback) {
            if (preg_match($pattern, $haystack) === 1) {
                return $fallback;
            }
        }

        return '/public/assets/category-images/cat-organizacao.jpg';
    }

    private function decodeJson(?string $value): array
    {
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function existingSlugs(): array
    {
        $result = $this->db?->query('SELECT slug FROM blog_articles');
        if (!$result) {
            return [];
        }

        $slugs = [];
        while ($row = $result->fetch_assoc()) {
            $slug = (string)($row['slug'] ?? '');
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
        $result->close();

        return $slugs;
    }

    private function futureScheduledSlots(): array
    {
        $result = $this->db?->query("SELECT scheduled_at FROM blog_articles WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at > UTC_TIMESTAMP()");
        if (!$result) {
            return [];
        }

        $slots = [];
        while ($row = $result->fetch_assoc()) {
            $scheduledAt = (string)($row['scheduled_at'] ?? '');
            if ($scheduledAt !== '') {
                $slots[] = $scheduledAt;
            }
        }
        $result->close();

        return $slots;
    }

    private function insertScheduledArticle(array $article, string $scheduledAtUtc): bool
    {
        $article['image_url'] = $this->resolveImage((string)($article['image_url'] ?? ''), $article);
        $sql = "INSERT INTO blog_articles
            (slug,title,excerpt,category,image_url,image_alt,content_json,faq_json,keywords_json,meta_title,meta_description,related_products_url,author,status,featured,reading_time,scheduled_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'scheduled',?,?,?)
            ON DUPLICATE KEY UPDATE updated_at = updated_at";
        $stmt = $this->db?->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $content = json_encode($article['content'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $faq = json_encode($article['faq'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $keywords = json_encode($article['keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $featured = !empty($article['featured']) ? 1 : 0;
        $readingTime = (int)($article['reading_time'] ?? 5);

        $stmt->bind_param(
            'sssssssssssssiis',
            $article['slug'],
            $article['title'],
            $article['excerpt'],
            $article['category'],
            $article['image_url'],
            $article['image_alt'],
            $content,
            $faq,
            $keywords,
            $article['meta_title'],
            $article['meta_description'],
            $article['related_products_url'],
            $article['author'],
            $featured,
            $readingTime,
            $scheduledAtUtc
        );

        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();

        return $ok;
    }
}
