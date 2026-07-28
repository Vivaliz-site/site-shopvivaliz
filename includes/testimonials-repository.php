<?php
declare(strict_types=1);

final class TestimonialsRepository
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: dirname(__DIR__) . '/storage/testimonials.json';
    }

    private function readAll(): array
    {
        if (!is_file($this->file)) return [];
        $json = file_get_contents($this->file);
        $rows = json_decode((string)$json, true);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function writeAll(array $rows): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $tmp = $this->file . '.tmp';
        $ok = file_put_contents($tmp, json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        return $ok !== false && rename($tmp, $this->file);
    }

    public function submit(array $data): array
    {
        $rows = $this->readAll();
        $id = bin2hex(random_bytes(8));
        $row = [
            'id' => $id,
            'name' => trim(strip_tags((string)($data['name'] ?? ''))),
            'city' => trim(strip_tags((string)($data['city'] ?? ''))),
            'rating' => max(1, min(5, (int)($data['rating'] ?? 5))),
            'message' => trim(strip_tags((string)($data['message'] ?? ''))),
            'order_reference' => trim(strip_tags((string)($data['order_reference'] ?? ''))),
            'status' => 'pending',
            'created_at' => gmdate('c'),
            'moderated_at' => null,
            'moderation_reason' => null,
        ];
        $rows[] = $row;
        if (!$this->writeAll($rows)) throw new RuntimeException('Não foi possível salvar a avaliação.');
        return $row;
    }

    public function approved(int $limit = 12): array
    {
        $rows = array_values(array_filter($this->readAll(), fn(array $r): bool => ($r['status'] ?? '') === 'approved'));
        usort($rows, fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return array_slice($rows, 0, max(1, $limit));
    }

    public function byStatus(string $status): array
    {
        $allowed = ['pending','approved','rejected','hidden'];
        if (!in_array($status, $allowed, true)) $status = 'pending';
        return array_values(array_filter($this->readAll(), fn(array $r): bool => ($r['status'] ?? '') === $status));
    }

    public function moderate(string $id, string $status, ?string $reason = null): bool
    {
        if (!in_array($status, ['pending','approved','rejected','hidden'], true)) return false;
        $rows = $this->readAll();
        $changed = false;
        foreach ($rows as &$row) {
            if (($row['id'] ?? '') !== $id) continue;
            $row['status'] = $status;
            $row['moderated_at'] = gmdate('c');
            $row['moderation_reason'] = $reason ? trim(strip_tags($reason)) : null;
            $changed = true;
            break;
        }
        unset($row);
        return $changed && $this->writeAll($rows);
    }
}
