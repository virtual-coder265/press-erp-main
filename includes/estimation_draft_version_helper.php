<?php
/**
 * Estimation draft step checkpoints — one restorable snapshot per wizard step (8 total).
 */

const ESTIMATION_DRAFT_STEP_COUNT = 8;

/** @deprecated Use ESTIMATION_DRAFT_STEP_COUNT */
const ESTIMATION_DRAFT_VERSION_LIMIT = ESTIMATION_DRAFT_STEP_COUNT;

/**
 * @return array<int, string> Step number => label
 */
function estimation_draft_step_labels(): array
{
    return [
        1 => 'Client & Job',
        2 => 'Materials',
        3 => 'Paper',
        4 => 'Ink',
        5 => 'Binding',
        6 => 'Labour',
        7 => 'Consumables',
        8 => 'Totals',
    ];
}

function estimation_draft_normalize_step(?int $step): int
{
    $step = (int) ($step ?? 1);
    if ($step < 1) {
        return 1;
    }
    if ($step > ESTIMATION_DRAFT_STEP_COUNT) {
        return ESTIMATION_DRAFT_STEP_COUNT;
    }
    return $step;
}

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
 * Upsert a step checkpoint (one row per estimation + wizard step).
 */
function estimation_draft_store_step_checkpoint(
    PDO $pdo,
    int $estimationId,
    int $draftStep,
    string $draftData,
    ?string $contentHash,
    int $savedBy
): void {
    $draftStep = estimation_draft_normalize_step($draftStep);

    $existing = estimation_draft_get_step_checkpoint($pdo, $estimationId, $draftStep);
    if ($existing) {
        $stmt = $pdo->prepare(
            "UPDATE estimation_draft_versions
             SET draft_data = :draft_data,
                 content_hash = :hash,
                 saved_at = NOW(),
                 saved_by = :saved_by,
                 revision = :revision
             WHERE estimation_id = :est_id AND draft_step = :step"
        );
        $stmt->execute([
            'est_id' => $estimationId,
            'step' => $draftStep,
            'draft_data' => $draftData,
            'hash' => $contentHash,
            'saved_by' => $savedBy,
            'revision' => $draftStep,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO estimation_draft_versions
            (estimation_id, revision, draft_data, draft_step, content_hash, saved_at, saved_by)
         VALUES
            (:est_id, :revision, :draft_data, :step, :hash, NOW(), :saved_by)"
    );
    $stmt->execute([
        'est_id' => $estimationId,
        'revision' => $draftStep,
        'draft_data' => $draftData,
        'step' => $draftStep,
        'hash' => $contentHash,
        'saved_by' => $savedBy,
    ]);
}

/**
 * @return array<int, array<string, mixed>> Step number => row
 */
function estimation_draft_step_checkpoint_map(PDO $pdo, int $estimationId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, estimation_id, revision, draft_step, content_hash, saved_at, saved_by
         FROM estimation_draft_versions
         WHERE estimation_id = :est_id
         ORDER BY draft_step ASC, saved_at DESC"
    );
    $stmt->execute(['est_id' => $estimationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $step = estimation_draft_normalize_step((int) ($row['draft_step'] ?? 1));
        if (!isset($map[$step])) {
            $map[$step] = $row;
        }
    }

    return $map;
}

/**
 * Build history list: current live draft + up to 8 step checkpoints (newest steps first).
 *
 * @return array<int, array<string, mixed>>
 */
function estimation_draft_list_step_history(PDO $pdo, int $estimationId, array $estimationRow): array
{
    $labels = estimation_draft_step_labels();
    $checkpoints = estimation_draft_step_checkpoint_map($pdo, $estimationId);
    $currentStep = estimation_draft_normalize_step((int) ($estimationRow['draft_step'] ?? 1));
    $currentRevision = (int) ($estimationRow['draft_revision'] ?? 0);

    $items = [[
        'draft_step' => $currentStep,
        'revision' => $currentRevision,
        'saved_at' => $estimationRow['last_auto_saved'] ?? null,
        'is_current' => true,
        'has_checkpoint' => true,
        'label' => 'Current (Step ' . $currentStep . ': ' . ($labels[$currentStep] ?? 'Unknown') . ')',
    ]];

    for ($step = ESTIMATION_DRAFT_STEP_COUNT; $step >= 1; $step--) {
        $checkpoint = $checkpoints[$step] ?? null;
        $items[] = [
            'draft_step' => $step,
            'revision' => $checkpoint ? (int) ($checkpoint['revision'] ?? $step) : null,
            'saved_at' => $checkpoint['saved_at'] ?? null,
            'is_current' => false,
            'has_checkpoint' => $checkpoint !== null,
            'label' => 'Step ' . $step . ': ' . ($labels[$step] ?? 'Unknown'),
        ];
    }

    return $items;
}

/**
 * @return array<string, mixed>|null
 */
function estimation_draft_get_step_checkpoint(PDO $pdo, int $estimationId, int $draftStep): ?array
{
    $draftStep = estimation_draft_normalize_step($draftStep);
    $stmt = $pdo->prepare(
        "SELECT *
         FROM estimation_draft_versions
         WHERE estimation_id = :est_id AND draft_step = :step
         ORDER BY saved_at DESC
         LIMIT 1"
    );
    $stmt->execute(['est_id' => $estimationId, 'step' => $draftStep]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @deprecated Prefer estimation_draft_get_step_checkpoint()
 * @return array<string, mixed>|null
 */
function estimation_draft_get_version(PDO $pdo, int $estimationId, int $revision): ?array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM estimation_draft_versions
         WHERE estimation_id = :est_id AND (draft_step = :step OR revision = :revision)
         ORDER BY saved_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        'est_id' => $estimationId,
        'step' => estimation_draft_normalize_step($revision),
        'revision' => $revision,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @deprecated Use estimation_draft_list_step_history()
 * @return array<int, array<string, mixed>>
 */
function estimation_draft_list_versions(PDO $pdo, int $estimationId, int $limit = ESTIMATION_DRAFT_STEP_COUNT): array
{
    $stmt = $pdo->prepare(
        "SELECT id, estimation_id, revision, draft_step, content_hash, saved_at, saved_by
         FROM estimation_draft_versions
         WHERE estimation_id = :est_id
         ORDER BY draft_step DESC, saved_at DESC
         LIMIT " . max(1, min($limit, ESTIMATION_DRAFT_STEP_COUNT))
    );
    $stmt->execute(['est_id' => $estimationId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @deprecated Step checkpoints replace revision archiving.
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
    estimation_draft_store_step_checkpoint(
        $pdo,
        $estimationId,
        $draftStep > 0 ? $draftStep : $revision,
        $draftData,
        $contentHash,
        $savedBy
    );
}

/**
 * @deprecated No longer prunes by revision count.
 */
function estimation_draft_prune_versions(PDO $pdo, int $estimationId): void
{
    // Keep one row per step; remove stray duplicates if unique index is missing.
    try {
        $pdo->prepare(
            "DELETE v1 FROM estimation_draft_versions v1
             INNER JOIN estimation_draft_versions v2
               ON v1.estimation_id = v2.estimation_id
              AND v1.draft_step = v2.draft_step
              AND v1.id < v2.id
             WHERE v1.estimation_id = :est_id"
        )->execute(['est_id' => $estimationId]);
    } catch (Throwable $e) {
        // Best effort.
    }
}
