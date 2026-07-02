<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../libs/MailManager.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null, 'forgot_password')) {
        $_SESSION['error_message'] = 'Your request could not be verified. Please try again.';
        header('Location: forgot_password');
        exit;
    }

    $email = $_POST['email'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_message'] = 'Invalid email format';
        header('Location: forgot_password');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $update = $pdo->prepare("UPDATE users SET reset_token = :token, reset_expires_at = :expires_at WHERE id = :id");
        $update->execute([
            'token' => $token,
            'expires_at' => $expires_at,
            'id' => $user['id']
        ]);

        $reset_link = BASE_URL . "modules/auth/reset_password?token=" . $token;
        $mailSettings = getMailSettings();
        $mailManager = new MailManager($pdo, $mailSettings);

        // Send password reset email
        $result = $mailManager->sendPasswordResetEmail(
            $email,
            $user['name'],
            $reset_link
        );

        if ($result['success']) {
             $_SESSION['success_message'] = 'Password reset link has been sent to your email. Please check your inbox.';
        } else {
             $_SESSION['error_message'] = 'Failed to send password reset email. Please try again later.';
             // Log the error for debugging
             error_log('Password reset email failed: ' . $result['error']);
        }
    } else {
        // For security: don't reveal if email exists
        $_SESSION['success_message'] = 'If an account exists with that email, a password reset link has been sent.';
    }

    header('Location: forgot_password');
    exit;
} else {
    header('Location: forgot_password');
    exit;
}
