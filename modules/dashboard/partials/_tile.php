<?php
/**
 * Reusable workspace tile.
 *
 * Expected variables (set by the caller before include):
 *   $tile = [
 *     'id'        => 'uniqueId',           // required, tile element id
 *     'modal'     => 'modalId',            // required, modal overlay id to open on click
 *     'icon'      => 'chart-line',          // Lucide icon name (data-lucide)
 *     'label'     => 'Performance',        // small uppercase label
 *     'value'     => '12',                 // big headline number/text
 *     'hint'      => 'Snapshot',           // optional 1-line hint below the value
 *     'tone'      => 'primary',            // primary|danger|warning|success|violet|neutral
 *     'preview'   => [                     // optional hover flyout contents
 *        'title' => 'Performance snapshot',
 *        'lines' => ['Revenue this month: ...', 'Active projects: ...']
 *     ],
 *   ];
 */
if (!isset($tile) || !is_array($tile)) {
    return;
}

$tileId = (string) ($tile['id'] ?? ('ws-tile-' . uniqid()));
$modalId = (string) ($tile['modal'] ?? '');
$icon = (string) ($tile['icon'] ?? 'grid_view');
$label = (string) ($tile['label'] ?? '');
$value = (string) ($tile['value'] ?? '');
$hint = (string) ($tile['hint'] ?? '');
$tone = (string) ($tile['tone'] ?? 'primary');
$preview = isset($tile['preview']) && is_array($tile['preview']) ? $tile['preview'] : null;
$hoverId = $preview ? ($tileId . '-preview') : '';
?>
<button type="button"
        class="todo-tile"
        id="<?php echo htmlspecialchars($tileId); ?>"
        data-tone="<?php echo htmlspecialchars($tone); ?>"
        data-ws-open="<?php echo htmlspecialchars($modalId); ?>"
        <?php if ($hoverId): ?>data-ws-hover="<?php echo htmlspecialchars($hoverId); ?>"<?php endif; ?>
        aria-haspopup="dialog"
        aria-controls="<?php echo htmlspecialchars($modalId); ?>">
    <span class="todo-tile-icon"><i data-lucide="<?php echo htmlspecialchars($icon); ?>" aria-hidden="true"></i></span>
    <span class="todo-tile-body">
        <span class="todo-tile-label"><?php echo htmlspecialchars($label); ?></span>
        <span class="todo-tile-value"><?php echo htmlspecialchars($value); ?></span>
        <?php if ($hint !== ''): ?>
            <span class="todo-tile-hint"><?php echo htmlspecialchars($hint); ?></span>
        <?php endif; ?>
    </span>
    <?php if ($preview): ?>
        <span class="todo-hover-card" id="<?php echo htmlspecialchars($hoverId); ?>" role="tooltip">
            <?php if (!empty($preview['title'])): ?>
                <span class="todo-hover-card-title"><?php echo htmlspecialchars((string) $preview['title']); ?></span>
            <?php endif; ?>
            <?php if (!empty($preview['lines']) && is_array($preview['lines'])): ?>
                <span class="todo-hover-card-meta">
                    <?php foreach ($preview['lines'] as $line): ?>
                        <span><?php echo htmlspecialchars((string) $line); ?></span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </span>
    <?php endif; ?>
</button>
<?php
unset($tile, $tileId, $modalId, $icon, $label, $value, $hint, $tone, $preview, $hoverId);
