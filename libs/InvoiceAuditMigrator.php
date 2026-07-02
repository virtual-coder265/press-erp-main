<?php
/**
 * InvoiceAuditMigrator
 *
 * Adds the columns the new invoices view / edit pages rely on:
 *
 *  - last_edited_at, last_edited_by  → audit notice
 *  - vat_percent                     → invoice now stores the VAT rate it was
 *                                      built with (mirrors what was applied
 *                                      in the source estimation's wizard)
 *
 * Idempotent: each ALTER is gated by an INFORMATION_SCHEMA lookup so it is
 * safe to call on every page load.
 */
class InvoiceAuditMigrator
{
    private static $checked = false;

    public static function ensure(PDO $pdo): bool
    {
        if (self::$checked) {
            return true;
        }

        try {
            $tableStmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'"
            );
            if ((int) $tableStmt->fetchColumn() === 0) {
                self::$checked = true;
                return false;
            }

            $existing = self::fetchColumns($pdo);

            $columnsToAdd = [
                'last_edited_at' => "TIMESTAMP NULL DEFAULT NULL COMMENT 'Most recent edit timestamp shown in the audit notice'",
                'last_edited_by' => "INT NULL DEFAULT NULL COMMENT 'User who last edited the invoice'",
                'vat_percent'    => "DECIMAL(6,2) NULL DEFAULT NULL COMMENT 'VAT % applied when the invoice was built'",
                'created_by'     => "INT NULL DEFAULT NULL COMMENT 'User who created the invoice'",
            ];

            foreach ($columnsToAdd as $col => $definition) {
                if (!in_array($col, $existing, true)) {
                    $pdo->exec("ALTER TABLE `invoices` ADD COLUMN `{$col}` {$definition}");
                }
            }

            // Backfill audit columns from the generated_date so existing
            // invoices render a meaningful "Last edited on" notice.
            $pdo->exec(
                "UPDATE `invoices`
                 SET `last_edited_at` = COALESCE(`last_edited_at`, `generated_date`)
                 WHERE `last_edited_at` IS NULL"
            );
        } catch (Throwable $e) {
            error_log('InvoiceAuditMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
    }

    /**
     * @return string[]
     */
    private static function fetchColumns(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'"
        );
        return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
