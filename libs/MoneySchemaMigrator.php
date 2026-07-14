<?php
/**
 * MoneySchemaMigrator
 *
 * Widens money columns that were originally DECIMAL(10,2)/DECIMAL(12,2)
 * (hard ceiling 99,999,999.99 / ~9.99B) so large print jobs can store
 * billion-plus amounts, then repairs rows that were truncated at the old
 * ceiling.
 *
 * Idempotent and safe to call on every page load.
 */
class MoneySchemaMigrator
{
    private static $checked = false;

    public static function ensure(PDO $pdo): bool
    {
        if (self::$checked) {
            return true;
        }

        try {
            self::widenColumns($pdo);
            self::repairInvoiceTotals($pdo);
            self::repairWorkOrderSnapshots($pdo);
            self::repairEstimationPressLabourItems($pdo);
        } catch (Throwable $e) {
            error_log('MoneySchemaMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function targetColumns(): array
    {
        return [
            'invoices' => [
                'total_amount' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'shipping_fee' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'subtotal' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'tax_amount' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'discount' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'paid_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0.00',
                'balance' => 'DECIMAL(15,2) NOT NULL DEFAULT 0.00',
            ],
            'work_orders' => [
                'total_cost_snapshot' => 'DECIMAL(15,2) NULL DEFAULT NULL',
                'amount_paid_snapshot' => 'DECIMAL(15,2) NULL DEFAULT NULL',
                'balance_snapshot' => 'DECIMAL(15,2) NULL DEFAULT NULL',
            ],
            'estimation_items' => [
                'unit_price' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'total_price' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'estimation_press_labour' => [
                'make_ready_rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'make_ready_total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'running_rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'running_total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'machine_total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'estimation_prepress_labour' => [
                'rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'estimation_finishing_labour' => [
                'rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'estimation_binding_materials' => [
                'rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'estimation_ink_colours' => [
                'rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'estimation_papers' => [
                'paper_rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
                'paper_total' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'material_rates' => [
                'rate' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'products' => [
                'price' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'services' => [
                'price' => 'DECIMAL(15,2) NULL DEFAULT 0.00',
            ],
            'projects' => [
                'budget_amount' => 'DECIMAL(15,2) NULL DEFAULT NULL',
            ],
            'task_expenses' => [
                'amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0.00',
            ],
        ];
    }

    private static function widenColumns(PDO $pdo): void
    {
        foreach (self::targetColumns() as $table => $columns) {
            if (!self::tableExists($pdo, $table)) {
                continue;
            }
            $types = self::fetchColumnTypes($pdo, $table);
            foreach ($columns as $column => $definition) {
                $type = strtolower((string) ($types[$column] ?? ''));
                if ($type === '') {
                    continue;
                }
                if (preg_match('/^decimal\((\d+),/', $type, $matches) && (int) $matches[1] >= 15) {
                    continue;
                }
                $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
            }
        }
    }

    private static function repairInvoiceTotals(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'invoices')) {
            return;
        }

        $pdo->exec(
            "UPDATE `invoices`
             SET `total_amount` = ROUND(
                 COALESCE(`subtotal`, 0)
                 + COALESCE(`tax_amount`, 0)
                 + COALESCE(`shipping_fee`, 0)
                 - COALESCE(`discount`, 0)
             , 2)
             WHERE ABS(
                 COALESCE(`total_amount`, 0)
                 - (
                     COALESCE(`subtotal`, 0)
                     + COALESCE(`tax_amount`, 0)
                     + COALESCE(`shipping_fee`, 0)
                     - COALESCE(`discount`, 0)
                 )
             ) > 0.01
               AND (
                   COALESCE(`total_amount`, 0) >= 99999999.99
                   OR ABS(
                       COALESCE(`total_amount`, 0)
                       - (COALESCE(`paid_amount`, 0) + COALESCE(`balance`, 0))
                   ) > 0.01
               )"
        );
    }

    private static function repairWorkOrderSnapshots(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'work_orders') || !self::tableExists($pdo, 'invoices')) {
            return;
        }

        $pdo->exec(
            "UPDATE `work_orders` wo
             INNER JOIN `invoices` i ON i.id = wo.invoice_id
             SET
                wo.total_cost_snapshot = i.total_amount,
                wo.amount_paid_snapshot = i.paid_amount,
                wo.balance_snapshot = i.balance
             WHERE
                ABS(COALESCE(wo.total_cost_snapshot, 0) - COALESCE(i.total_amount, 0)) > 0.01
                OR ABS(COALESCE(wo.amount_paid_snapshot, 0) - COALESCE(i.paid_amount, 0)) > 0.01
                OR ABS(COALESCE(wo.balance_snapshot, 0) - COALESCE(i.balance, 0)) > 0.01"
        );
    }

    private static function repairEstimationPressLabourItems(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'estimation_items')
            || !self::tableExists($pdo, 'estimation_press_labour')) {
            return;
        }

        $pdo->exec(
            "UPDATE `estimation_items` ei
             INNER JOIN (
                SELECT estimation_id, ROUND(SUM(COALESCE(machine_total, 0)), 2) AS press_total
                FROM `estimation_press_labour`
                GROUP BY estimation_id
             ) pl ON pl.estimation_id = ei.estimation_id
             SET
                ei.unit_price = pl.press_total,
                ei.total_price = pl.press_total
             WHERE ei.description = 'Press Labour'
               AND pl.press_total > 0
               AND ABS(COALESCE(ei.total_price, 0) - pl.press_total) > 0.01"
        );
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string, string>
     */
    private static function fetchColumnTypes(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[strtolower((string) $row['COLUMN_NAME'])] = (string) $row['COLUMN_TYPE'];
        }
        return $types;
    }
}
