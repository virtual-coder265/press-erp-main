<?php
/**
 * Custom HTTP error responses with audit logging and safe return navigation.
 */

if (!function_exists('app_validate_internal_path')) {
    /**
     * Accept only in-app relative paths (no scheme, no parent traversal).
     */
    function app_validate_internal_path(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $path) || str_starts_with($path, '//')) {
            return null;
        }

        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        if (!preg_match('#^(?:index|modules/[\w\-./]+)(?:\?[\w\-._~%&=]*)?$#', $path)) {
            return null;
        }

        return $path;
    }
}

if (!function_exists('app_current_request_path')) {
    function app_current_request_path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $basePath = parse_url(BASE_URL, PHP_URL_PATH);
        if (is_string($basePath) && $basePath !== '' && $basePath !== '/') {
            $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
            if (str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
            }
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        return $path;
    }
}

if (!function_exists('app_store_return_url')) {
    function app_store_return_url(?string $path = null): void
    {
        if ($path === null) {
            $path = app_current_request_path();
        }

        $validated = app_validate_internal_path($path);
        if ($validated === null) {
            return;
        }

        if (str_starts_with(strtolower($validated), 'modules/errors/')) {
            return;
        }

        $_SESSION['_app_return_url'] = $validated;
    }
}

if (!function_exists('app_return_url')) {
    function app_return_url(?string $fallback = null): string
    {
        $stored = isset($_SESSION['_app_return_url'])
            ? app_validate_internal_path((string) $_SESSION['_app_return_url'])
            : null;

        if ($stored !== null) {
            return $stored;
        }

        if ($fallback !== null) {
            $validatedFallback = app_validate_internal_path($fallback);
            if ($validatedFallback !== null) {
                return $validatedFallback;
            }
        }

        if (function_exists('dashboard_default_landing_path')) {
            return dashboard_default_landing_path();
        }

        return 'index';
    }
}

if (!function_exists('app_capture_request_return_url')) {
    /**
     * Remember the current page so errors can offer a safe rollback target.
     */
    function app_capture_request_return_url(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return;
        }

        app_store_return_url();
    }
}

if (!function_exists('app_is_api_request')) {
    function app_is_api_request(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return true;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if ($contentType !== '' && str_contains($contentType, 'application/json')) {
            return true;
        }

        return false;
    }
}

if (!function_exists('app_error_severity_for_code')) {
    function app_error_severity_for_code(int $code): string
    {
        if ($code >= 500) {
            return 'critical';
        }
        if ($code === 403 || $code === 401) {
            return 'warning';
        }

        return 'info';
    }
}

if (!function_exists('app_error_event_type_for_code')) {
    function app_error_event_type_for_code(int $code): string
    {
        return match ($code) {
            301 => 'moved_permanently',
            404 => 'not_found',
            403 => 'forbidden',
            401 => 'unauthorized',
            500 => 'server_error',
            503 => 'service_unavailable',
            default => 'http_' . $code,
        };
    }
}

if (!function_exists('app_log_http_error')) {
    function app_log_http_error(int $code, string $message, array $context = []): void
    {
        global $pdo;

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return;
        }

        try {
            require_once ROOT_PATH . 'libs/AuditLogger.php';
            $auditLogger = new AuditLogger($pdo);
            $auditOptions = [
                'severity' => app_error_severity_for_code($code),
                'user_id' => $_SESSION['user_id'] ?? null,
                'status' => (string) $code,
                'context' => array_merge($context, [
                    'return_url' => app_return_url(),
                    'request_path' => app_current_request_path(),
                ]),
            ];

            if (!empty($context['entity_type'])) {
                $auditOptions['entity_type'] = (string) $context['entity_type'];
            }
            if (array_key_exists('entity_id', $context) && $context['entity_id'] !== null && $context['entity_id'] !== '') {
                $auditOptions['entity_id'] = $context['entity_id'];
            }

            $auditLogger->log(
                'http',
                app_error_event_type_for_code($code),
                $message,
                $auditOptions
            );
        } catch (Throwable $e) {
            error_log('Failed to write HTTP error audit log: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_error_template_for_code')) {
    function app_error_template_for_code(int $code): string
    {
        $dedicated = [301, 404, 500];
        if (in_array($code, $dedicated, true)) {
            return (string) $code;
        }

        return 'generic';
    }
}

if (!function_exists('app_error_defaults_for_code')) {
    function app_error_defaults_for_code(int $code): array
    {
        return match ($code) {
            301 => [
                'title' => 'Permanently moved',
                'message' => 'This page or resource has moved to a new location.',
            ],
            404 => [
                'title' => 'Page not found',
                'message' => 'The page or record you requested does not exist or may have been removed.',
            ],
            500 => [
                'title' => 'Something went wrong',
                'message' => 'An unexpected error occurred while processing your request. Our team has been notified.',
            ],
            403 => [
                'title' => 'Access denied',
                'message' => 'You do not have permission to view or perform this action.',
            ],
            503 => [
                'title' => 'Service unavailable',
                'message' => APP_NAME . ' is temporarily unavailable. Please try again shortly.',
            ],
            default => [
                'title' => 'Request could not be completed',
                'message' => 'The server returned an unexpected response for your request.',
            ],
        };
    }
}

if (!function_exists('app_referer_internal_path')) {
    function app_referer_internal_path(): ?string
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return null;
        }

        $baseHost = parse_url(BASE_URL, PHP_URL_HOST);
        $refererHost = parse_url($referer, PHP_URL_HOST);
        if (is_string($baseHost) && $baseHost !== '' && is_string($refererHost) && $refererHost !== '') {
            if (strcasecmp($baseHost, $refererHost) !== 0) {
                return null;
            }
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $basePath = parse_url(BASE_URL, PHP_URL_PATH);
        if (is_string($basePath) && $basePath !== '' && $basePath !== '/') {
            $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
            if (str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
            }
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $query = parse_url($referer, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        return app_validate_internal_path($path);
    }
}

if (!function_exists('app_resolve_error_return_url')) {
    function app_resolve_error_return_url(array $options): string
    {
        foreach (['return_url', 'redirect_to'] as $key) {
            if (!empty($options[$key])) {
                $validated = app_validate_internal_path((string) $options[$key]);
                if ($validated !== null) {
                    return $validated;
                }
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $postRedirect = trim((string) ($_POST['redirect_to'] ?? ''));
            $validatedPost = app_validate_internal_path($postRedirect);
            if ($validatedPost !== null) {
                return $validatedPost;
            }
        }

        $stored = app_return_url(null);
        $current = app_validate_internal_path(app_current_request_path());
        if ($current !== null && $stored === $current) {
            $referer = app_referer_internal_path();
            if ($referer !== null && $referer !== $current) {
                return $referer;
            }
        }

        return $stored;
    }
}

if (!function_exists('app_render_error_json')) {
    function app_render_error_json(int $code, array $options = []): never
    {
        $defaults = app_error_defaults_for_code($code);
        $message = trim((string) ($options['message'] ?? $defaults['message']));
        $returnUrl = app_resolve_error_return_url($options);

        if (empty($options['skip_audit'])) {
            app_log_http_error($code, $message, (array) ($options['context'] ?? []));
        }

        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'status' => $code,
            'title' => (string) ($options['title'] ?? $defaults['title']),
            'message' => $message,
            'return_url' => BASE_URL . ltrim($returnUrl, '/'),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('app_render_error')) {
    /**
     * Render a branded error screen (or JSON for API requests) and preserve audit context.
     *
     * @param array{
     *   message?: string,
     *   title?: string,
     *   return_url?: string,
     *   redirect_to?: string,
     *   redirect_url?: string,
     *   skip_audit?: bool,
     *   context?: array,
     *   permission_slug?: string,
     * } $options
     */
    function app_render_error(int $code, array $options = []): never
    {
        if (app_is_api_request()) {
            app_render_error_json($code, $options);
        }

        $defaults = app_error_defaults_for_code($code);
        $message = trim((string) ($options['message'] ?? $defaults['message']));
        $title = trim((string) ($options['title'] ?? $defaults['title']));
        $returnUrl = app_resolve_error_return_url($options);
        $context = (array) ($options['context'] ?? []);

        if (!empty($options['permission_slug'])) {
            $context['permission_slug'] = (string) $options['permission_slug'];
        }

        if (!empty($options['redirect_url'])) {
            $validatedRedirect = app_validate_internal_path((string) $options['redirect_url']);
            if ($validatedRedirect !== null) {
                $context['redirect_url'] = $validatedRedirect;
            }
        }

        if (empty($options['skip_audit'])) {
            app_log_http_error($code, $message, $context);
        }

        http_response_code($code);

        $template = app_error_template_for_code($code);
        $errorCode = $code;
        $errorTitle = $title;
        $errorMessage = $message;
        $errorReturnUrl = $returnUrl;
        $errorHomeUrl = function_exists('dashboard_default_landing_path')
            ? dashboard_default_landing_path()
            : 'index';
        $errorRedirectUrl = $context['redirect_url'] ?? null;
        $errorShowRetry = $code >= 500;

        require ROOT_PATH . 'includes/partials/errors/_layout.php';
        exit;
    }
}

if (!function_exists('app_register_error_handlers')) {
    function app_register_error_handlers(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        set_exception_handler(static function (Throwable $exception): void {
            error_log('Unhandled exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());

            $message = env('APP_ENV', 'development') === 'production'
                ? 'An unexpected error occurred while processing your request.'
                : $exception->getMessage();

            app_render_error(500, [
                'message' => $message,
                'context' => [
                    'exception_class' => get_class($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
            ]);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            if (headers_sent()) {
                return;
            }

            error_log('Fatal error: ' . ($error['message'] ?? 'unknown') . ' in ' . ($error['file'] ?? '') . ':' . ($error['line'] ?? ''));

            app_render_error(500, [
                'message' => env('APP_ENV', 'development') === 'production'
                    ? 'A critical error stopped this request.'
                    : (string) ($error['message'] ?? 'Fatal error'),
                'context' => [
                    'file' => $error['file'] ?? null,
                    'line' => $error['line'] ?? null,
                    'type' => $error['type'] ?? null,
                ],
            ]);
        });
    }
}

if (!function_exists('app_not_found')) {
    function app_not_found(string $message = '', array $options = []): never
    {
        if ($message !== '') {
            $options['message'] = $message;
        }

        app_render_error(404, $options);
    }
}

if (!function_exists('app_forbidden')) {
    function app_forbidden(string $message = '', array $options = []): never
    {
        if ($message !== '') {
            $options['message'] = $message;
        }

        app_render_error(403, $options);
    }
}

if (!function_exists('app_server_error')) {
    function app_server_error(string $message = '', array $options = []): never
    {
        if ($message !== '') {
            $options['message'] = $message;
        }

        app_render_error(500, $options);
    }
}

if (!function_exists('app_moved_permanently')) {
    function app_moved_permanently(string $newPath, string $message = '', array $options = []): never
    {
        $options['redirect_url'] = $newPath;
        if ($message !== '') {
            $options['message'] = $message;
        }

        app_render_error(301, $options);
    }
}
