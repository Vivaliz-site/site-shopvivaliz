from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_storage_denies_direct_http_access() -> None:
    rules = (ROOT / "storage" / ".htaccess").read_text(encoding="utf-8")
    assert rules.strip() == "Require all denied"


def test_apache_policy_blocks_repository_and_runtime_paths() -> None:
    policy = (ROOT / "deploy" / "apache" / "shopvivaliz-private-paths.conf").read_text(
        encoding="utf-8"
    )
    for token in (
        "\\.git",
        "storage",
        "\\.env",
        "tasks-queue",
        "scripts",
        "tests",
        "gen-token",
        "test-normalize",
        "sync-cache-endpoint",
        "test-results",
        "olist",
        "migrations",
        "release-notes",
    ):
        assert token in policy
    assert "Require all denied" in policy
    assert "Options -Indexes" in policy
    assert "Content-Security-Policy" in policy


def test_root_htaccess_blocks_env_variants() -> None:
    rules = (ROOT / ".htaccess").read_text(encoding="utf-8")
    assert "\\.env(?:\\..*)?" in rules
    assert "Options -Indexes" in rules
    assert "Content-Security-Policy" in rules


def test_root_htaccess_blocks_legacy_web_diagnostics() -> None:
    rules = (ROOT / ".htaccess").read_text(encoding="utf-8")
    for token in (
        "gen-token",
        "setup-webhooks",
        "test-normalize",
        "sync-cache-endpoint",
        "test-results",
        "olist",
    ):
        assert token in rules
    assert "(?:[-_.][^/]*)?" in rules
    assert "(?:debug|test|teste|check|gen-token)[^/]*" not in rules


def test_catalog_response_does_not_publish_runtime_debug_state() -> None:
    endpoint = (ROOT / "api" / "catalog" / "products.php").read_text(encoding="utf-8")
    assert "'debug'" not in endpoint
    assert 'error_log("[products.php]' not in endpoint


def test_sync_cache_endpoint_uses_hardened_token_loading_and_atomic_write() -> None:
    endpoint = (ROOT / "api" / "sync-cache-endpoint.php").read_text(encoding="utf-8")
    assert "storage/private/tokens.json" in endpoint
    assert "runtime-secrets.php" in endpoint
    assert "sync_cache_write_atomic" in endpoint
    assert "token_not_found" in endpoint
    assert "'file' => 'storage/products-cache-ativos.json'" in endpoint


def test_health_endpoint_is_read_only() -> None:
    endpoint = (ROOT / "api" / "health.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "return is_dir($path) && is_writable($path);" in endpoint


def test_tiny_stock_webhook_validates_and_releases_lock_safely() -> None:
    endpoint = (ROOT / "api" / "tiny" / "stock-webhook.php").read_text(encoding="utf-8")
    assert "header_remove('X-Powered-By');" in endpoint
    assert "header('Cache-Control: no-store');" in endpoint
    assert "reason' => 'invalid_saldo'" in endpoint
    assert "catalog_lock_failed" in endpoint
    assert "catalog_invalid" in endpoint
    assert "finally {" in endpoint


def test_covers_status_does_not_expose_internal_catalog_path() -> None:
    endpoint = (ROOT / "api" / "covers" / "status.php").read_text(encoding="utf-8")
    assert "'path' => $catalogFile" not in endpoint
    assert "Falha ao ler catálogo" in endpoint


def test_graphql_rate_limit_avoids_mkdir_in_request_path_and_uses_locking() -> None:
    endpoint = (ROOT / "api" / "graphql.php").read_text(encoding="utf-8")
    assert "function gql_rate_limit_dir(): ?string" in endpoint
    assert "@mkdir" not in endpoint
    assert "fopen($file, 'c+')" in endpoint
    assert "flock($fp, LOCK_EX)" in endpoint
    assert "X-RateLimit-Policy: unavailable" in endpoint


def test_monitor_dev_status_is_read_only() -> None:
    endpoint = (ROOT / "api" / "monitor" / "dev-status.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "Diretorio logs gravavel" in endpoint


def test_stock_alerts_endpoints_avoid_mkdir_and_hide_absolute_outbox_path() -> None:
    subscribe = (ROOT / "api" / "stock-alerts" / "subscribe.php").read_text(encoding="utf-8")
    process = (ROOT / "api" / "stock-alerts" / "process.php").read_text(encoding="utf-8")
    assert "@mkdir" not in subscribe
    assert "@mkdir" not in process
    assert "'outbox' => 'storage/stock-alerts/outbox.jsonl'" in process


def test_ml_webhook_avoids_mkdir_and_uses_safe_helpers() -> None:
    endpoint = (ROOT / "api" / "ml" / "webhook.php").read_text(encoding="utf-8")
    assert "header('Cache-Control: no-store');" in endpoint
    assert "@mkdir" not in endpoint
    assert "function ml_append_log" in endpoint
    assert "function ml_write_json_file" in endpoint
    assert "ML order storage unavailable" in endpoint


def test_squad_chat_avoids_mkdir_and_checks_private_storage_writability() -> None:
    endpoint = (ROOT / "api" / "agent" / "squad-chat.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function squad_write_json" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "Fila de intervencao indisponivel." in endpoint


def test_orchestrator_queue_avoids_mkdir_and_hides_absolute_queue_path() -> None:
    endpoint = (ROOT / "api" / "orchestrator" / "queue.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "return (is_dir($dir) && is_writable($dir)) ? $dir : null;" in endpoint
    assert "'queue_file' => 'storage/orchestrator/queue.json'" in endpoint
    assert "header('Cache-Control: no-store');" in endpoint


def test_orchestrator_director_avoids_mkdir_for_logging() -> None:
    endpoint = (ROOT / "api" / "orchestrator" / "director.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function odir_log_dir(): ?string" in endpoint
    assert "if ($dir === null) {" in endpoint


def test_orchestrator_scheduler_avoids_mkdir_for_logging() -> None:
    endpoint = (ROOT / "api" / "orchestrator" / "scheduler.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function osch_log_dir(): ?string" in endpoint
    assert "if ($dir === null) {" in endpoint


def test_monitor_api_avoids_mkdir_and_surfaces_private_queue_unavailability() -> None:
    endpoint = (ROOT / "api" / "monitor" / "api.php").read_text(encoding="utf-8")
    assert "header_remove('X-Powered-By');" in endpoint
    assert "@mkdir" not in endpoint
    assert "function monitor_write_jsonl(string $relPath, array $payload): bool" in endpoint
    assert "Fila privada indisponivel para gravacao." in endpoint


def test_autonomous_testing_framework_avoids_mkdir_for_test_logs() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "testing-framework.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "public static function logResult(array $test): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_task_validator_avoids_mkdir_for_rejection_logs() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "task-validator.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "public static function reject(array $task, array $validation): bool" in endpoint
    assert "if (!is_dir($logDir) || !is_writable($logDir))" in endpoint


def test_autonomous_task_deduplicator_avoids_mkdir_for_log_files() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "task-deduplicator.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "public static function appendJsonl(string $path, array $payload): bool" in endpoint
    assert "public static function logDuplicate(array $newTask, string $duplicateOf): bool" in endpoint
    assert "private static function logOrphan(array $orphan): bool" in endpoint


def test_autonomous_review_enforcer_avoids_mkdir_for_review_logs() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "review-enforcer.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function logReview(array $review): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_regression_tracker_avoids_mkdir_for_baseline_and_results() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "regression-tracker.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function saveBaseline(array $baseline): bool" in endpoint
    assert "private static function logRegression(array $result): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_send_email_avoids_mkdir_for_email_logs() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "send-email.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function appendLog(array $payload): bool" in endpoint
    assert "private static function logSuccess(string $to, string $subject, string $method): bool" in endpoint
    assert "private static function logError(string $error): bool" in endpoint


def test_autonomous_operational_controls_avoids_mkdir_for_audit_log() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "operational-controls.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function logAudit(array $findings): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_health_monitor_avoids_mkdir_for_sla_log() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "health-monitor.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function logSLAStatus(string $agent, array $violations): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_database_safety_avoids_mkdir_for_db_log() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "database-safety.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "public static function logOperation(string $operation, array $result): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_approval_queue_manager_avoids_mkdir_for_queue_save() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "approval-queue-manager.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function saveQueue(array $queue): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint


def test_autonomous_incident_manager_avoids_mkdir_for_logs_and_evidence() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "incident-manager.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function preserveEvidence(string $incidentId, array $context): bool" in endpoint
    assert "private static function haltNonCriticalWork(string $incidentId): bool" in endpoint
    assert "private static function log(array $incident): bool" in endpoint
    assert "incident-' . $incidentId . '-context.json" in endpoint
    assert "if (!is_dir($evidenceDir) || !is_writable($evidenceDir))" in endpoint


def test_autonomous_maintenance_controller_avoids_mkdir_for_control_files() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "maintenance-controller.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "public static function pauseAll(string $reason = ''): bool" in endpoint
    assert "public static function pauseAgent(string $agent, string $reason = ''): bool" in endpoint
    assert "public static function enableReadonly(string $reason = ''): bool" in endpoint
    assert "public static function emergencyStop(): bool" in endpoint
    assert "public static function defineChangeWindow(string $dayOfWeek, string $startTime, string $endTime): bool" in endpoint
    assert "private static function writeControlFile(string $path, array $payload): bool" in endpoint
    assert "if (!is_dir(self::CONTROL_DIR) || !is_writable(self::CONTROL_DIR))" in endpoint


def test_autonomous_backup_manager_uses_explicit_directory_guards() -> None:
    endpoint = (ROOT / "api" / "autonomous" / "backup-manager.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "private static function appendManifest(array $backup): bool" in endpoint
    assert "private static function ensureDirectory(string $dir): bool" in endpoint
    assert "private static function isWritableParent(string $path): bool" in endpoint
    assert "'reason' => 'backup_target_unavailable'" in endpoint
    assert "$backup['manifest_updated'] = self::appendManifest($backup);" in endpoint


def test_gamification_status_avoids_mkdir_for_summary_snapshot() -> None:
    endpoint = (ROOT / "api" / "gamification" / "status.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function gms_write_summary(array $payload): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "gms_write_summary($payload);" in endpoint


def test_agent_cron_dispatcher_avoids_mkdir_for_log_file() -> None:
    endpoint = (ROOT / "api" / "agent" / "cron-dispatcher.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function cd_log(string $task, string $msg): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "return file_put_contents($dir . '/cron-dispatcher.log', $line, FILE_APPEND | LOCK_EX) !== false;" in endpoint


def test_agent_autonomous_status_lib_avoids_mkdir_for_self_healing_log() -> None:
    endpoint = (ROOT / "api" / "agent" / "autonomous-status-lib.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function svas_append_self_healing_attempt(array $record): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "return file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . \"\\n\", FILE_APPEND | LOCK_EX) !== false;" in endpoint


def test_admin_sync_critical_files_uses_atomic_write_without_mkdir() -> None:
    endpoint = (ROOT / "admin" / "sync-critical-files.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function sync_write_file_atomic(string $path, string $content): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "$tempPath = $path . '.tmp';" in endpoint
    assert "if (!sync_write_file_atomic($local_path, $content))" in endpoint


def test_olist_webhook_processor_avoids_mkdir_for_log_file() -> None:
    endpoint = (ROOT / "api" / "olist" / "webhook-processor.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function log_event($action, $data = []): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "return file_put_contents($log, $line, FILE_APPEND | LOCK_EX) !== false;" in endpoint


def test_sync_full_sync_avoids_mkdir_for_price_log() -> None:
    endpoint = (ROOT / "api" / "sync" / "full-sync.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function full_sync_append_log(array $payload): bool" in endpoint
    assert "if (!is_dir($logDir) || !is_writable($logDir))" in endpoint
    assert "full_sync_append_log([" in endpoint
    assert "FILE_APPEND | LOCK_EX" in endpoint


def test_catalog_products_avoids_mkdir_for_tiny_price_cache() -> None:
    endpoint = (ROOT / "api" / "catalog" / "products.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function svcat_write_price_cache(string $path, array $map): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "svcat_write_price_cache($cache, $map);" in endpoint
    assert "file_put_contents($path, json_encode($map, JSON_UNESCAPED_UNICODE), LOCK_EX)" in endpoint


def test_orders_process_validated_avoids_mkdir_for_order_log() -> None:
    endpoint = (ROOT / "api" / "orders" / "process-validated.php").read_text(encoding="utf-8")
    assert "function svop_append_log(array $order): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "file_put_contents(" in endpoint
    assert "FILE_APPEND | LOCK_EX" in endpoint


def test_orders_create_v2_avoids_mkdir_for_legacy_order_log() -> None:
    endpoint = (ROOT / "api" / "orders" / "create-v2.php").read_text(encoding="utf-8")
    assert "function svo_append_legacy_order_log(array $order): bool" in endpoint
    assert "if (!is_dir($logDir) || !is_writable($logDir))" in endpoint
    assert "file_put_contents(" in endpoint
    assert "FILE_APPEND | LOCK_EX" in endpoint


def test_catalog_signal_avoids_mkdir_and_surfaces_storage_unavailability() -> None:
    endpoint = (ROOT / "api" / "catalog" / "signal.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function svsig_write(string $path, array $signals): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "signal_storage_unavailable" in endpoint
    assert "svsig_write($signalPath, $signals)" in endpoint


def test_ml_client_avoids_mkdir_for_token_storage() -> None:
    endpoint = (ROOT / "api" / "ml" / "client.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function ml_tokens_writable(): bool" in endpoint
    assert "return is_dir($dir) && is_writable($dir);" in endpoint
    assert "Diretorio de tokens ML indisponivel para gravacao." in endpoint


def test_admin_force_git_pull_avoids_mkdir_for_log_file() -> None:
    endpoint = (ROOT / "admin" / "force-git-pull.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "function force_pull_log(string $path, string $line): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "return file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;" in endpoint


def test_ml_products_avoids_mkdir_for_publish_log() -> None:
    endpoint = (ROOT / "api" / "ml" / "products.php").read_text(encoding="utf-8")
    assert "function ml_publish_log(array $entry): bool" in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "return file_put_contents($dir . '/ml-publish.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . \"\\n\", FILE_APPEND | LOCK_EX) !== false;" in endpoint


def test_send_notifications_avoids_mkdir_for_notification_log() -> None:
    endpoint = (ROOT / "scripts" / "send-notifications.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "return file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) !== false;" in endpoint


def test_roi_engine_avoids_mkdir_for_json_report() -> None:
    endpoint = (ROOT / "scripts" / "roi-engine.php").read_text(encoding="utf-8")
    assert "@mkdir" not in endpoint
    assert "if (!is_dir($dir) || !is_writable($dir))" in endpoint
    assert "if (file_put_contents(" in endpoint
    assert "return '';" in endpoint


def test_agent_lock_manager_avoids_mkdir_for_lock_dir() -> None:
    endpoint = (ROOT / "scripts" / "agent-lock-manager.php").read_text(encoding="utf-8")
    assert "mkdir(" not in endpoint
    assert "private function lockDirReady(): bool" in endpoint
    assert "return is_dir($this->lockDir) && is_writable($this->lockDir);" in endpoint
    assert "if (!$this->lockDirReady())" in endpoint
    assert "return file_put_contents($lockFile, json_encode($lockData), LOCK_EX) !== false;" in endpoint


def test_agent_heartbeat_monitor_avoids_mkdir_for_heartbeat_dir() -> None:
    endpoint = (ROOT / "scripts" / "agent-heartbeat-monitor.php").read_text(encoding="utf-8")
    assert "mkdir(" not in endpoint
    assert "private function heartbeatDirReady(): bool" in endpoint
    assert "return is_dir($this->heartbeatDir) && is_writable($this->heartbeatDir);" in endpoint
    assert "public function recordHeartbeat(string $agentId): bool" in endpoint
    assert "if (!$this->heartbeatDirReady())" in endpoint
    assert "return file_put_contents($file, json_encode($data), LOCK_EX) !== false;" in endpoint


def test_performance_optimizer_avoids_mkdir_for_cache_dir() -> None:
    endpoint = (ROOT / "scripts" / "performance-optimizer.php").read_text(encoding="utf-8")
    assert "mkdir(" not in endpoint
    assert "private function cacheDirReady()" in endpoint
    assert "return is_dir($this->cacheDir) && is_writable($this->cacheDir);" in endpoint
    assert "if (!$this->cacheDirReady())" in endpoint


def test_disaster_recovery_avoids_mkdir_for_backup_dir() -> None:
    endpoint = (ROOT / "scripts" / "disaster-recovery.php").read_text(encoding="utf-8")
    assert "mkdir(" not in endpoint
    assert "private function backupDirReady()" in endpoint
    assert "return is_dir($this->backupDir) && is_writable($this->backupDir);" in endpoint
    assert "if (!$this->backupDirReady())" in endpoint


def test_mcp_server_avoids_mkdir_for_logs_and_write_tool() -> None:
    endpoint = (ROOT / "scripts" / "mcp-server.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def logs_dir_ready() -> bool:" in endpoint
    assert "def parent_dir_ready(path: Path) -> bool:" in endpoint
    assert "if logs_dir_ready():" in endpoint
    assert 'return {"error": f"Diretorio indisponivel para escrita: {file_path.parent}"}' in endpoint


def test_task_queue_lib_avoids_mkdir_for_queue_files() -> None:
    endpoint = (ROOT / "scripts" / "task_queue_lib.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert 'raise FileNotFoundError(f"Diretorio da fila indisponivel: {path.parent}")' in endpoint
    assert "path.write_text(" in endpoint


def test_metrics_collector_avoids_mkdir_for_metrics_log() -> None:
    endpoint = (ROOT / "scripts" / "metrics-collector.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def metrics_dir_ready(self) -> bool:" in endpoint
    assert "return self.metrics_file.parent.is_dir() and os.access(self.metrics_file.parent, os.W_OK)" in endpoint
    assert "if not self.metrics_dir_ready():" in endpoint
    assert "return False" in endpoint


def test_observability_suite_avoids_mkdir_for_logs_dir() -> None:
    endpoint = (ROOT / "scripts" / "observability-suite.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def logs_dir_ready(self) -> bool:" in endpoint
    assert "return self.logs_dir.is_dir() and self.logs_dir.exists()" in endpoint
    assert "if not self.logs_dir_ready():" in endpoint


def test_rollback_manager_avoids_mkdir_for_rollback_log() -> None:
    endpoint = (ROOT / "scripts" / "rollback-manager.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def log_dir_ready(self):" in endpoint
    assert "return self.rollback_log.parent.is_dir() and os.access(self.rollback_log.parent, os.W_OK)" in endpoint
    assert "if not self.log_dir_ready():" in endpoint
    assert "return False" in endpoint


def test_utils_logger_avoids_mkdir_for_pipeline_csv() -> None:
    endpoint = (ROOT / "scripts" / "utils" / "logger.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def _log_dir_ready() -> bool:" in endpoint
    assert "return LOG_DIR.is_dir() and os.access(LOG_DIR, os.W_OK)" in endpoint
    assert "if not _log_dir_ready():" in endpoint
    assert "if _log_dir_ready():" in endpoint


def test_utils_config_avoids_mkdir_on_import() -> None:
    endpoint = (ROOT / "scripts" / "utils" / "config.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(dir_path: Path) -> bool:" in endpoint
    assert "PATHS_READY = {" in endpoint
    assert "'storage': dir_ready(STORAGE_DIR)" in endpoint


def test_mcp_local_autostart_avoids_mkdir_for_reports_dir() -> None:
    endpoint = (ROOT / "scripts" / "mcp-local-autostart.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert 'raise FileNotFoundError(f"Report directory unavailable: {REPORTS_DIR}")' in endpoint
    assert "path.write_text(" in endpoint


def test_mcp_remote_validation_avoids_mkdir_for_reports_dir() -> None:
    endpoint = (ROOT / "scripts" / "mcp-remote-validation.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert 'raise FileNotFoundError(f"Report directory unavailable: {REPORTS_DIR}")' in endpoint
    assert "path.write_text(" in endpoint


def test_log_simulator_avoids_mkdir_for_logs_dir() -> None:
    endpoint = (ROOT / "scripts" / "log-simulator.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "if not dir_ready(log_dir):" in endpoint
    assert "if not dir_ready(execution_dir):" in endpoint
    assert "return False" in endpoint


def test_log_health_checker_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "log-health-checker.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de logs indisponível para relatório: {report_dir}")' in endpoint
    assert "json.dump(report, f, indent=2, ensure_ascii=False)" in endpoint


def test_agent_operations_worker_avoids_mkdir_for_operational_dirs() -> None:
    endpoint = (ROOT / "scripts" / "agent-operations-worker.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert 'raise FileNotFoundError(f"Diretórios operacionais indisponíveis: {\', \'.join(missing)}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório indisponível para escrita: {path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de heartbeat indisponível: {HEARTBEAT_DIR}")' in endpoint


def test_system_health_check_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "system-health-check.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {report_file.parent}")' in endpoint


def test_deploy_validator_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "deploy-validator.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {report_path.parent}")' in endpoint
    assert "report_path.write_text(" in endpoint


def test_deploy_diagnostic_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "deploy-diagnostic.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {report_path.parent}")' in endpoint
    assert "report_path.write_text(" in endpoint


def test_stock_alerts_audit_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "stock-alerts-audit.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {REPORT_JSON.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {REPORT_MD.parent}")' in endpoint
    assert "REPORT_JSON.write_text(" in endpoint
    assert "REPORT_MD.write_text(" in endpoint


def test_product_page_indexability_audit_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "product-page-indexability-audit.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {REPORT_JSON.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {REPORT_MD.parent}")' in endpoint
    assert "REPORT_JSON.write_text(" in endpoint
    assert "REPORT_MD.write_text(" in endpoint


def test_seo_automation_audit_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "seo-automation-audit.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {REPORT_JSON.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {REPORT_MD.parent}")' in endpoint
    assert "REPORT_JSON.write_text(" in endpoint
    assert "REPORT_MD.write_text(" in endpoint


def test_run_autonomy_phases_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "run-autonomy-phases.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {json_path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {md_path.parent}")' in endpoint
    assert "json_path.write_text(" in endpoint
    assert "md_path.write_text(" in endpoint


def test_deploy_production_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "deploy_production.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {report_file.parent}")' in endpoint
    assert "with open(report_file, 'w') as f:" in endpoint


def test_verify_marketplace_upload_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "verify_marketplace_upload.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {VERIFICATION_REPORT.parent}")' in endpoint
    assert "with VERIFICATION_REPORT.open('w', encoding='utf-8') as f:" in endpoint


def test_ml_readiness_report_avoids_mkdir_for_output_path() -> None:
    endpoint = (ROOT / "scripts" / "ml-readiness-report.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {output_path.parent}")' in endpoint
    assert "output_path.write_text(text + \"\\n\", encoding=\"utf-8\")" in endpoint


def test_shopee_readiness_report_avoids_mkdir_for_output_path() -> None:
    endpoint = (ROOT / "scripts" / "shopee-readiness-report.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {output_path.parent}")' in endpoint
    assert "output_path.write_text(text + \"\\n\", encoding=\"utf-8\")" in endpoint


def test_heartbeat_executor_avoids_mkdir_for_log_dir() -> None:
    endpoint = (ROOT / "scripts" / "heartbeat-executor.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def log_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {logfile.parent}")' in endpoint
    assert "logfile.write_text(" in endpoint


def test_vulnerability_scanner_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "vulnerability-scanner.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {self.report_file.parent}")' in endpoint
    assert "with open(self.report_file, 'a') as f:" in endpoint


def test_auto_sync_daemon_avoids_mkdir_for_log_dir() -> None:
    endpoint = (ROOT / "scripts" / "auto-sync-daemon.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def log_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {LOG_FILE.parent}")' in endpoint
    assert 'with open(LOG_FILE, "a", encoding="utf-8") as f:' in endpoint


def test_olist_headless_login_avoids_mkdir_for_log_dir() -> None:
    endpoint = (ROOT / "scripts" / "olist-headless-login.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def log_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {LOG_FILE.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {screenshot.parent}")' in endpoint
    assert "driver.save_screenshot(str(screenshot))" in endpoint


def test_olist_direct_login_avoids_mkdir_for_log_and_tokens_dir() -> None:
    endpoint = (ROOT / "scripts" / "olist-direct-login.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {LOG_FILE.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de tokens indisponível: {CONFIG_FILE.parent}")' in endpoint
    assert "with open(CONFIG_FILE, 'w') as f:" in endpoint


def test_validate_20_products_avoids_mkdir_for_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "validate_20_products.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def report_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {report_file.parent}")' in endpoint
    assert "with report_file.open('w', encoding='utf-8') as f:" in endpoint


def test_olist_selenium_login_avoids_mkdir_for_result_dir() -> None:
    endpoint = (ROOT / "scripts" / "olist-selenium-login.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def result_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de resultado indisponível: {result_file.parent}")' in endpoint
    assert "with open(result_file, 'w', encoding='utf-8') as f:" in endpoint


def test_olist_sync_manual_avoids_mkdir_for_result_dir() -> None:
    endpoint = (ROOT / "scripts" / "olist-sync-manual.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def result_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de resultado indisponível: {result_file.parent}")' in endpoint
    assert "with open(result_file, 'w', encoding='utf-8') as f:" in endpoint


def test_olist_oauth_login_avoids_mkdir_for_result_dir() -> None:
    endpoint = (ROOT / "scripts" / "olist-oauth-login.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def result_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de resultado indisponível: {result_file.parent}")' in endpoint
    assert "with open(result_file, 'w', encoding='utf-8') as f:" in endpoint


def test_olist_sync_chrome_avoids_mkdir_for_result_dir() -> None:
    endpoint = (ROOT / "scripts" / "olist-sync-chrome.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "import os" in endpoint
    assert "def result_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de resultado indisponível: {result_file.parent}")' in endpoint
    assert "with open(result_file, 'w', encoding='utf-8') as f:" in endpoint


def test_auto_oauth_login_avoids_mkdir_for_log_and_tokens_dir() -> None:
    endpoint = (ROOT / "scripts" / "auto-oauth-login.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {LOG_FILE.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {screenshot_path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de tokens indisponível: {CONFIG_FILE.parent}")' in endpoint
    assert "driver.save_screenshot(str(screenshot_path))" in endpoint


def test_auto_complete_olist_avoids_mkdir_for_log_and_tokens_dir() -> None:
    endpoint = (ROOT / "scripts" / "auto-complete-olist.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de log indisponível: {LOG_FILE.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de tokens indisponível: {CONFIG_FILE.parent}")' in endpoint
    assert "with open(CONFIG_FILE, 'w') as f:" in endpoint


def test_download_olist_images_v2_avoids_mkdir_for_output_dirs() -> None:
    endpoint = (ROOT / "scripts" / "download-olist-images-v2.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "def parent_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.is_dir() and os.access(path, os.W_OK)" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de saída indisponível: {self.output_dir}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório do produto indisponível: {folder}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de auditoria indisponível: {filepath.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de mapeamento indisponível: {filepath.parent}")' in endpoint


def test_export_olist_images_csv_avoids_mkdir_for_output_dirs() -> None:
    endpoint = (ROOT / "scripts" / "export-olist-images-csv.py").read_text(encoding="utf-8")
    assert ".mkdir(" not in endpoint
    assert "def output_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório CSV indisponível: {OUT_CSV.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório JSON indisponível: {OUT_JSON.parent}")' in endpoint
    assert 'with OUT_CSV.open("w", newline="", encoding="utf-8-sig") as csv_file:' in endpoint
    assert 'with OUT_JSON.open("w", encoding="utf-8") as json_file:' in endpoint


def test_sync_olist_images_hardens_report_dirs_without_mkdir_for_reports() -> None:
    endpoint = (ROOT / "scripts" / "sync-olist-images.py").read_text(encoding="utf-8")
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "def parent_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.is_dir() and os.access(path, os.W_OK)" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de entrada convertida indisponível: {path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de downloads indisponível: {path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório final indisponível: {path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório JSON indisponível: {path.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatórios indisponível: {args.reports_dir}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de imagens indisponível: {args.storage_images}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório SQL indisponível: {sql_path.parent}")' in endpoint


def test_repair_olist_images_hardens_sql_and_cache_dirs() -> None:
    endpoint = (ROOT / "scripts" / "repair-olist-images.py").read_text(encoding="utf-8")
    assert "def parent_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório SQL indisponível: {sql_file.parent}")' in endpoint
    assert 'raise FileNotFoundError(f"Diretório de cache indisponível: {cache_file.parent}")' in endpoint
    assert "with open(sql_file, 'w', encoding='utf-8') as f:" in endpoint
    assert "with open(cache_file, 'w', encoding='utf-8') as f:" in endpoint


def test_import_shopee_hardens_output_dirs() -> None:
    endpoint = (ROOT / "scripts" / "import_shopee.py").read_text(encoding="utf-8")
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "def parent_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.is_dir() and os.access(path, os.W_OK)" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert "raise FileNotFoundError(f'Diretório SKU indisponível: {sku_folder}')" in endpoint
    assert "raise FileNotFoundError(f'Diretório de mapeamento indisponível: {SKU_MAPPING_FILE.parent}')" in endpoint
    assert "raise FileNotFoundError(f'Diretório do Excel indisponível: {output_path.parent}')" in endpoint


def test_generate_ai_images_hardens_output_dirs() -> None:
    endpoint = (ROOT / "scripts" / "generate_ai_images.py").read_text(encoding="utf-8")
    assert "def dir_ready(path: Path) -> bool:" in endpoint
    assert "def parent_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.is_dir() and os.access(path, os.W_OK)" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert "raise FileNotFoundError(f'Diretório de destino indisponível: {destination_path.parent}')" in endpoint
    assert "raise FileNotFoundError(f'Diretório de saída indisponível: {output_path.parent}')" in endpoint
    assert "raise FileNotFoundError(f'Diretório de download indisponível: {output_path.parent}')" in endpoint
    assert "raise FileNotFoundError(f'Diretório de variante indisponível: {destination_path.parent}')" in endpoint
    assert "raise FileNotFoundError(f'Diretório processado indisponível: {output_dir}')" in endpoint


def test_upload_images_hardens_output_mapping_dir() -> None:
    endpoint = (ROOT / "scripts" / "upload_images.py").read_text(encoding="utf-8")
    assert "def output_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert "raise FileNotFoundError(f'Diretório de mapeamento indisponível: {OUTPUT_MAPPING_FILE.parent}')" in endpoint
    assert "with OUTPUT_MAPPING_FILE.open('w', newline='', encoding='utf-8') as csvfile:" in endpoint


def test_process_images_hardens_processed_output_dir() -> None:
    endpoint = (ROOT / "scripts" / "process_images.py").read_text(encoding="utf-8")
    assert "def output_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert "raise FileNotFoundError(f'Diretório processado indisponível: {target_path.parent}')" in endpoint
    assert "image.save(target_path, format='JPEG', quality=92, optimize=True)" in endpoint


def test_publish_to_marketplace_hardens_output_report_dir() -> None:
    endpoint = (ROOT / "scripts" / "publish-to-marketplace.py").read_text(encoding="utf-8")
    assert "def output_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de relatório indisponível: {out_path.parent}")' in endpoint
    assert "out_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding=\"utf-8\")" in endpoint


def test_quick_deploy_ecommerce_hardens_page_output_dirs() -> None:
    endpoint = (ROOT / "scripts" / "quick-deploy-ecommerce.py").read_text(encoding="utf-8")
    assert "def output_dir_ready(path: Path) -> bool:" in endpoint
    assert "return path.parent.is_dir() and os.access(path.parent, os.W_OK)" in endpoint
    assert 'raise FileNotFoundError(f"Diretório de página indisponível: {arquivo.parent}")' in endpoint
    assert "arquivo.write_text(conteudo, encoding='utf-8')" in endpoint
