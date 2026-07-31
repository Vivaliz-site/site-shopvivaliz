<?php
declare(strict_types=1);

final class BlogCommentRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public static function fromApplicationDatabase(): self
    {
        require_once __DIR__ . '/../config/database.php';
        return new self(Database::getInstance()->getConnection());
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO blog_comments (article_slug,name,email_encrypted,email_hash,message,status,moderation_reason,ip_hash,user_agent_hash,published_at) VALUES (?,?,?,?,?,?,?,?,?,?)';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar o comentário.');
        }
        $stmt->bind_param(
            'ssssssssss',
            $data['article_slug'],
            $data['name'],
            $data['email_encrypted'],
            $data['email_hash'],
            $data['message'],
            $data['status'],
            $data['moderation_reason'],
            $data['ip_hash'],
            $data['user_agent_hash'],
            $data['published_at']
        );
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao salvar comentário: ' . $error);
        }
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function addReply(int $commentId, string $authorType, string $authorName, string $message, bool $aiGenerated, ?string $provider, ?string $model): int
    {
        $stmt = $this->db->prepare('INSERT INTO blog_comment_replies (comment_id,author_type,author_name,message,ai_generated,provider,model,status) VALUES (?,?,?,?,?,?,?,\'published\')');
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a resposta.');
        }
        $ai = $aiGenerated ? 1 : 0;
        $stmt->bind_param('isssiss', $commentId, $authorType, $authorName, $message, $ai, $provider, $model);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao salvar resposta: ' . $error);
        }
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    public function publishedForArticle(string $slug, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare('SELECT id,name,message,created_at,published_at FROM blog_comments WHERE article_slug=? AND status=\'published\' ORDER BY COALESCE(published_at,created_at) ASC LIMIT ?');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('si', $slug, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments = [];
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $row['replies'] = [];
            $comments[(int)$row['id']] = $row;
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $replyStmt = $this->db->prepare("SELECT comment_id,author_type,author_name,message,ai_generated,created_at FROM blog_comment_replies WHERE status='published' AND comment_id IN ($placeholders) ORDER BY created_at ASC");
        if ($replyStmt) {
            $replyStmt->bind_param($types, ...$ids);
            $replyStmt->execute();
            $replyResult = $replyStmt->get_result();
            while ($reply = $replyResult->fetch_assoc()) {
                $cid = (int)$reply['comment_id'];
                if (isset($comments[$cid])) {
                    $comments[$cid]['replies'][] = $reply;
                }
            }
            $replyStmt->close();
        }
        return array_values($comments);
    }

    public function listForModeration(string $status = 'pending', int $limit = 200): array
    {
        $allowed = ['pending','published','rejected','spam','hidden'];
        $status = in_array($status, $allowed, true) ? $status : 'pending';
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->prepare('SELECT id,article_slug,name,message,status,moderation_reason,created_at,published_at FROM blog_comments WHERE status=? ORDER BY created_at DESC LIMIT ?');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('si', $status, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function updateStatus(int $id, string $status, ?string $reason = null): bool
    {
        $allowed = ['pending','published','rejected','spam','hidden'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $publishedAt = $status === 'published' ? gmdate('Y-m-d H:i:s') : null;
        $stmt = $this->db->prepare('UPDATE blog_comments SET status=?,moderation_reason=?,published_at=CASE WHEN ? IS NULL THEN published_at ELSE ? END WHERE id=?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssssi', $status, $reason, $publishedAt, $publishedAt, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_comments WHERE id=? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function audit(?int $commentId, string $actorType, ?string $actorId, string $action, array $metadata = []): void
    {
        $json = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare('INSERT INTO blog_comment_audit (comment_id,actor_type,actor_id,action,metadata_json) VALUES (?,?,?,?,?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('issss', $commentId, $actorType, $actorId, $action, $json);
        $stmt->execute();
        $stmt->close();
    }
}