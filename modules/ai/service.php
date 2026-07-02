<?php

require_once __DIR__ . '/provider_openai.php';
require_once __DIR__ . '/provider_ollama.php';
require_once __DIR__ . '/../../includes/settings_helper.php';

if (!function_exists('ai_detect_feature')) {
    function ai_detect_feature($message, $requestedFeature = '') {
        $feature = strtolower(trim((string) $requestedFeature));
        $validFeatures = ['tasks', 'projects', 'reminders', 'invoices', 'estimations', 'sales', 'analysis'];
        if (in_array($feature, $validFeatures, true)) {
            return $feature;
        }

        $text = strtolower((string) $message);
        
        // Invoice-related patterns
        if (preg_match('/\b(invoice|invoices|unpaid|overdue|payment|payments|bill|bills|customer balance|outstanding|collection)\b/', $text)) {
            return 'invoices';
        }
        // Estimation-related patterns
        if (preg_match('/\b(estimation|estimations|estimate|quote|costing|cost|labour|paper|materials|profit margin|job costing)\b/', $text)) {
            return 'estimations';
        }
        // Sales-related patterns
        if (preg_match('/\b(sale|sales|revenue|product|service|sold|product performance|sales tracking|direct sale)\b/', $text)) {
            return 'sales';
        }
        // Task patterns
        if (preg_match('/\b(task|tasks|todo|assignment|work item|pending work)\b/', $text)) {
            return 'tasks';
        }
        // Project patterns
        if (preg_match('/\b(project|projects|milestone|timeline|delivery|progress)\b/', $text)) {
            return 'projects';
        }
        // Reminder patterns
        if (preg_match('/\b(reminder|alarm|follow up|follow-up|schedule|upcoming)\b/', $text)) {
            return 'reminders';
        }

        return 'analysis';
    }
}

if (!function_exists('ai_estimate_cost_usd')) {
    function ai_estimate_cost_usd($promptTokens, $completionTokens, $provider = 'openai') {
        if (strtolower((string) $provider) === 'ollama') {
            return 0.0;
        }

        $promptCostPerThousand = 0.00015;
        $completionCostPerThousand = 0.00060;

        $prompt = max(0, (int) $promptTokens);
        $completion = max(0, (int) $completionTokens);

        return (($prompt / 1000) * $promptCostPerThousand) + (($completion / 1000) * $completionCostPerThousand);
    }
}

if (!function_exists('ai_provider_requires_api_key')) {
    function ai_provider_requires_api_key($provider) {
        return strtolower((string) $provider) === 'openai';
    }
}

if (!function_exists('ai_redact_sensitive_text')) {
    function ai_redact_sensitive_text($text) {
        $value = (string) $text;
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value);
        $value = preg_replace('/\+?\d[\d\-\s]{7,}\d/', '[redacted-phone]', $value);
        return (string) $value;
    }
}

if (!function_exists('ai_get_monthly_spend')) {
    function ai_get_monthly_spend(PDO $pdo) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(estimated_cost), 0)
            FROM ai_usage_events
            WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              AND created_at < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
        ");
        $stmt->execute();
        return (float) $stmt->fetchColumn();
    }
}

if (!function_exists('ai_log_usage_event')) {
    /**
     * @param array<string,mixed> $payload
     * @return void
     */
    function ai_log_usage_event(PDO $pdo, array $payload) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ai_usage_events
                (user_id, feature, model, prompt_tokens, completion_tokens, total_tokens, estimated_cost, status, error_message, request_excerpt, response_excerpt)
                VALUES
                (:user_id, :feature, :model, :prompt_tokens, :completion_tokens, :total_tokens, :estimated_cost, :status, :error_message, :request_excerpt, :response_excerpt)
            ");

            $stmt->execute([
                'user_id' => (int) ($payload['user_id'] ?? 0),
                'feature' => (string) ($payload['feature'] ?? 'analysis'),
                'model' => (string) ($payload['model'] ?? ''),
                'prompt_tokens' => (int) ($payload['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($payload['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($payload['total_tokens'] ?? 0),
                'estimated_cost' => (float) ($payload['estimated_cost'] ?? 0),
                'status' => (string) ($payload['status'] ?? 'ok'),
                'error_message' => (string) ($payload['error_message'] ?? ''),
                'request_excerpt' => (string) ($payload['request_excerpt'] ?? ''),
                'response_excerpt' => (string) ($payload['response_excerpt'] ?? ''),
            ]);
        } catch (Exception $e) {
            error_log('AI usage log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ai_fetch_invoices_context')) {
    function ai_fetch_invoices_context(PDO $pdo, $userId) {
        $context = [];
        try {
            $summaryStmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_invoices,
                    SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) AS paid_invoices,
                    SUM(CASE WHEN status = 'Unpaid' OR status = 'Partially Paid' THEN 1 ELSE 0 END) AS unpaid_invoices,
                    SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) AS overdue_invoices,
                    COALESCE(SUM(total_amount), 0) AS total_amount,
                    COALESCE(SUM(paid_amount), 0) AS total_paid,
                    COALESCE(SUM(balance), 0) AS total_outstanding
                FROM invoices
            ");
            $summaryStmt->execute();
            $context['summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $invoicesStmt = $pdo->prepare("
                SELECT 
                    invoice_number, customer_name, status, 
                    total_amount, balance, due_date,
                    DATEDIFF(CURDATE(), due_date) AS days_overdue
                FROM invoices
                ORDER BY 
                    CASE WHEN status = 'Overdue' THEN 0 ELSE 1 END,
                    CASE WHEN balance > 0 THEN 0 ELSE 1 END,
                    due_date ASC
                LIMIT 8
            ");
            $invoicesStmt->execute();
            $context['items'] = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $context['error'] = 'Invoice context fetch warning: ' . $e->getMessage();
        }
        return $context;
    }
}

if (!function_exists('ai_fetch_estimations_context')) {
    function ai_fetch_estimations_context(PDO $pdo, $userId) {
        $context = [];
        try {
            $summaryStmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_estimations,
                    SUM(CASE WHEN status = 'Draft' THEN 1 ELSE 0 END) AS draft_estimations,
                    SUM(CASE WHEN status = 'Performer Invoiced' THEN 1 ELSE 0 END) AS pending_approval_estimations,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_estimations,
                    SUM(CASE WHEN status = 'Invoiced' THEN 1 ELSE 0 END) AS invoiced_estimations,
                    COALESCE(AVG(profit_margin_percent), 0) AS avg_profit_margin,
                    COALESCE(SUM(total_amount), 0) AS total_estimation_value
                FROM estimations
            ");
            $summaryStmt->execute();
            $context['summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $estimationsStmt = $pdo->prepare("
                SELECT 
                    estimation_number, customer_name, status, job_description,
                    total_amount, profit_margin_percent, 
                    ROUND(profit_amount, 2) AS profit_amount,
                    created_at, last_edited_at
                FROM estimations
                ORDER BY 
                    CASE WHEN status = 'Draft' THEN 0
                         WHEN status = 'Performer Invoiced' THEN 1
                         WHEN status = 'Approved' THEN 2
                         ELSE 3 END,
                    created_at DESC
                LIMIT 8
            ");
            $estimationsStmt->execute();
            $context['items'] = $estimationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $context['error'] = 'Estimation context fetch warning: ' . $e->getMessage();
        }
        return $context;
    }
}

if (!function_exists('ai_fetch_sales_context')) {
    function ai_fetch_sales_context(PDO $pdo, $userId) {
        $context = [];
        try {
            $summaryStmt = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM invoices WHERE estimation_id IS NOT NULL) AS invoiced_sales_count,
                    (SELECT COUNT(*) FROM invoices WHERE estimation_id IS NULL) AS direct_sales_count,
                    COALESCE(SUM(CASE WHEN estimation_id IS NOT NULL THEN total_amount ELSE 0 END), 0) AS invoiced_sales_value,
                    COALESCE(SUM(CASE WHEN estimation_id IS NULL THEN total_amount ELSE 0 END), 0) AS direct_sales_value,
                    COALESCE(SUM(total_amount), 0) AS total_sales,
                    COALESCE(SUM(paid_amount), 0) AS total_collected
                FROM invoices
            ");
            $summaryStmt->execute();
            $context['summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $salesStmt = $pdo->prepare("
                SELECT 
                    invoice_number, customer_name, 
                    CASE WHEN estimation_id IS NOT NULL THEN 'Estimated Sale' ELSE 'Direct Sale' END AS sale_type,
                    total_amount, paid_amount, balance,
                    status, generated_date
                FROM invoices
                ORDER BY generated_date DESC
                LIMIT 8
            ");
            $salesStmt->execute();
            $context['items'] = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $context['error'] = 'Sales context fetch warning: ' . $e->getMessage();
        }
        return $context;
    }
}

if (!function_exists('ai_fetch_feature_context')) {
    function ai_fetch_feature_context(PDO $pdo, $userId, $feature) {
        $userId = (int) $userId;
        $feature = strtolower((string) $feature);
        $context = [];

        try {
            if ($feature === 'tasks') {
                $summaryStmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_tasks,
                        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_tasks,
                        SUM(CASE WHEN status <> 'Completed' THEN 1 ELSE 0 END) AS open_tasks
                    FROM tasks
                    WHERE assigned_to = :user_id OR created_by = :user_id
                ");
                $summaryStmt->execute(['user_id' => $userId]);
                $context['summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $taskStmt = $pdo->prepare("
                    SELECT name, status, priority, due_date
                    FROM tasks
                    WHERE assigned_to = :user_id OR created_by = :user_id
                    ORDER BY
                        CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,
                        due_date ASC
                    LIMIT 6
                ");
                $taskStmt->execute(['user_id' => $userId]);
                $context['items'] = $taskStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($feature === 'projects') {
                $summaryStmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_projects,
                        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_projects,
                        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS active_projects
                    FROM projects
                    WHERE created_by = :user_id
                ");
                $summaryStmt->execute(['user_id' => $userId]);
                $context['summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $projectStmt = $pdo->prepare("
                    SELECT name, status, start_date, end_date
                    FROM projects
                    WHERE created_by = :user_id
                    ORDER BY
                        CASE WHEN end_date IS NULL THEN 1 ELSE 0 END,
                        end_date ASC
                    LIMIT 6
                ");
                $projectStmt->execute(['user_id' => $userId]);
                $context['items'] = $projectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($feature === 'reminders') {
                $summaryStmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_reminders,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_reminders,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_reminders
                    FROM reminders
                    WHERE user_id = :user_id
                ");
                $summaryStmt->execute(['user_id' => $userId]);
                $context['summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $reminderStmt = $pdo->prepare("
                    SELECT title, status, priority, due_on, remind_at
                    FROM reminders
                    WHERE user_id = :user_id
                    ORDER BY
                        CASE WHEN COALESCE(remind_at, due_on) IS NULL THEN 1 ELSE 0 END,
                        COALESCE(remind_at, due_on) ASC
                    LIMIT 6
                ");
                $reminderStmt->execute(['user_id' => $userId]);
                $context['items'] = $reminderStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($feature === 'invoices') {
                $context = ai_fetch_invoices_context($pdo, $userId);
            } elseif ($feature === 'estimations') {
                $context = ai_fetch_estimations_context($pdo, $userId);
            } elseif ($feature === 'sales') {
                $context = ai_fetch_sales_context($pdo, $userId);
            } else {
                $tasksStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = :user_id OR created_by = :user_id");
                $tasksStmt->execute(['user_id' => $userId]);
                $tasksCount = (int) $tasksStmt->fetchColumn();

                $projectsStmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE created_by = :user_id");
                $projectsStmt->execute(['user_id' => $userId]);
                $projectsCount = (int) $projectsStmt->fetchColumn();

                $remindersStmt = $pdo->prepare("SELECT COUNT(*) FROM reminders WHERE user_id = :user_id");
                $remindersStmt->execute(['user_id' => $userId]);
                $remindersCount = (int) $remindersStmt->fetchColumn();

                $invoicesStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE balance > 0");
                $invoicesStmt->execute();
                $invoicesCount = (int) $invoicesStmt->fetchColumn();

                $estimationsStmt = $pdo->prepare("SELECT COUNT(*) FROM estimations WHERE status != 'Invoiced'");
                $estimationsStmt->execute();
                $estimationsCount = (int) $estimationsStmt->fetchColumn();

                $context['summary'] = [
                    'tasks' => $tasksCount,
                    'projects' => $projectsCount,
                    'reminders' => $remindersCount,
                    'unpaid_invoices' => $invoicesCount,
                    'pending_estimations' => $estimationsCount,
                ];
                $context['items'] = [];
            }
        } catch (Exception $e) {
            $context['summary'] = $context['summary'] ?? [];
            $context['items'] = $context['items'] ?? [];
            $context['error'] = 'Context fetch warning: ' . $e->getMessage();
        }

        return $context;
    }
}

if (!function_exists('ai_build_system_prompt')) {
    function ai_build_system_prompt($feature) {
        $feature = strtolower((string) $feature);
        $featureInstructions = [
            -'tasks' => 'Focus on task prioritization, blockers, and execution suggestions. Analyze the provided task context to identify pending tasks (those with status other than "Completed"), highlight priorities, and suggest next steps.',
            'projects' => 'Focus on project progress, milestones, and risk forecasting. Analyze the provided project context to identify active projects (status "In Progress"), assess timelines, and highlight potential risks.',
            'reminders' => 'Focus on schedule optimization and follow-up reminders. Analyze the provided reminder context to identify active reminders (status "active"), prioritize by due date, and suggest optimal follow-up timing.',
            'invoices' => 'Focus on financial health and payment collection. Analyze invoice statuses (Paid, Unpaid, Partially Paid, Overdue, Cancelled), identify payment patterns, highlight overdue invoices, and suggest collection strategies. Alert on overdue invoices with high balances. Provide cash flow insights.',
            'estimations' => 'Focus on cost management and profitability. Analyze estimation statuses (Draft, Performer Invoiced, Approved, Invoiced), track profit margins, identify bottlenecks in approval/conversion process, and suggest pricing optimization. Highlight estimations stuck in early stages. Analyze labour cost distribution.',
            'sales' => 'Focus on revenue tracking and sales performance. Compare estimated sales vs direct sales, analyze revenue trends, track payment collection, identify top customers and products, and provide sales pipeline insights. Highlight sales mix and conversion metrics.',
            'analysis' => 'Focus on concise operational insights and projections. Analyze all provided ERP context data to give actionable insights about business operations.',
        ];

        $feature_specific = $featureInstructions[$feature] ?? $featureInstructions['analysis'];

        return implode("\n", [
            'You are the ERP AI Assistant for operations management.',
            'YOU HAVE BEEN PROVIDED WITH REAL ERP CONTEXT DATA IN JSON FORMAT. Use this data to answer the user\'s question.',
            'Always analyze and reference the provided ERP context - it contains the actual data you need to answer accurately.',
            'For financial queries: Analyze totals, balances, outstanding amounts. For invoices, unpaid/overdue are critical. For estimations, profit margins and status are key. For sales, track revenue and collections.',
            'Pending items typically means: tasks/projects (not Completed/In Progress), invoices (balance > 0 or Overdue), estimations (status not Invoiced).',
            'Provide concise, practical recommendations in plain language based on the actual data provided.',
            'Never claim to execute actions in the ERP; provide guidance only.',
            'If user asks about "pending", check the "items" array for pending work. For financial pending, analyze balance > 0 invoices.',
            'When referencing data, be specific: include numbers (amounts, balances, percentages), statuses, customer names, and due dates from the context.',
            'Highlight risks: overdue invoices, long approval times, low profit margins, payment collection issues.',
            'Do not ask for parameters or data that has already been provided to you in the context.',
            $feature_specific,
        ]);
    }
}

if (!function_exists('ai_feature_is_enabled')) {
    function ai_feature_is_enabled(array $settings, $feature) {
        $feature = strtolower((string) $feature);
        if (!isset($settings['features']) || !is_array($settings['features'])) {
            return false;
        }

        return !empty($settings['features'][$feature]);
    }
}

if (!function_exists('ai_dispatch_provider_chat')) {
    function ai_dispatch_provider_chat(array $settings, array $messages) {
        $provider = strtolower((string) ($settings['provider'] ?? 'openai'));
        if ($provider === 'openai') {
            return ai_openai_chat($settings, $messages);
        }

        if ($provider === 'ollama') {
            return ai_ollama_chat($settings, $messages);
        }

        return [
            'success' => false,
            'message' => 'Unsupported AI provider: ' . $provider,
        ];
    }
}

if (!function_exists('ai_chat')) {
    /**
     * @return array<string,mixed>
     */
    function ai_chat(PDO $pdo, $userId, $message, $requestedFeature = '') {
        $settings = get_ai_settings();
        $userId = (int) $userId;
        $rawMessage = trim((string) $message);
        $feature = ai_detect_feature($rawMessage, $requestedFeature);

        if (empty($settings['enabled'])) {
            return ['success' => false, 'message' => 'AI assistant is currently disabled by system administration.', 'status_code' => 403];
        }

        if (!ai_feature_is_enabled($settings, $feature)) {
            return ['success' => false, 'message' => 'This AI feature is currently disabled by system administration.', 'status_code' => 403];
        }

        if ($rawMessage === '') {
            return ['success' => false, 'message' => 'Please enter a message for the assistant.', 'status_code' => 400];
        }

        if (mb_strlen($rawMessage) > 2500) {
            return ['success' => false, 'message' => 'Message is too long. Keep prompts under 2500 characters.', 'status_code' => 400];
        }

        if (ai_provider_requires_api_key($settings['provider'] ?? 'openai') && trim((string) ($settings['api_key'] ?? '')) === '') {
            return ['success' => false, 'message' => 'AI provider API key is not configured.', 'status_code' => 503];
        }

        $monthlyBudget = (float) ($settings['monthly_budget'] ?? 0);
        if ($monthlyBudget > 0) {
            try {
                $spent = ai_get_monthly_spend($pdo);
                if ($spent >= $monthlyBudget) {
                    return ['success' => false, 'message' => 'AI monthly budget limit has been reached. Contact your administrator.', 'status_code' => 429];
                }
            } catch (Exception $e) {
            }
        }

        $redactedMessage = ai_redact_sensitive_text($rawMessage);
        $context = ai_fetch_feature_context($pdo, $userId, $feature);

        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $contextMessage = "ERP CONTEXT DATA (This is real data from the system - use it to answer the user's question):\n" . 
                          "Feature: " . ucfirst($feature) . "\n" .
                          "Summary: " . json_encode($context['summary'] ?? []) . "\n" .
                          "Items: " . json_encode($context['items'] ?? []) . "\n" .
                          (isset($context['error']) ? "Note: " . $context['error'] : "All data loaded successfully.");

        $messages = [
            ['role' => 'system', 'content' => ai_build_system_prompt($feature)],
            ['role' => 'user', 'content' => "User request:\n" . $redactedMessage],
            ['role' => 'user', 'content' => $contextMessage],
        ];

        $providerResult = ai_dispatch_provider_chat($settings, $messages);
        if (empty($providerResult['success'])) {
            ai_log_usage_event($pdo, [
                'user_id' => $userId,
                'feature' => $feature,
                'model' => (string) ($settings['model'] ?? ''),
                'status' => 'error',
                'error_message' => (string) ($providerResult['message'] ?? 'Provider error'),
                'request_excerpt' => mb_substr($redactedMessage, 0, 500),
            ]);

            return [
                'success' => false,
                'message' => (string) ($providerResult['message'] ?? 'AI provider request failed.'),
                'status_code' => 502,
            ];
        }

        $promptTokens = (int) ($providerResult['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($providerResult['usage']['completion_tokens'] ?? 0);
        $totalTokens = (int) ($providerResult['usage']['total_tokens'] ?? ($promptTokens + $completionTokens));
        $estimatedCost = ai_estimate_cost_usd($promptTokens, $completionTokens, (string) ($settings['provider'] ?? 'openai'));
        $answer = trim((string) ($providerResult['content'] ?? ''));

        ai_log_usage_event($pdo, [
            'user_id' => $userId,
            'feature' => $feature,
            'model' => (string) ($providerResult['model'] ?? ($settings['model'] ?? '')),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_cost' => $estimatedCost,
            'status' => 'ok',
            'request_excerpt' => mb_substr($redactedMessage, 0, 500),
            'response_excerpt' => mb_substr($answer, 0, 500),
        ]);

        return [
            'success' => true,
            'message' => 'ok',
            'feature' => $feature,
            'answer' => $answer,
            'model' => (string) ($providerResult['model'] ?? ($settings['model'] ?? '')),
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost' => round($estimatedCost, 6),
            ],
            'status_code' => 200,
        ];
    }
}

if (!function_exists('ai_test_connection')) {
    /**
     * @param array<string,mixed> $overrideSettings
     * @return array<string,mixed>
     */
    function ai_test_connection(array $overrideSettings = []) {
        $settings = array_merge(get_ai_settings(), $overrideSettings);
        if (ai_provider_requires_api_key($settings['provider'] ?? 'openai') && trim((string) ($settings['api_key'] ?? '')) === '') {
            return ['success' => false, 'message' => 'API key is required for connection test.'];
        }

        $messages = [
            ['role' => 'system', 'content' => 'Reply with a short confirmation that the AI connection is healthy.'],
            ['role' => 'user', 'content' => 'Health check'],
        ];

        $result = ai_dispatch_provider_chat($settings, $messages);
        if (empty($result['success'])) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Connection test failed.')];
        }

        return [
            'success' => true,
            'message' => 'Connection successful. Provider responded using model ' . (string) ($result['model'] ?? ($settings['model'] ?? 'unknown')) . '.',
        ];
    }
}

?>
