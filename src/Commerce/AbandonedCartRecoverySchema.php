<?php
declare(strict_types=1);

/**
 * Extend checkout_abandonments with cross-device recovery fields.
 *
 * Kept in the commerce domain instead of the visual includes layer so schema
 * evolution does not masquerade as a storefront change in policy checks.
 */
function svacr_ensure_restore_schema(PDO $pdo): void
{
    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM checkout_abandonments');
    foreach ($stmt->fetchAll() as $row) {
        $columns[$row['Field']] = true;
    }

    if (!isset($columns['recovery_token_hash'])) {
        $pdo->exec('ALTER TABLE checkout_abandonments ADD COLUMN recovery_token_hash CHAR(64) NULL');
    }
    if (!isset($columns['recovery_token_expires_at'])) {
        $pdo->exec('ALTER TABLE checkout_abandonments ADD COLUMN recovery_token_expires_at DATETIME NULL');
    }
    if (!isset($columns['restored_at'])) {
        $pdo->exec('ALTER TABLE checkout_abandonments ADD COLUMN restored_at DATETIME NULL');
    }

    $indexes = [];
    $idxStmt = $pdo->query('SHOW INDEX FROM checkout_abandonments');
    foreach ($idxStmt->fetchAll() as $row) {
        $indexes[$row['Key_name']] = true;
    }
    if (!isset($indexes['idx_checkout_abandon_recovery_token'])) {
        $pdo->exec('ALTER TABLE checkout_abandonments ADD INDEX idx_checkout_abandon_recovery_token (recovery_token_hash)');
    }
    if (!isset($indexes['idx_checkout_abandon_restored'])) {
        $pdo->exec('ALTER TABLE checkout_abandonments ADD INDEX idx_checkout_abandon_restored (restored_at)');
    }
}