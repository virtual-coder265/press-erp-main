<?php
/**
 * Helpers for estimation list views (drafts, completed, invoiced).
 */

require_once __DIR__ . '/../libs/EstimationStatusManager.php';

const ESTIMATION_DRAFT_ABANDONED_DAYS = 7;

/**
 * @return array<string, string>
 */
function estimation_list_views(): array
{
    return [
        'drafts' => 'Draft',
        'completed' => 'Completed',
        'invoiced' => 'Invoiced',
    ];
}

function estimation_normalize_list_view(?string $view): string
{
    $view = strtolower(trim((string) $view));
    return array_key_exists($view, estimation_list_views()) ? $view : 'drafts';
}

/**
 * @return array<string, string>
 */
function estimation_draft_kind_filters(): array
{
    return [
        '' => 'All drafts',
        'autosaved' => 'Autosaved',
        'manual' => 'Manually saved',
        'recovered' => 'Auto-recovered',
        'abandoned' => 'Abandoned',
    ];
}

/**
 * Classify an in-progress draft row for display/filtering.
 *
 * @param array<string, mixed> $est
 */
function estimation_draft_kind(array $est): string
{
    if (($est['status'] ?? '') !== EstimationStatusManager::STATUS_DRAFT) {
        return '';
    }
    if (empty($est['draft_data'])) {
        return '';
    }

    $origin = strtolower(trim((string) ($est['draft_origin'] ?? '')));
    if ($origin === 'manual') {
        return 'manual';
    }
    if ($origin === 'recovered') {
        return 'recovered';
    }
    if ($origin === 'autosave' || $origin === 'autosaved') {
        return 'autosaved';
    }

    if (estimation_draft_is_abandoned($est)) {
        return 'abandoned';
    }

    return 'autosaved';
}

/**
 * @param array<string, mixed> $est
 */
function estimation_draft_is_abandoned(array $est): bool
{
    $lastSaved = $est['last_auto_saved'] ?? null;
    if (!$lastSaved) {
        $lastSaved = $est['updated_at'] ?? $est['created_at'] ?? null;
    }
    if (!$lastSaved) {
        return false;
    }

    $threshold = strtotime('-' . ESTIMATION_DRAFT_ABANDONED_DAYS . ' days');
    return strtotime((string) $lastSaved) < $threshold;
}

/**
 * @param array<string, mixed> $est
 */
function estimation_draft_kind_label(array $est): string
{
    $labels = [
        'autosaved' => 'Autosaved',
        'manual' => 'Manually saved',
        'recovered' => 'Auto-recovered',
        'abandoned' => 'Abandoned',
    ];
    $kind = estimation_draft_kind($est);

    return $labels[$kind] ?? 'Draft';
}

/**
 * @param array<string, mixed> $est
 */
function estimation_draft_kind_badge_html(array $est): string
{
    $kind = estimation_draft_kind($est);
    if ($kind === '') {
        return '';
    }

    $classes = [
        'autosaved' => 'bg-slate-100 text-slate-800',
        'manual' => 'bg-amber-100 text-amber-900',
        'recovered' => 'bg-blue-100 text-blue-900',
        'abandoned' => 'bg-orange-100 text-orange-900',
    ];

    $class = $classes[$kind] ?? 'bg-gray-100 text-gray-800';
    $label = estimation_draft_kind_label($est);

    return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ' . $class . '">'
        . htmlspecialchars($label) . '</span>';
}

/**
 * Build WHERE fragments for list.php based on view + draft kind filter.
 *
 * @return array{sql: string, params: array<string, mixed>}
 */
function estimation_list_query_filters(string $view, string $draftKind = ''): array
{
    $sql = '';
    $params = [];

    if ($view === 'drafts') {
        $sql .= " AND e.status = 'Draft' AND e.draft_data IS NOT NULL AND e.draft_data != ''";
    } elseif ($view === 'completed') {
        $sql .= " AND (
            e.status IN ('Approved', 'Performer Invoiced')
            OR (e.status = 'Draft' AND (e.draft_data IS NULL OR e.draft_data = ''))
        )";
    } elseif ($view === 'invoiced') {
        $sql .= " AND e.status = 'Invoiced'";
    }

    $draftKind = strtolower(trim($draftKind));
    if ($view === 'drafts' && $draftKind !== '') {
        if ($draftKind === 'manual') {
            $sql .= " AND e.draft_origin = 'manual'";
        } elseif ($draftKind === 'recovered') {
            $sql .= " AND e.draft_origin = 'recovered'";
        } elseif ($draftKind === 'autosaved') {
            $sql .= " AND (e.draft_origin IN ('autosave', 'autosaved') OR e.draft_origin IS NULL OR e.draft_origin = '')";
            $sql .= " AND COALESCE(e.last_auto_saved, e.updated_at, e.created_at) >= DATE_SUB(NOW(), INTERVAL "
                . (int) ESTIMATION_DRAFT_ABANDONED_DAYS . " DAY)";
        } elseif ($draftKind === 'abandoned') {
            $sql .= " AND COALESCE(e.last_auto_saved, e.updated_at, e.created_at) < DATE_SUB(NOW(), INTERVAL "
                . (int) ESTIMATION_DRAFT_ABANDONED_DAYS . " DAY)";
        }
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * @param array<string, mixed> $est
 */
function estimation_can_continue_draft(array $est): bool
{
    return ($est['status'] ?? '') === EstimationStatusManager::STATUS_DRAFT
        && !empty($est['draft_data']);
}
