<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/settings_helper.php';

if (($_SESSION['role'] ?? '') !== 'System Admin' && !hasPermission('manage_settings')) {
    die('Access Denied.');
}

$success = '';
$error = '';
$testResult = null;

$aiSettings = get_ai_settings();
$monthlyUsage = [
    'requests' => 0,
    'total_tokens' => 0,
    'estimated_cost' => 0.0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_settings');

    if (!verify_csrf_token($_POST['_csrf'] ?? null, 'settings_ai')) {
        $error = 'Request rejected due to invalid security token.';
    } elseif ($action === 'save_settings') {
        try {
            $selectedProvider = strtolower(trim((string) ($_POST['ai_provider'] ?? 'openai')));
            if (!in_array($selectedProvider, ['openai', 'ollama'], true)) {
                $selectedProvider = 'openai';
            }

            $selectedModel = trim((string) ($_POST['ai_model'] ?? ''));
            if ($selectedModel === '') {
                $selectedModel = $selectedProvider === 'ollama' ? 'llama3.2:1b' : 'gpt-4o-mini';
            }

            save_application_settings([
                'ai_enabled' => isset($_POST['ai_enabled']),
                'ai_provider' => $selectedProvider,
                'ai_model' => $selectedModel,
                'ai_ollama_base_url' => $_POST['ai_ollama_base_url'] ?? 'http://127.0.0.1:11434',
                'ai_api_key' => $_POST['ai_api_key'] ?? '',
                'ai_monthly_budget' => $_POST['ai_monthly_budget'] ?? 0,
                'ai_max_tokens' => $_POST['ai_max_tokens'] ?? 700,
                'ai_timeout_seconds' => $_POST['ai_timeout_seconds'] ?? 30,
                'ai_enable_tasks' => isset($_POST['ai_enable_tasks']),
                'ai_enable_projects' => isset($_POST['ai_enable_projects']),
                'ai_enable_reminders' => isset($_POST['ai_enable_reminders']),
                'ai_enable_analysis' => isset($_POST['ai_enable_analysis']),
                'ai_enable_invoices' => isset($_POST['ai_enable_invoices']),
                'ai_enable_estimations' => isset($_POST['ai_enable_estimations']),
                'ai_enable_sales' => isset($_POST['ai_enable_sales']),
            ]);

            $success = 'AI assistant settings updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to save AI settings: ' . $e->getMessage();
        }
    } elseif ($action === 'test_connection') {
        require_once __DIR__ . '/../ai/service.php';
        try {
            $testSettings = get_ai_settings();
            if (!empty($_POST['ai_provider'])) {
                $testProvider = strtolower(trim((string) $_POST['ai_provider']));
                if (in_array($testProvider, ['openai', 'ollama'], true)) {
                    $testSettings['provider'] = $testProvider;
                }
            }
            if (!empty($_POST['ai_api_key'])) {
                $testSettings['api_key'] = trim((string) $_POST['ai_api_key']);
            }
            if (!empty($_POST['ai_model'])) {
                $testSettings['model'] = trim((string) $_POST['ai_model']);
            }
            if (!empty($_POST['ai_ollama_base_url'])) {
                $testSettings['ollama_base_url'] = trim((string) $_POST['ai_ollama_base_url']);
            }
            $testResult = ai_test_connection($testSettings);
        } catch (Exception $e) {
            $testResult = [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    $aiSettings = get_ai_settings();
}

try {
    $usageStmt = $pdo->prepare("
        SELECT COUNT(*) AS requests, COALESCE(SUM(total_tokens), 0) AS total_tokens, COALESCE(SUM(estimated_cost), 0) AS estimated_cost
        FROM ai_usage_events
        WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND created_at < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
    ");
    $usageStmt->execute();
    $row = $usageStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $monthlyUsage['requests'] = (int) ($row['requests'] ?? 0);
        $monthlyUsage['total_tokens'] = (int) ($row['total_tokens'] ?? 0);
        $monthlyUsage['estimated_cost'] = (float) ($row['estimated_cost'] ?? 0);
    }
} catch (Exception $e) {
}

$aiStorage = get_setting_storage_status('ai_api_key');

include '../../includes/header.php';
?>

<div class="container mx-auto px-4 py-8 max-w-6xl">
    <div class="flex flex-col gap-2 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">AI Assistant Settings</h1>
        <p class="text-sm text-gray-500">Manage AI provider configuration, usage controls, and feature toggles for the ERP assistant.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Assistant status</p>
            <span class="inline-flex mt-3 px-3 py-1 rounded-full text-sm font-semibold <?php echo $aiSettings['enabled'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700'; ?>">
                <?php echo $aiSettings['enabled'] ? 'Enabled' : 'Disabled'; ?>
            </span>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Monthly requests</p>
            <p class="mt-3 text-xl font-semibold text-gray-800"><?php echo number_format((int) $monthlyUsage['requests']); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-400">Estimated monthly cost</p>
            <p class="mt-3 text-xl font-semibold text-gray-800">$<?php echo number_format((float) $monthlyUsage['estimated_cost'], 4); ?></p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($testResult)): ?>
        <div class="<?php echo !empty($testResult['success']) ? 'bg-green-100 border-green-300 text-green-800' : 'bg-red-100 border-red-300 text-red-800'; ?> border px-4 py-3 rounded-lg mb-4">
            <?php echo htmlspecialchars((string) ($testResult['message'] ?? 'Connection test completed.')); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="ai" class="space-y-8">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('settings_ai')); ?>">

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Provider and Model</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl md:col-span-2">
                    <input type="checkbox" name="ai_enabled" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo !empty($aiSettings['enabled']) ? 'checked' : ''; ?>>
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Enable AI assistant across the ERP shell</span>
                        <span class="block text-xs text-gray-500">When disabled, assistant endpoints and UI controls are unavailable to users.</span>
                    </span>
                </label>

                <div>
                    <label for="ai_provider" class="block text-sm font-medium text-gray-700">Provider</label>
                    <select id="ai_provider" name="ai_provider" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                        <option value="openai" <?php echo (($aiSettings['provider'] ?? 'openai') === 'openai') ? 'selected' : ''; ?>>OpenAI</option>
                        <option value="ollama" <?php echo (($aiSettings['provider'] ?? 'openai') === 'ollama') ? 'selected' : ''; ?>>Ollama Local (free testing)</option>
                    </select>
                </div>

                <div>
                    <label for="ai_model" class="block text-sm font-medium text-gray-700">Model</label>
                    <input type="text" id="ai_model" name="ai_model" value="<?php echo htmlspecialchars((string) ($aiSettings['model'] ?? 'gpt-4o-mini')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="gpt-4o-mini">
                    <p id="ai_model_hint" class="mt-1 text-xs text-gray-500">Use a model supported by the selected provider.</p>
                </div>

                <div id="ai_ollama_fields" class="md:col-span-2">
                    <label for="ai_ollama_base_url" class="block text-sm font-medium text-gray-700">Ollama Base URL</label>
                    <input type="text" id="ai_ollama_base_url" name="ai_ollama_base_url" value="<?php echo htmlspecialchars((string) ($aiSettings['ollama_base_url'] ?? 'http://127.0.0.1:11434')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="http://127.0.0.1:11434">
                    <p class="mt-1 text-xs text-gray-500">Use the local Ollama server for free testing. No provider API key is required.</p>
                </div>

                <div id="ai_openai_key_field" class="md:col-span-2">
                    <label for="ai_api_key" class="block text-sm font-medium text-gray-700">API Key</label>
                    <input type="password" id="ai_api_key" name="ai_api_key" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="<?php echo setting_has_stored_value('ai_api_key') ? 'Stored in .env' : 'Enter provider API key'; ?>">
                    <p class="mt-1 text-xs text-gray-500">
                        Leave blank to keep existing key.
                        Current source: <?php echo htmlspecialchars($aiStorage['source'] === 'env' ? '.env' : ($aiStorage['source'] === 'database' ? 'legacy database fallback' : 'not stored')); ?>.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Guardrails</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="ai_monthly_budget" class="block text-sm font-medium text-gray-700">Monthly Budget (USD)</label>
                    <input type="number" min="0" step="0.01" id="ai_monthly_budget" name="ai_monthly_budget" value="<?php echo htmlspecialchars((string) ($aiSettings['monthly_budget'] ?? 0)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label for="ai_max_tokens" class="block text-sm font-medium text-gray-700">Max Tokens Per Request</label>
                    <input type="number" min="64" max="4000" id="ai_max_tokens" name="ai_max_tokens" value="<?php echo htmlspecialchars((string) ($aiSettings['max_tokens'] ?? 700)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
                <div>
                    <label for="ai_timeout_seconds" class="block text-sm font-medium text-gray-700">Request Timeout (Seconds)</label>
                    <input type="number" min="5" max="120" id="ai_timeout_seconds" name="ai_timeout_seconds" value="<?php echo htmlspecialchars((string) ($aiSettings['timeout_seconds'] ?? 30)); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Feature Access</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php
                $features = [
                    'ai_enable_tasks' => ['label' => 'Task insights and summaries', 'key' => 'tasks'],
                    'ai_enable_projects' => ['label' => 'Project progress insights', 'key' => 'projects'],
                    'ai_enable_reminders' => ['label' => 'Reminder recommendations', 'key' => 'reminders'],
                    'ai_enable_analysis' => ['label' => 'General analysis and projections', 'key' => 'analysis'],
                    'ai_enable_invoices' => ['label' => 'Invoice health and collections insights', 'key' => 'invoices'],
                    'ai_enable_estimations' => ['label' => 'Estimation profitability and conversion insights', 'key' => 'estimations'],
                    'ai_enable_sales' => ['label' => 'Sales performance and revenue insights', 'key' => 'sales'],
                ];
                ?>
                <?php foreach ($features as $inputName => $meta): ?>
                    <label class="flex items-start gap-3 p-4 border border-gray-200 rounded-xl">
                        <input type="checkbox" name="<?php echo htmlspecialchars($inputName); ?>" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600" <?php echo !empty($aiSettings['features'][$meta['key']]) ? 'checked' : ''; ?>>
                        <span>
                            <span class="block text-sm font-medium text-gray-800"><?php echo htmlspecialchars($meta['label']); ?></span>
                            <span class="block text-xs text-gray-500">Can be adjusted per rollout phase without disabling the assistant globally.</span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="flex flex-wrap gap-3 justify-end">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg shadow-sm hover:bg-blue-700">Save AI Settings</button>
        </div>
    </form>

    <form method="POST" action="ai" class="bg-white shadow-md rounded-xl p-6 mt-6">
        <input type="hidden" name="action" value="test_connection">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token('settings_ai')); ?>">
        <h2 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Test Provider Connection</h2>
        <p class="text-sm text-gray-500 mb-4">Validates provider credentials and model reachability without making business data requests.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="test_ai_provider" class="block text-sm font-medium text-gray-700">Provider Override</label>
                <select id="test_ai_provider" name="ai_provider" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
                    <option value="openai" <?php echo (($aiSettings['provider'] ?? 'openai') === 'openai') ? 'selected' : ''; ?>>OpenAI</option>
                    <option value="ollama" <?php echo (($aiSettings['provider'] ?? 'openai') === 'ollama') ? 'selected' : ''; ?>>Ollama Local (free testing)</option>
                </select>
            </div>
            <div>
                <label for="test_ai_model" class="block text-sm font-medium text-gray-700">Model Override (Optional)</label>
                <input type="text" id="test_ai_model" name="ai_model" value="<?php echo htmlspecialchars((string) ($aiSettings['model'] ?? 'gpt-4o-mini')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2">
            </div>
            <div>
                <label for="test_ai_api_key" class="block text-sm font-medium text-gray-700">API Key Override (Optional)</label>
                <input type="password" id="test_ai_api_key" name="ai_api_key" value="" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="Use saved key if blank">
            </div>
            <div>
                <label for="test_ai_ollama_base_url" class="block text-sm font-medium text-gray-700">Ollama Base URL Override</label>
                <input type="text" id="test_ai_ollama_base_url" name="ai_ollama_base_url" value="<?php echo htmlspecialchars((string) ($aiSettings['ollama_base_url'] ?? 'http://127.0.0.1:11434')); ?>" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="http://127.0.0.1:11434">
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700">Test Connection</button>
        </div>
    </form>
</div>

<script>
    (function () {
        const providerSelect = document.getElementById('ai_provider');
        const modelInput = document.getElementById('ai_model');
        const modelHint = document.getElementById('ai_model_hint');
        const ollamaFields = document.getElementById('ai_ollama_fields');
        const openAiKeyField = document.getElementById('ai_openai_key_field');
        const testProviderSelect = document.getElementById('test_ai_provider');
        const testModelInput = document.getElementById('test_ai_model');

        const defaults = {
            openai: 'gpt-4o-mini',
            ollama: 'llama3.2:1b'
        };

        function refreshProviderFields(previousProvider) {
            if (!providerSelect) {
                return;
            }

            const provider = providerSelect.value || 'openai';
            const isOllama = provider === 'ollama';
            const previousDefault = defaults[previousProvider] || '';

            if (ollamaFields) {
                ollamaFields.classList.toggle('hidden', !isOllama);
            }
            if (openAiKeyField) {
                openAiKeyField.classList.toggle('hidden', isOllama);
            }
            if (modelHint) {
                modelHint.textContent = isOllama
                    ? 'Recommended for local testing: llama3.2:1b. Pull it first with ollama run llama3.2:1b.'
                    : 'Recommended cloud testing model: gpt-4o-mini.';
            }
            if (modelInput && (modelInput.value.trim() === '' || modelInput.value.trim() === previousDefault)) {
                modelInput.value = defaults[provider] || '';
            }
        }

        if (providerSelect) {
            let previousProvider = providerSelect.value || 'openai';
            refreshProviderFields(previousProvider);
            providerSelect.addEventListener('change', function () {
                const oldProvider = previousProvider;
                previousProvider = providerSelect.value || 'openai';
                refreshProviderFields(oldProvider);
            });
        }

        if (testProviderSelect && testModelInput) {
            testProviderSelect.addEventListener('change', function () {
                const provider = testProviderSelect.value || 'openai';
                if (testModelInput.value.trim() === '' || Object.values(defaults).indexOf(testModelInput.value.trim()) !== -1) {
                    testModelInput.value = defaults[provider] || '';
                }
            });
        }
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
