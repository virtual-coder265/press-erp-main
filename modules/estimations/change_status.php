<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_estimations']);
require_once __DIR__ . '/../../libs/EstimationStatusManager.php';

// Always set JSON header for error responses
header('Content-Type: application/json');

try {
    // Handle AJAX and form requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Debug: Log what we receive
        error_log("POST received. Raw POST: " . json_encode($_POST));
        error_log("SESSION: " . json_encode($_SESSION ?? []));

        $estimation_id = isset($_POST['estimation_id']) ? intval($_POST['estimation_id']) : 0;
        $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

        error_log("Parsed: est_id=$estimation_id, status=$new_status, reason=$reason");

        if (!$estimation_id || !$new_status) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameters: estimation_id and new_status required',
                'details' => [
                    'est_id' => $estimation_id,
                    'status' => $new_status,
                    'post_data' => $_POST
                ]
            ]);
            exit;
        }

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'User session not found. Please log in again.'
            ]);
            exit;
        }

        $statusManager = new EstimationStatusManager($pdo);
        $result = $statusManager->changeStatus($estimation_id, $new_status, $_SESSION['user_id'], $reason);

        // Check if this is AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            if ($result['success']) {
                http_response_code(200);
            } else {
                http_response_code(400);
            }
            echo json_encode($result);
        } else {
            // Regular form submission
            if ($result['success']) {
                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
            redirect('modules/estimations/view?id=' . $estimation_id);
        }
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("Exception in change_status.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Exception occurred: ' . $e->getMessage(),
        'type' => 'exception'
    ]);
    exit;
}

// GET request - show change status form (AJAX modal)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $estimation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$estimation_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'GET Request: Estimation ID is required',
            'debug_method' => $_SERVER['REQUEST_METHOD']
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM estimations WHERE id = :id");
        $stmt->execute(['id' => $estimation_id]);
        $estimation = $stmt->fetch();

        if (!$estimation) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Estimation not found',
                'estimation_id' => $estimation_id
            ]);
            exit;
        }

        $allowed_transitions = EstimationStatusManager::getAllowedTransitions($estimation['status']);
        $current_status_details = EstimationStatusManager::getStatusDetails($estimation['status']);

        if (!$current_status_details) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid status value in database: ' . htmlspecialchars($estimation['status'])
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'estimation_id' => $estimation['id'],
            'current_status' => $estimation['status'],
            'current_status_details' => $current_status_details,
            'allowed_transitions' => array_map(function ($status) {
                return [
                    'value' => $status,
                    'details' => EstimationStatusManager::getStatusDetails($status)
                ];
            }, $allowed_transitions)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Invalid request method or fallthrough
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Invalid request method or logic fallthrough.',
    'debug' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'post' => $_POST,
        'get' => $_GET,
        'input' => file_get_contents('php://input'),
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
    ]
]);
exit;
