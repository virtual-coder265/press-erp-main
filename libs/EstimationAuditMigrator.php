<?php
/**
 * EstimationAuditMigrator
 *
 * Lightweight runtime migrator that guarantees the `last_edited_at` and
 * `last_edited_by` audit columns exist on the `estimations` table.
 *
 * The estimation view page surfaces a "Last edited on {Date, Time} by {User}"
 * notice. Older deployments may not yet have these columns, so this helper
 * adds them lazily with INFORMATION_SCHEMA lookups (no MySQL 8 specific
 * syntax) the first time any estimation page is loaded.
 *
 * The check is cached for the request lifetime to avoid repeated queries.
 */
class EstimationAuditMigrator
{
    /** Per-request cache flag so we only run the check once. */
    private static $checked = false;

    /**
     * Ensure the audit + breakdown columns exist. Returns true when the
     * table is ready, false when the table itself is missing (very fresh
     * installs).
     */
    public static function ensure(PDO $pdo): bool
    {
        if (self::$checked) {
            return true;
        }

        try {
            // Confirm the estimations table exists at all before touching it.
            $tableStmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimations'"
            );
            if ((int) $tableStmt->fetchColumn() === 0) {
                self::$checked = true;
                return false;
            }

            $existingColumns = self::fetchColumns($pdo);

            // --- Audit columns surfaced on the view page --------------------
            if (!in_array('last_edited_at', $existingColumns, true)) {
                $pdo->exec(
                    "ALTER TABLE `estimations`
                     ADD COLUMN `last_edited_at` TIMESTAMP NULL DEFAULT NULL
                        COMMENT 'Most recent edit timestamp shown in the audit notice'"
                );
            }

            if (!in_array('last_edited_by', $existingColumns, true)) {
                $pdo->exec(
                    "ALTER TABLE `estimations`
                     ADD COLUMN `last_edited_by` INT NULL DEFAULT NULL
                        COMMENT 'User who last edited the estimation'"
                );

                // Best-effort backfill so existing rows render a sensible audit notice.
                $pdo->exec(
                    "UPDATE `estimations`
                     SET `last_edited_at` = COALESCE(`updated_at`, `created_at`),
                         `last_edited_by` = `created_by`
                     WHERE `last_edited_at` IS NULL"
                );
            }

            // --- Cost breakdown so invoices can pull pre-VAT totals + VAT --
            // We persist the values the wizard already calculates so a future
            // invoice can show "Job description: pre-VAT total" + "VAT: x%"
            // without re-running the cost math.
            $breakdownColumns = [
                'subtotal_amount'         => "DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'Sum of cost-line totals before profit, supervision and VAT'",
                'profit_margin_percent'   => "DECIMAL(6,2)  NULL DEFAULT NULL COMMENT 'Profit margin % captured from the wizard'",
                'profit_amount'           => "DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'Profit margin amount (subtotal x %)'",
                'cost_supervision_amount' => "DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'Manually entered overtime / supervision cost'",
                'cost_consumables_amount' => "DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'Manually entered consumables cost'",
                'vat_percent'             => "DECIMAL(6,2)  NULL DEFAULT NULL COMMENT 'VAT % applied in the estimation'",
                'vat_amount'              => "DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'VAT amount applied (taxable x %)'",
                'pre_vat_total'           => "DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'Taxable amount = (cost subtotal + supervision + profit). Used as the invoice line price.'",
            ];

            foreach ($breakdownColumns as $col => $definition) {
                if (!in_array($col, $existingColumns, true)) {
                    $pdo->exec("ALTER TABLE `estimations` ADD COLUMN `{$col}` {$definition}");
                }
            }

            if (!in_array('draft_origin', $existingColumns, true)) {
                $pdo->exec(
                    "ALTER TABLE `estimations`
                     ADD COLUMN `draft_origin` VARCHAR(20) NULL DEFAULT NULL
                        COMMENT 'How the wizard draft was created: autosave, manual, recovered'"
                );
            }

            // Refresh column list after possible draft_origin add.
            $existingColumns = self::fetchColumns($pdo);

            if (!in_array('draft_revision', $existingColumns, true)) {
                $pdo->exec(
                    "ALTER TABLE `estimations`
                     ADD COLUMN `draft_revision` INT NOT NULL DEFAULT 0
                        COMMENT 'Monotonic draft revision for optimistic locking'"
                );
            }

            if (!in_array('draft_content_hash', $existingColumns, true)) {
                $pdo->exec(
                    "ALTER TABLE `estimations`
                     ADD COLUMN `draft_content_hash` CHAR(64) NULL DEFAULT NULL
                        COMMENT 'SHA-256 of canonical draft_data JSON'"
                );
            }

            // Backfill: existing non-empty drafts start at revision 1.
            // Content hash is filled lazily on the next save/read using canonical JSON.
            $pdo->exec(
                "UPDATE `estimations`
                 SET `draft_revision` = 1
                 WHERE `status` = 'Draft'
                   AND `draft_data` IS NOT NULL
                   AND TRIM(`draft_data`) <> ''
                   AND TRIM(`draft_data`) <> '{}'
                   AND `draft_revision` = 0"
            );

            self::ensureDraftVersionsTable($pdo);
            self::ensureDraftVersionStepUnique($pdo);

            require_once __DIR__ . '/../includes/estimation_detail_dedup_helper.php';
            estimation_deduplicate_detail_rows($pdo);

            require_once __DIR__ . '/MoneySchemaMigrator.php';
            MoneySchemaMigrator::ensure($pdo);

            require_once __DIR__ . '/MaterialSpecMigrator.php';
            MaterialSpecMigrator::ensure($pdo);
        } catch (Throwable $e) {
            // Never let the migrator break the page render; just log it.
            error_log('EstimationAuditMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
    }

    /**
     * Create estimation_draft_versions if missing (keeps last N snapshots per draft).
     */
    private static function ensureDraftVersionsTable(PDO $pdo): void
    {
        $tableStmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimation_draft_versions'"
        );
        if ((int) $tableStmt->fetchColumn() > 0) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE `estimation_draft_versions` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `estimation_id` INT NOT NULL,
                `revision` INT NOT NULL,
                `draft_data` LONGTEXT NOT NULL,
                `draft_step` TINYINT NOT NULL DEFAULT 1,
                `content_hash` CHAR(64) NULL DEFAULT NULL,
                `saved_at` DATETIME NOT NULL,
                `saved_by` INT NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_est_revision` (`estimation_id`, `revision` DESC),
                CONSTRAINT `fk_draft_versions_estimation`
                    FOREIGN KEY (`estimation_id`) REFERENCES `estimations` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * One checkpoint row per wizard step (8 steps). Dedupe legacy rows and add a unique index.
     */
    private static function ensureDraftVersionStepUnique(PDO $pdo): void
    {
        $tableStmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimation_draft_versions'"
        );
        if ((int) $tableStmt->fetchColumn() === 0) {
            return;
        }

        try {
            $pdo->exec(
                "DELETE v1 FROM estimation_draft_versions v1
                 INNER JOIN estimation_draft_versions v2
                   ON v1.estimation_id = v2.estimation_id
                  AND v1.draft_step = v2.draft_step
                  AND v1.id < v2.id"
            );
        } catch (Throwable $e) {
            error_log('EstimationAuditMigrator draft version dedupe failed: ' . $e->getMessage());
        }

        $indexStmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'estimation_draft_versions'
               AND INDEX_NAME = 'uk_estimation_draft_step'"
        );
        if ((int) $indexStmt->fetchColumn() > 0) {
            return;
        }

        try {
            $pdo->exec(
                "ALTER TABLE estimation_draft_versions
                 ADD UNIQUE KEY uk_estimation_draft_step (estimation_id, draft_step)"
            );
        } catch (Throwable $e) {
            error_log('EstimationAuditMigrator draft step unique index failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch the current column list from INFORMATION_SCHEMA.
     *
     * @return string[]
     */
    private static function fetchColumns(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimations'"
        );
        return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
