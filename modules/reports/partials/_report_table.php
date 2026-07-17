<?php
/**
 * @var array $rows
 * @var array $columns keyed display columns
 * @var string $emptyMessage
 */
$emptyMessage = $emptyMessage ?? 'No records match the current filters.';
?>
<div class="bg-white shadow rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <?php foreach ($columns as $label): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($rows as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <?php foreach (array_keys($columns) as $key): ?>
                            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars((string) ($row[$key] ?? '—')); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?php echo count($columns); ?>" class="px-4 py-10 text-center text-gray-500"><?php echo htmlspecialchars($emptyMessage); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
