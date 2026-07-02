<?php

function normalizeUserComparisonValue(string $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9]+/', '', trim($value)));
}

function findPotentialDuplicateUser(PDO $pdo, string $name, string $email, ?int $excludeUserId = null): ?array
{
    $sql = "SELECT id, name, email FROM users";
    $params = [];

    if ($excludeUserId !== null) {
        $sql .= " WHERE id != ?";
        $params[] = $excludeUserId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $targetName = normalizeUserComparisonValue($name);
    $targetEmail = normalizeUserComparisonValue($email);

    foreach ($stmt->fetchAll() as $user) {
        $existingName = normalizeUserComparisonValue($user['name'] ?? '');
        $existingEmail = normalizeUserComparisonValue($user['email'] ?? '');

        $exactEmail = $targetEmail !== '' && $targetEmail === $existingEmail;
        $exactName = $targetName !== '' && $targetName === $existingName;
        $closeName = $targetName !== '' && $existingName !== '' && levenshtein($targetName, $existingName) <= 1;
        $closeEmail = $targetEmail !== '' && $existingEmail !== '' && levenshtein($targetEmail, $existingEmail) <= 2;

        if ($exactEmail) {
            return ['user' => $user, 'reason' => 'email'];
        }

        if ($exactName) {
            return ['user' => $user, 'reason' => 'name'];
        }

        if ($closeName && $closeEmail) {
            return ['user' => $user, 'reason' => 'similar'];
        }
    }

    return null;
}

function buildDuplicateUserMessage(array $duplicate): string
{
    $user = $duplicate['user'];
    $label = ($user['name'] ?? 'Existing user') . ' (' . ($user['email'] ?? 'no email') . ')';

    if (($duplicate['reason'] ?? '') === 'email') {
        return 'This email address is already assigned to ' . $label . '.';
    }

    if (($duplicate['reason'] ?? '') === 'name') {
        return 'A user with the same name already exists: ' . $label . '.';
    }

    return 'This looks like a duplicate of ' . $label . '. Please review the name and email before saving.';
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function getUserDependencyCounts(PDO $pdo, int $userId): array
{
    $referencesStmt = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME = 'users'
    ");

    $dependencies = [];

    foreach ($referencesStmt->fetchAll() as $reference) {
        $tableName = $reference['TABLE_NAME'];
        $columnName = $reference['COLUMN_NAME'];

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . quoteIdentifier($tableName) . ' WHERE ' . quoteIdentifier($columnName) . ' = ?'
        );
        $countStmt->execute([$userId]);
        $count = (int) $countStmt->fetchColumn();

        if ($count > 0) {
            $dependencies[] = [
                'table' => $tableName,
                'column' => $columnName,
                'count' => $count,
            ];
        }
    }

    usort($dependencies, static function (array $left, array $right): int {
        return $right['count'] <=> $left['count'];
    });

    return $dependencies;
}

function buildUserDependencyMessage(array $dependencies): string
{
    if (empty($dependencies)) {
        return 'This user cannot be deleted because related records still exist.';
    }

    $parts = [];

    foreach (array_slice($dependencies, 0, 3) as $dependency) {
        $parts[] = sprintf(
            '%d in %s.%s',
            $dependency['count'],
            $dependency['table'],
            $dependency['column']
        );
    }

    $message = 'Cannot delete this user yet. Related records still point to the account: ' . implode(', ', $parts) . '.';

    if (count($dependencies) > 3) {
        $message .= ' Additional references also exist.';
    }

    return $message;
}
