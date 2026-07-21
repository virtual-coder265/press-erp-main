<?php
/**
 * ProductionLabourMigrator
 *
 * Master catalog for estimation labour tasks (pre-press, press, finishing).
 * Idempotent — safe to call on estimation wizard load.
 */
class ProductionLabourMigrator
{
    private static $checked = false;

    public static function ensure(PDO $pdo): bool
    {
        if (self::$checked) {
            return true;
        }

        try {
            self::createTables($pdo);
            self::seedDefaults($pdo);
        } catch (Throwable $e) {
            error_log('ProductionLabourMigrator failed: ' . $e->getMessage());
        }

        self::$checked = true;
        return true;
    }

    private static function createTables(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_labour_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                section VARCHAR(20) NOT NULL,
                name VARCHAR(255) NOT NULL,
                unit VARCHAR(50) NOT NULL DEFAULT 'hrs',
                measure_type VARCHAR(50) NULL DEFAULT NULL,
                default_iph INT NULL DEFAULT NULL,
                description TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_labour_section_name (section, name),
                KEY idx_labour_section (section)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_labour_rates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                make_ready_rate DECIMAL(15,2) NULL DEFAULT NULL,
                running_rate DECIMAL(15,2) NULL DEFAULT NULL,
                effective_date DATE NOT NULL,
                created_by INT NULL DEFAULT NULL,
                KEY idx_labour_rate_task (task_id),
                CONSTRAINT fk_labour_rate_task
                    FOREIGN KEY (task_id) REFERENCES production_labour_tasks(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private static function seedDefaults(PDO $pdo): void
    {
        $prepress = [
            'Design',
            'Keying',
            'Laying Out',
            'Reading',
            'Proof Making',
            'Film Assembly',
            'Platemaking',
        ];

        $finishing = [
            ['Numbering', 'numbers', null],
            ['Perforating', 'perfs', null],
            ['Saddle Stitching', 'books', null],
            ['Perfect Binding', 'books', null],
            ['Paper Cutting', 'reams', null],
            ['Trimming', 'items', null],
            ['Case Making', 'items', null],
            ['Gold Blocking', 'items', null],
        ];

        $insertTask = $pdo->prepare("
            INSERT IGNORE INTO production_labour_tasks (section, name, unit, measure_type, default_iph)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($prepress as $name) {
            $insertTask->execute(['prepress', $name, 'hrs', null, null]);
        }

        foreach ($finishing as [$name, $measure, $iph]) {
            $insertTask->execute(['finishing', $name, 'hrs', $measure, $iph]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fetchTasks(PDO $pdo): array
    {
        self::ensure($pdo);

        $stmt = $pdo->query("
            SELECT t.*,
                   r.rate,
                   r.make_ready_rate,
                   r.running_rate
            FROM production_labour_tasks t
            LEFT JOIN (
                SELECT task_id, rate, make_ready_rate, running_rate
                FROM production_labour_rates
                WHERE id IN (SELECT MAX(id) FROM production_labour_rates GROUP BY task_id)
            ) r ON r.task_id = t.id
            ORDER BY t.section, t.name
        ");

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
