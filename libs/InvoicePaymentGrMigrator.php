<?php
/**
 * InvoicePaymentGrMigrator
 *
 * Adds General Receipt (GR) tracking to invoice_payments:
 *  - gr_number       → manually entered, unique per payment transaction
 *  - invoice_item_id → optional link to a specific product/service line
 */
class InvoicePaymentGrMigrator
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
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_payments'"
            );
            if ((int) $tableStmt->fetchColumn() === 0) {
                self::$checked = true;
                return false;
            }

            $existing = self::fetchColumns($pdo);

            if (!in_array('gr_number', $existing, true)) {
                $pdo->exec(
                    "ALTER TABLE `invoice_payments`
                     ADD COLUMN `gr_number` VARCHAR(50) NULL DEFAULT NULL
                     COMMENT 'Manually entered General Receipt number, unique per transaction'
                     AFTER `transaction_id`"
                );
            }

            if (!in_array('invoice_item_id', $existing, true)) {
                $pdo->exec(
                    "ALTER TABLE `invoice_payments`
                     ADD COLUMN `invoice_item_id` INT NULL DEFAULT NULL
                     COMMENT 'Optional link to the product/service this payment covers'
                     AFTER `gr_number`"
                );
            }

            if (!self::indexExists($pdo, 'invoice_payments', 'uq_invoice_payments_gr_number')) {
                $pdo->exec(
                    "ALTER TABLE `invoice_payments`
                     ADD UNIQUE KEY `uq_invoice_payments_gr_number` (`gr_number`)"
                );
            }

            if (!self::indexExists($pdo, 'invoice_payments', 'fk_invoice_payments_invoice_item_id')) {
                $itemsTable = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_items'"
                )->fetchColumn();
                if ((int) $itemsTable > 0) {
                    $pdo->exec(
                        "ALTER TABLE `invoice_payments`
                         ADD KEY `fk_invoice_payments_invoice_item_id` (`invoice_item_id`),
                         ADD CONSTRAINT `fk_invoice_payments_invoice_item_id`
                             FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`)
                             ON DELETE SET NULL"
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('InvoicePaymentGrMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
    }

    /**
     * Validate and normalize a GR number before insert.
     *
     * @throws InvalidArgumentException
     */
    public static function validateGrNumber(PDO $pdo, string $grNumber, ?int $excludePaymentId = null): string
    {
        $grNumber = trim($grNumber);
        if ($grNumber === '') {
            throw new InvalidArgumentException('General Receipt (GR) number is required for every payment transaction.');
        }
        if (strlen($grNumber) > 50) {
            throw new InvalidArgumentException('GR number must be 50 characters or fewer.');
        }

        $sql = 'SELECT id FROM invoice_payments WHERE gr_number = ?';
        $params = [$grNumber];
        if ($excludePaymentId !== null && $excludePaymentId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $excludePaymentId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('GR number "' . $grNumber . '" is already recorded. Each transaction must have a unique GR number.');
        }

        return $grNumber;
    }

    /**
     * @return string[]
     */
    private static function fetchColumns(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_payments'"
        );
        return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function indexExists(PDO $pdo, string $table, string $indexName): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$table, $indexName]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
