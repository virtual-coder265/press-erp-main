<?php
/**
 * MaterialSpecMigrator
 *
 * Adds structured specification columns on materials and material_id links
 * on estimation detail tables for catalog-backed fetching in the wizard.
 */
class MaterialSpecMigrator
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
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials'"
            );
            if ((int) $tableStmt->fetchColumn() === 0) {
                self::$checked = true;
                return false;
            }

            $existingColumns = self::fetchColumns($pdo, 'materials');

            $materialColumns = [
                'material_kind' => "VARCHAR(30) NULL DEFAULT NULL COMMENT 'paper, plate, film, separation, ink, binding, consumable'",
                'stock_type' => "VARCHAR(80) NULL DEFAULT NULL COMMENT 'Product family e.g. Manilla, Book Cloth'",
                'grammage' => "DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'Paper gsm'",
                'color' => "VARCHAR(40) NULL DEFAULT NULL COMMENT 'Paper/ink/cloth colour'",
                'dimensions' => "VARCHAR(40) NULL DEFAULT NULL COMMENT 'A4, A1, 605x745mm'",
                'thickness_mm' => "DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'Board/wire thickness'",
                'brand' => "VARCHAR(60) NULL DEFAULT NULL COMMENT 'Ink/toner brand'",
            ];

            foreach ($materialColumns as $col => $definition) {
                if (!in_array(strtolower($col), $existingColumns, true)) {
                    $pdo->exec("ALTER TABLE `materials` ADD COLUMN `{$col}` {$definition}");
                }
            }

            self::ensureEstimationColumn($pdo, 'estimation_papers', 'material_id', 'INT NULL DEFAULT NULL');
            self::ensureEstimationColumn($pdo, 'estimation_ink_colours', 'material_id', 'INT NULL DEFAULT NULL');
        } catch (Throwable $e) {
            error_log('MaterialSpecMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
    }

    private static function ensureEstimationColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $tableStmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
        );
        if ((int) $tableStmt->fetchColumn() === 0) {
            return;
        }

        $existingColumns = self::fetchColumns($pdo, $table);
        if (in_array(strtolower($column), $existingColumns, true)) {
            return;
        }

        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    /**
     * @return string[]
     */
    private static function fetchColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
