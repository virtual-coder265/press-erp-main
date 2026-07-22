<?php
/**
 * Estimation ownership / shared-access rules.
 *
 * System Admin and Costing users can view and manage all estimations.
 * Other users with manage_estimations may only change drafts they created.
 */

if (!function_exists('estimation_user_has_shared_access')) {
    function estimation_user_has_shared_access(): bool
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'System Admin') {
            return true;
        }

        if (isset($_SESSION['role']) && $_SESSION['role'] === 'Costing') {
            return true;
        }

        return false;
    }
}

if (!function_exists('estimation_owner_scope')) {
    /**
     * Optional SQL fragment restricting rows to the current user as creator.
     *
     * @return array{sql: string, params: array<string, int>}
     */
    function estimation_owner_scope(string $column = 'created_by', string $param = 'est_owner_user'): array
    {
        if (estimation_user_has_shared_access()) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql' => " AND {$column} = :{$param}",
            'params' => [$param => (int) ($_SESSION['user_id'] ?? 0)],
        ];
    }
}

if (!function_exists('estimation_fetch_draft_row')) {
    /**
     * Load a draft estimation row if the current user may access it.
     *
     * @return array<string, mixed>|null
     */
    function estimation_fetch_draft_row(
        PDO $pdo,
        int $estId,
        string $columns = '*',
        bool $forUpdate = false
    ): ?array {
        if ($estId <= 0) {
            return null;
        }

        $owner = estimation_owner_scope('created_by', 'user');
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT {$columns}
                FROM estimations
                WHERE id = :id
                  AND status = 'Draft'{$owner['sql']}
                LIMIT 1{$lock}";

        $params = array_merge(['id' => $estId], $owner['params']);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('estimation_fetch_manageable_row')) {
    /**
     * Load an estimation row the current user may update via the wizard save flow.
     *
     * @return array<string, mixed>|null
     */
    function estimation_fetch_manageable_row(PDO $pdo, int $estId, bool $forUpdate = false): ?array
    {
        if ($estId <= 0) {
            return null;
        }

        $owner = estimation_owner_scope('created_by', 'user');
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $sql = "SELECT id, status, estimation_number, created_by
                FROM estimations
                WHERE id = :id{$owner['sql']}
                LIMIT 1{$lock}";

        $params = array_merge(['id' => $estId], $owner['params']);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
