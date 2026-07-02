<?php

if (!function_exists('ai_ollama_base_url')) {
    function ai_ollama_base_url(array $settings) {
        $baseUrl = trim((string) ($settings['ollama_base_url'] ?? 'http://127.0.0.1:11434'));
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:11434';
        }

        $baseUrl = rtrim($baseUrl, '/');
        if (preg_match('#/api$#i', $baseUrl)) {
            $baseUrl = substr($baseUrl, 0, -4);
        }

        return rtrim($baseUrl, '/');
    }
}

if (!function_exists('ai_ollama_chat')) {
    /**
     * @param array<string,mixed> $settings
     * @param array<int,array<string,string>> $messages
     * @return array<string,mixed>
     */
    function ai_ollama_chat(array $settings, array $messages) {
        $baseUrl = ai_ollama_base_url($settings);
        $model = trim((string) ($settings['model'] ?? 'llama3.2:1b'));
        $maxTokens = max(64, (int) ($settings['max_tokens'] ?? 700));
        $timeoutSeconds = max(5, min(120, (int) ($settings['timeout_seconds'] ?? 45)));

        if ($model === '') {
            $model = 'llama3.2:1b';
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'cURL extension is required for Ollama provider requests.',
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => array_map(static function ($message) {
                return [
                    'role' => (string) ($message['role'] ?? 'user'),
                    'content' => (string) ($message['content'] ?? ''),
                ];
            }, $messages),
            'stream' => false,
            'options' => [
                'temperature' => 0.2,
                'num_predict' => $maxTokens,
            ],
        ];

        $ch = curl_init($baseUrl . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success' => false,
                'message' => 'Ollama is not reachable at ' . $baseUrl . '. Start Ollama locally and confirm the model has been pulled. Transport error: ' . ($curlError !== '' ? $curlError : 'unknown error'),
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'Ollama response could not be parsed.',
            ];
        }

        if ($httpCode >= 400) {
            $errorMessage = (string) ($decoded['error'] ?? 'Ollama rejected request.');
            return [
                'success' => false,
                'message' => 'Ollama error: ' . $errorMessage,
                'http_code' => $httpCode,
            ];
        }

        $content = trim((string) ($decoded['message']['content'] ?? ''));
        if ($content === '') {
            return [
                'success' => false,
                'message' => 'Ollama response was empty.',
            ];
        }

        $promptTokens = (int) ($decoded['prompt_eval_count'] ?? 0);
        $completionTokens = (int) ($decoded['eval_count'] ?? 0);

        return [
            'success' => true,
            'message' => 'ok',
            'content' => $content,
            'model' => (string) ($decoded['model'] ?? $model),
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
            'raw' => $decoded,
        ];
    }
}

?>
