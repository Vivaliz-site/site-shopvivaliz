<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pdo-database.php';
require_once dirname(__DIR__) . '/config/bootstrap-env.php';
require_once dirname(__DIR__) . '/scripts/mailer.php';

function svem_base_url(): string
{
    return rtrim(sv_mailer_site_url(), '/');
}

function svem_secret(): string
{
    foreach (['NEWSLETTER_SIGNING_KEY', 'APP_KEY', 'SHOPVIVALIZ_APP_KEY', 'SHOPVIVALIZ_AGENT_KEY'] as $key) {
        $value = trim((string)(getenv($key) ?: ''));
        if (strlen($value) >= 24) {
            return $value;
        }
    }
    throw new RuntimeException('email_marketing_signing_key_missing');
}

function svem_email_hash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function svem_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function svem_base64url_decode(string $value): string|false
{
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function svem_unsubscribe_url(string $email): string
{
    $encoded = svem_base64url_encode(strtolower(trim($email)));
    $signature = hash_hmac('sha256', $encoded, svem_secret());
    return svem_base_url() . '/newsletter/descadastrar?e=' . rawurlencode($encoded) . '&s=' . rawurlencode($signature);
}

function svem_table_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return [];
    }
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $columns = [];
    foreach ($rows as $row) {
        $columns[(string)$row['Field']] = true;
    }
    return $columns;
}

function svem_recipients(PDO $pdo): array
{
    $recipients = [];
    $suppressed = [];
    foreach ($pdo->query('SELECT email_hash FROM email_marketing_suppressions')->fetchAll(PDO::FETCH_COLUMN) as $hash) {
        $suppressed[(string)$hash] = true;
    }

    $newsletterColumns = svem_table_columns($pdo, 'newsletter_subscriptions');
    if (isset($newsletterColumns['email'], $newsletterColumns['status'])) {
        $stmt = $pdo->query("SELECT email FROM newsletter_subscriptions WHERE status = 'confirmed'");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $emailValue) {
            $email = strtolower(trim((string)$emailValue));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($suppressed[svem_email_hash($email)])) {
                $recipients[$email] = ['email' => $email, 'source' => 'newsletter'];
            }
        }
    }

    foreach (['customers', 'users'] as $table) {
        $columns = svem_table_columns($pdo, $table);
        if (!isset($columns['email'])) {
            continue;
        }
        $consentColumn = null;
        foreach (['marketing_consent', 'marketing_opt_in', 'newsletter_opt_in', 'accepts_marketing'] as $candidate) {
            if (isset($columns[$candidate])) {
                $consentColumn = $candidate;
                break;
            }
        }
        if ($consentColumn === null) {
            continue;
        }
        $sql = 'SELECT email FROM `' . $table . '` WHERE `' . $consentColumn . '` IN (1, true, \'1\', \'yes\', \'sim\')';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $emailValue) {
            $email = strtolower(trim((string)$emailValue));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($suppressed[svem_email_hash($email)])) {
                continue;
            }
            if (!isset($recipients[$email])) {
                $recipients[$email] = ['email' => $email, 'source' => 'customer_consent'];
            }
        }
    }

    ksort($recipients);
    return array_values($recipients);
}

function svem_campaign_exists(PDO $pdo, string $campaignKey): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM email_campaign_runs WHERE campaign_key = :campaign_key AND status IN ('completed','partial') LIMIT 1");
    $stmt->execute([':campaign_key' => $campaignKey]);
    return (bool)$stmt->fetchColumn();
}

function svem_send_campaign(PDO $pdo, string $campaignKey, string $campaignType, string $subject, callable $htmlBuilder, callable $textBuilder, array $payload = []): array
{
    $lockName = 'svem:' . substr(hash('sha256', $campaignKey), 0, 48);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 2)');
    $lockStmt->execute([':lock_name' => $lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('campaign_lock_unavailable');
    }

    try {
        if (svem_campaign_exists($pdo, $campaignKey)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'already_completed', 'campaign_key' => $campaignKey];
        }

        $run = $pdo->prepare("INSERT INTO email_campaign_runs (campaign_key, campaign_type, status, payload_json) VALUES (:campaign_key, :campaign_type, 'running', :payload_json) ON DUPLICATE KEY UPDATE status = IF(status = 'completed', status, 'running'), payload_json = VALUES(payload_json), started_at = IF(status = 'completed', started_at, NOW()), finished_at = NULL");
        $run->execute([
            ':campaign_key' => $campaignKey,
            ':campaign_type' => $campaignType,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $recipients = svem_recipients($pdo);
        $sent = 0;
        $failed = 0;
        $suppressed = 0;

        $selectDelivery = $pdo->prepare('SELECT status FROM email_campaign_deliveries WHERE campaign_key = :campaign_key AND recipient_email_hash = :email_hash LIMIT 1');
        $upsertDelivery = $pdo->prepare("INSERT INTO email_campaign_deliveries (campaign_key, recipient_email_hash, recipient_source, status) VALUES (:campaign_key, :email_hash, :source, 'sending') ON DUPLICATE KEY UPDATE recipient_source = VALUES(recipient_source), status = IF(status = 'sent', 'sent', 'sending'), error_message = NULL");
        $markSent = $pdo->prepare("UPDATE email_campaign_deliveries SET status = 'sent', sent_at = NOW(), error_message = NULL WHERE campaign_key = :campaign_key AND recipient_email_hash = :email_hash");
        $markFailed = $pdo->prepare("UPDATE email_campaign_deliveries SET status = 'failed', error_message = :error_message WHERE campaign_key = :campaign_key AND recipient_email_hash = :email_hash");

        foreach ($recipients as $recipient) {
            $email = $recipient['email'];
            $emailHash = svem_email_hash($email);
            $selectDelivery->execute([':campaign_key' => $campaignKey, ':email_hash' => $emailHash]);
            if ($selectDelivery->fetchColumn() === 'sent') {
                $sent++;
                continue;
            }

            $upsertDelivery->execute([
                ':campaign_key' => $campaignKey,
                ':email_hash' => $emailHash,
                ':source' => $recipient['source'],
            ]);

            $unsubscribeUrl = svem_unsubscribe_url($email);
            $html = (string)$htmlBuilder($email, $unsubscribeUrl);
            $text = (string)$textBuilder($email, $unsubscribeUrl);
            $ok = send_email($email, $subject, $html, $text);
            if ($ok) {
                $markSent->execute([':campaign_key' => $campaignKey, ':email_hash' => $emailHash]);
                $sent++;
            } else {
                $markFailed->execute([
                    ':campaign_key' => $campaignKey,
                    ':email_hash' => $emailHash,
                    ':error_message' => 'smtp_transport_failed',
                ]);
                $failed++;
            }
        }

        $status = $failed === 0 ? 'completed' : ($sent > 0 ? 'partial' : 'failed');
        $finish = $pdo->prepare('UPDATE email_campaign_runs SET status = :status, recipient_count = :recipient_count, sent_count = :sent_count, failed_count = :failed_count, finished_at = NOW() WHERE campaign_key = :campaign_key');
        $finish->execute([
            ':status' => $status,
            ':recipient_count' => count($recipients),
            ':sent_count' => $sent,
            ':failed_count' => $failed,
            ':campaign_key' => $campaignKey,
        ]);

        return [
            'ok' => $failed === 0,
            'campaign_key' => $campaignKey,
            'status' => $status,
            'recipients' => count($recipients),
            'sent' => $sent,
            'failed' => $failed,
            'suppressed' => $suppressed,
        ];
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $lockName]);
    }
}

function svem_email_shell(string $title, string $bodyHtml, string $unsubscribeUrl): string
{
    return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033">'
        . '<div style="max-width:640px;margin:0 auto;padding:24px"><div style="background:#fff;border-radius:16px;padding:28px">'
        . '<h1 style="margin-top:0;color:#0b4f88;font-size:26px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . $bodyHtml
        . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:28px 0">'
        . '<p style="font-size:12px;color:#64748b">Você recebeu esta mensagem porque autorizou comunicações da Vivaliz. '
        . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') . '">Descadastrar</a>.</p>'
        . '</div></div></body></html>';
}

function svem_send_published_blog_articles(PDO $pdo): array
{
    $columns = svem_table_columns($pdo, 'blog_articles');
    foreach (['id', 'title', 'slug', 'status'] as $required) {
        if (!isset($columns[$required])) {
            throw new RuntimeException('blog_articles_schema_incompatible');
        }
    }
    $publishedColumn = isset($columns['published_at']) ? 'published_at' : (isset($columns['updated_at']) ? 'updated_at' : 'id');
    $excerptExpr = isset($columns['excerpt']) ? 'excerpt' : (isset($columns['summary']) ? 'summary' : "'' AS excerpt");
    $imageExpr = isset($columns['image_url']) ? 'image_url' : (isset($columns['featured_image']) ? 'featured_image' : "'' AS image_url");
    $sql = "SELECT id, title, slug, {$excerptExpr}, {$imageExpr}, {$publishedColumn} AS published_at FROM blog_articles WHERE status = 'published' ORDER BY {$publishedColumn} DESC LIMIT 30";
    $articles = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($articles as $article) {
        $id = (string)$article['id'];
        $publishedAt = (string)($article['published_at'] ?? '');
        $campaignKey = 'blog:' . $id . ':' . substr(hash('sha256', $publishedAt), 0, 12);
        if (svem_campaign_exists($pdo, $campaignKey)) {
            continue;
        }
        $title = trim((string)$article['title']);
        $url = svem_base_url() . '/blog/' . rawurlencode((string)$article['slug']);
        $excerpt = trim(strip_tags((string)($article['excerpt'] ?? '')));
        $image = trim((string)($article['image_url'] ?? ''));
        $body = '';
        if ($image !== '') {
            $body .= '<img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="" style="width:100%;height:auto;border-radius:12px;margin-bottom:18px">';
        }
        if ($excerpt !== '') {
            $body .= '<p style="font-size:16px;line-height:1.6">' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $body .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0b7a52;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:700">Ler artigo</a></p>';
        $results[] = svem_send_campaign(
            $pdo,
            $campaignKey,
            'blog_article',
            $title . ' | Blog Vivaliz',
            static fn(string $email, string $unsubscribeUrl): string => svem_email_shell($title, $body, $unsubscribeUrl),
            static fn(string $email, string $unsubscribeUrl): string => $title . "\n\n" . $excerpt . "\n\n" . $url . "\n\nDescadastrar: " . $unsubscribeUrl,
            ['article_id' => $id, 'url' => $url]
        );
    }
    return $results;
}

function svem_send_product_highlights(PDO $pdo): array
{
    $last = $pdo->query("SELECT started_at FROM email_campaign_runs WHERE campaign_type = 'product_highlights' AND status IN ('completed','partial') ORDER BY started_at DESC LIMIT 1")->fetchColumn();
    if ($last && strtotime((string)$last) > time() - (15 * 86400)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'not_due', 'last_sent_at' => $last];
    }

    require_once dirname(__DIR__) . '/includes/catalog-runtime.php';
    $products = array_values(array_filter(svcr_products(), static function ($product): bool {
        return is_array($product) && (float)($product['price'] ?? 0) > 0 && (int)($product['stock'] ?? 0) > 0;
    }));
    usort($products, static function (array $a, array $b): int {
        $featured = (int)!empty($b['featured']) <=> (int)!empty($a['featured']);
        return $featured !== 0 ? $featured : ((int)($b['stock'] ?? 0) <=> (int)($a['stock'] ?? 0));
    });
    $products = array_slice($products, 0, 8);
    if ($products === []) {
        throw new RuntimeException('no_sellable_products_for_campaign');
    }

    $cards = '';
    $textLines = [];
    foreach ($products as $product) {
        $name = trim((string)($product['name'] ?? 'Produto Vivaliz'));
        $sku = trim((string)($product['sku'] ?? ''));
        $slug = trim((string)($product['slug'] ?? ''));
        $url = $slug !== '' ? svem_base_url() . '/produto/' . rawurlencode($slug) : svem_base_url() . '/produto?sku=' . rawurlencode($sku);
        $price = 'R$ ' . number_format((float)$product['price'], 2, ',', '.');
        $image = trim((string)($product['image_url'] ?? ''));
        $cards .= '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin:12px 0">';
        if ($image !== '') {
            $cards .= '<img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="" style="width:120px;height:120px;object-fit:contain;float:left;margin-right:16px">';
        }
        $cards .= '<h2 style="font-size:18px;margin:0 0 8px">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h2>';
        $cards .= '<p style="font-weight:700;color:#0b4f88">' . $price . '</p>';
        $cards .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Ver produto</a></p><div style="clear:both"></div></div>';
        $textLines[] = $name . ' — ' . $price . ' — ' . $url;
    }

    $campaignKey = 'product-highlights:' . gmdate('Y-m-d');
    return svem_send_campaign(
        $pdo,
        $campaignKey,
        'product_highlights',
        'Produtos em destaque da Vivaliz',
        static fn(string $email, string $unsubscribeUrl): string => svem_email_shell('Produtos em destaque', '<p>Selecionamos produtos disponíveis para você.</p>' . $cards, $unsubscribeUrl),
        static fn(string $email, string $unsubscribeUrl): string => "Produtos em destaque da Vivaliz\n\n" . implode("\n", $textLines) . "\n\nDescadastrar: " . $unsubscribeUrl,
        ['product_count' => count($products), 'skus' => array_values(array_map(static fn(array $p): string => (string)($p['sku'] ?? ''), $products))]
    );
}
