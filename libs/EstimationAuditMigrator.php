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

            require_once __DIR__ . '/MoneySchemaMigrator.php';
            MoneySchemaMigrator::ensure($pdo);
        } catch (Throwable $e) {
            // Never let the migrator break the page render; just log it.
            error_log('EstimationAuditMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
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
