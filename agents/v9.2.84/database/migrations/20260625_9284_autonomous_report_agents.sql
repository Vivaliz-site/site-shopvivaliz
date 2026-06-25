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
