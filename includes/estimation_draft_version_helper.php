<?php
/**
 * Helpers for estimation draft version history (last 4 snapshots per draft).
 */

const ESTIMATION_DRAFT_VERSION_LIMIT = 4;

/**
 * Recursively sort associative arrays so client/server hashes match.
 *
 * @param mixed $value
 * @return mixed
 */
function estimation_draft_canonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    if (!$isList) {
        ksort($value);
    }

    foreach ($value as $key => $child) {
        $value[$key] = estimation_draft_canonicalize($child);
    }

    return $value;
}

/**
 * @param array|string $formData Decoded form fields or raw JSON string
 */
function estimation_draft_content_hash($formData): string
{
    if (is_string($formData)) {
        $decoded = json_decode($formData, true);
        $formData = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($formData)) {
        $formData = [];
    }

    $canonical = estimation_draft_canonicalize($formData);
    $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return hash('sha256', $json !== false ? $json : '');
}

function estimation_draft_utc_now(): string
{
    return gmdate('c');
}

/**
 * Store a version snapshot before overwriting draft_data.
 */
function estimation_draft_store_version(
    PDO $pdo,
    int $estimationId,
    int $revision,
    string $draftData,
    int $draftStep,
    ?string $contentHash,
    int $savedBy
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO estimation_draft_versions
            (estimation_id, revision, draft_data, draft_step, content_hash, saved_at, saved_by)
         VALUES
            (:est_id, :revision, :draft_data, :step, :hash, NOW(), :saved_by)"
    );
    $stmt->execute([
        'est_id' => $estimationId,
        'revision' => $revision,
        'draft_data' => $draftData,
        'step' => $draftStep,
        'hash' => $contentHash,
        'saved_by' => $savedBy,
    ]);

    estimation_draft_prune_versions($pdo, $estimationId);
}

/**
 * Keep only the most recent N version rows per estimation.
 */
function estimation_draft_prune_versions(PDO $pdo, int $estimationId): void
{
    $limit = (int) ESTIMATION_DRAFT_VERSION_LIMIT;
    $stmt = $pdo->prepare(
        "DELETE FROM estimation_draft_versions
         WHERE estimation_id = :est_id
           AND id NOT IN (
               SELECT id FROM (
                   SELECT id FROM estimation_draft_versions
                   WHERE estimation_id = :est_id2
                   ORDER BY revision DESC, saved_at DESC
                   LIMIT {$limit}
               ) AS keep_rows
           )"
    );
    $stmt->execute(['est_id' => $estimationId, 'est_id2' => $estimationId]);
}

/**
 * List stored versions for a draft (newest first, up to limit).
 *
 * @return array<int, array<string, mixed>>
 */
function estimation_draft_list_versions(PDO $pdo, int $estimationId, int $limit = ESTIMATION_DRAFT_VERSION_LIMIT): array
{
    $limit = max(1, min($limit, ESTIMATION_DRAFT_VERSION_LIMIT));
    $stmt = $pdo->prepare(
        "SELECT id, estimation_id, revision, draft_step, content_hash, saved_at, saved_by
         FROM estimation_draft_versions
         WHERE estimation_id = :est_id
         ORDER BY revision DESC, saved_at DESC
         LIMIT {$limit}"
    );
    $stmt->execute(['est_id' => $estimationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fetch one version row by estimation + revision.
 *
 * @return array<string, mixed>|null
 */
function estimation_draft_get_version(PDO $pdo, int $estimationId, int $revision): ?array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM estimation_draft_versions
         WHERE estimation_id = :est_id AND revision = :revision
         LIMIT 1"
    );
    $stmt->execute(['est_id' => $estimationId, 'revision' => $revision]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
