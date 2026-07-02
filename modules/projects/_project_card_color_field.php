<?php
/**
 * Portfolio workspace card accent (hex). Expects $projectCardColorValue (string|null).
 */
$projectCardColorValue = $projectCardColorValue ?? '';
$projectCardColorPresets = ['#0f766e', '#0d9488', '#0ea5e9', '#6366f1', '#7c3aed', '#db2777', '#ea580c', '#ca8a04', '#16a34a', '#0f172a', '#64748b'];
$fieldId = $projectCardColorFieldId ?? 'projectCardColorHex';
?>
<div class="workspace-panel p-4 sm:p-5 bg-slate-50 border border-slate-100 rounded-xl">
    <label class="block text-gray-800 font-bold mb-1" for="<?php echo htmlspecialchars($fieldId); ?>">Portfolio card color</label>
    <p class="text-sm text-gray-500 mb-3">Choose an accent for this project in the portfolio workspace. Leave blank for the default teal.</p>
    <div class="flex flex-wrap gap-2 mb-3" role="group" aria-label="Preset card colors">
        <?php foreach ($projectCardColorPresets as $preset): ?>
            <button type="button"
                    class="project-card-color-swatch w-10 h-10 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                    style="background:<?php echo htmlspecialchars($preset); ?>"
                    data-color-target="<?php echo htmlspecialchars($fieldId); ?>"
                    data-hex="<?php echo htmlspecialchars($preset); ?>"
                    onclick="var t=this.dataset.colorTarget,h=this.dataset.hex,e=t&&document.getElementById(t);if(e)e.value=h;"
                    title="<?php echo htmlspecialchars($preset); ?>"
                    aria-label="Use color <?php echo htmlspecialchars($preset); ?>"></button>
        <?php endforeach; ?>
        <button type="button"
                class="px-3 py-2 text-xs font-semibold rounded-full border border-slate-300 bg-white text-slate-600 hover:bg-slate-50"
                data-color-target="<?php echo htmlspecialchars($fieldId); ?>"
                onclick="var t=this.dataset.colorTarget,e=t&&document.getElementById(t);if(e)e.value='';"
                aria-label="Use default portfolio color">
            Clear
        </button>
    </div>
    <input type="text"
           name="card_color"
           id="<?php echo htmlspecialchars($fieldId); ?>"
           value="<?php echo htmlspecialchars($projectCardColorValue); ?>"
           class="w-full px-4 py-3 border border-gray-300 rounded-lg font-mono text-sm"
           placeholder="Leave blank for default (#0f766e)"
           maxlength="7"
           pattern="#[0-9A-Fa-f]{6}"
           autocomplete="off">
    <p class="text-xs text-gray-500 mt-2">Enter a hex value such as <code class="bg-slate-100 px-1 rounded">#0ea5e9</code> or pick a swatch above.</p>
</div>
