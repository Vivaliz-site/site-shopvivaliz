CREATE TABLE IF NOT EXISTS blog_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    article_slug VARCHAR(191) NOT NULL,
    name VARCHAR(120) NOT NULL,
    email_encrypted TEXT NULL,
    email_hash CHAR(64) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending','published','rejected','spam','hidden') NOT NULL DEFAULT 'pending',
    moderation_reason VARCHAR(255) NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_blog_comments_article_status (article_slug, status, created_at),
    KEY idx_blog_comments_status_created (status, created_at),
    KEY idx_blog_comments_email_hash (email_hash),
    CONSTRAINT fk_blog_comments_article_slug
        FOREIGN KEY (article_slug) REFERENCES blog_articles(slug)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_comment_replies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id BIGINT UNSIGNED NOT NULL,
    author_type ENUM('liz','admin') NOT NULL,
    author_name VARCHAR(120) NOT NULL,
    message TEXT NOT NULL,
    ai_generated TINYINT(1) NOT NULL DEFAULT 0,
    provider VARCHAR(60) NULL,
    model VARCHAR(120) NULL,
    status ENUM('published','hidden') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_blog_comment_replies_comment (comment_id, status, created_at),
    CONSTRAINT fk_blog_comment_replies_comment
        FOREIGN KEY (comment_id) REFERENCES blog_comments(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_comment_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id BIGINT UNSIGNED NULL,
    actor_type ENUM('system','liz','admin') NOT NULL,
    actor_id VARCHAR(120) NULL,
    action VARCHAR(80) NOT NULL,
    metadata_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_blog_comment_audit_comment (comment_id, created_at),
    CONSTRAINT fk_blog_comment_audit_comment
        FOREIGN KEY (comment_id) REFERENCES blog_comments(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;