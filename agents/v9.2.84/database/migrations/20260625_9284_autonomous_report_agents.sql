CREATE TABLE IF NOT EXISTS sv_agent_heartbeats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ok',
  summary_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_created (agent, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sv_autonomous_agent_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_key VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'ok',
  report_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_created (report_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sv_autonomous_loop_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'queued',
  payload_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sv_media_reject_memory (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NULL,
  media_id BIGINT UNSIGNED NULL,
  sku VARCHAR(191) NULL,
  image_hash VARCHAR(191) NULL,
  reason VARCHAR(191) NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_media_id (media_id),
  KEY idx_sku_hash (sku, image_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sv_media_review_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NULL,
  media_id BIGINT UNSIGNED NULL,
  sku VARCHAR(191) NULL,
  position INT NULL,
  score INT NOT NULL DEFAULT 0,
  reasons_json LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_media_queue (media_id),
  KEY idx_status_score (status, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
