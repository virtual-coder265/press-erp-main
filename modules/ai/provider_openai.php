<?php

if (!function_exists('ai_openai_chat')) {
    /**
     * @param array<string,mixed> $settings
     * @param array<int,array<string,string>> $messages
     * @return array<string,mixed>
     */
    function ai_openai_chat(array $settings, array $messages) {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $model = trim((string) ($settings['model'] ?? 'gpt-4o-mini'));
        $maxTokens = max(64, (int) ($settings['max_tokens'] ?? 700));
        $timeoutSeconds = max(5, min(120, (int) ($settings['timeout_seconds'] ?? 30)));

        if ($apiKey === '') {
            return [
                'success' => false,
                'message' => 'OpenAI API key is not configured.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'cURL extension is required for AI provider requests.',
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.2,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
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
                'message' => 'AI request failed: ' . ($curlError !== '' ? $curlError : 'unknown transport error'),
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'AI response could not be parsed.',
            ];
        }

        if ($httpCode >= 400) {
            $errorMessage = (string) ($decoded['error']['message'] ?? 'Provider rejected request.');
            return [
                'success' => false,
                'message' => 'OpenAI error: ' . $errorMessage,
                'http_code' => $httpCode,
            ];
        }

        $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            return [
                'success' => false,
                'message' => 'AI response was empty.',
            ];
        }

        return [
            'success' => true,
            'message' => 'ok',
            'content' => $content,
            'model' => (string) ($decoded['model'] ?? $model),
            'usage' => [
                'prompt_tokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($decoded['usage']['total_tokens'] ?? 0),
            ],
            'raw' => $decoded,
        ];
    }
}

?>
