<?php
/**
 * MailManager - SMTP Email Sender
 * 
 * Handles sending emails via SMTP or PHP mail function
 * Supports email templates and logging
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class MailManager {
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $fromAddress;
    private $fromName;
    private $driver;
    private $pdo;
    private $appName;

    public function __construct($pdo, $settings = []) {
        $this->pdo = $pdo;
        
        // Use provided settings or load from config
        $this->host = $settings['host'] ?? (defined('MAIL_HOST') ? MAIL_HOST : '');
        $this->port = $settings['port'] ?? (defined('MAIL_PORT') ? MAIL_PORT : 587);
        $this->username = $settings['username'] ?? (defined('MAIL_USERNAME') ? MAIL_USERNAME : '');
        $this->password = $settings['password'] ?? (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '');
        $this->encryption = $settings['encryption'] ?? (defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls');
        $this->fromAddress = $settings['from_address'] ?? (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '');
        $this->fromName = $settings['from_name'] ?? (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Gov Press ERP');
        $this->driver = $settings['driver'] ?? (defined('MAIL_DRIVER') ? MAIL_DRIVER : 'php');
        $this->appName = defined('APP_NAME') ? APP_NAME : 'Gov Press ERP';

        if (empty($this->fromAddress)) {
            $this->fromAddress = 'noreply@press-erp-main.local';
        }
    }

    /**
     * Send email
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body HTML email body
     * @param array $cc CC recipients (optional)
     * @param array $bcc BCC recipients (optional)
     * @param array $attachments File paths to attach (optional)
     * @return array ['success' => bool, 'message' => string, 'error' => string|null]
     */
    public function send($to, $subject, $body, $cc = [], $bcc = [], $attachments = []) {
        try {
            // Validate email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid recipient email',
                    'error' => 'Invalid email: ' . $to
                ];
            }

            if ($this->driver === 'smtp') {
                return $this->sendViaSMTP($to, $subject, $body, $cc, $bcc, $attachments);
            } else {
                return $this->sendViaMailFunction($to, $subject, $body, $cc, $bcc);
            }
        } catch (Exception $e) {
            $this->logEmail($to, $subject, false, $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to send email',
                'error' => $e->getMessage(),
                'recipient' => $to,
                'provider' => $this->driver === 'smtp' ? 'smtp' : 'php-mail',
            ];
        }
    }

    /**
     * Send email via SMTP using PHPMailer
     */
    private function sendViaSMTP($to, $subject, $body, $cc = [], $bcc = [], $attachments = []) {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->Timeout    = 15;
            
            if ($this->encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
            
            $mail->Port       = $this->port;

            // Recipients
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($this->fromAddress, $this->fromName);

            foreach ($cc as $ccEmail) {
                $mail->addCC($ccEmail);
            }

            foreach ($bcc as $bccEmail) {
                $mail->addBCC($bccEmail);
            }

            // Attachments
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            
            $this->logEmail($to, $subject, true);
            
            return [
                'success' => true,
                'message' => 'Email sent successfully via SMTP',
                'error' => null,
                'recipient' => $to,
                'provider' => 'smtp',
            ];
        } catch (PHPMailerException $e) {
            $this->logEmail($to, $subject, false, $mail->ErrorInfo);
            
            return [
                'success' => false,
                'message' => 'Failed to send email via SMTP',
                'error' => $mail->ErrorInfo,
                'recipient' => $to,
                'provider' => 'smtp',
            ];
        }
    }

    /**
     * Send email via PHP mail() function with proper headers
     */
    private function sendViaMailFunction($to, $subject, $body, $cc = [], $bcc = []) {
        try {
            // Prepare headers
            $headers = [];
            $headers[] = 'From: ' . $this->fromName . ' <' . $this->fromAddress . '>';
            $headers[] = 'Reply-To: ' . $this->fromAddress;
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $headers[] = 'X-Priority: 3';
            $headers[] = 'X-Mailer: ' . $this->appName;

            if (!empty($cc)) {
                $headers[] = 'Cc: ' . implode(', ', $cc);
            }

            if (!empty($bcc)) {
                $headers[] = 'Bcc: ' . implode(', ', $bcc);
            }

            $headerString = implode("\r\n", $headers);

            // Additional parameters for mail function
            $additionalParams = '-f' . $this->fromAddress;

            // Send email using PHP mail function
            if (mail($to, $subject, $body, $headerString, $additionalParams)) {
                $this->logEmail($to, $subject, true);
                
                return [
                    'success' => true,
                    'message' => 'Email sent successfully',
                    'error' => null,
                    'recipient' => $to,
                    'provider' => 'php-mail',
                ];
            } else {
                throw new Exception('mail() function returned false');
            }
        } catch (Exception $e) {
            $this->logEmail($to, $subject, false, $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to send email',
                'error' => $e->getMessage(),
                'recipient' => $to,
                'provider' => 'php-mail',
            ];
        }
    }

    /**
     * Log email to database
     */
    private function logEmail($to, $subject, $success, $error = null) {
        if (function_exists('isMailLogEnabled') && !isMailLogEnabled()) {
            return;
        }

        if (!function_exists('isMailLogEnabled') && !MAIL_LOG_ENABLED) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_log (recipient, subject, success, error_message, sent_at)
                VALUES (:recipient, :subject, :success, :error, NOW())
            ");

            $stmt->execute([
                'recipient' => $to,
                'subject' => $subject,
                'success' => $success ? 1 : 0,
                'error' => $error
            ]);
        } catch (Exception $e) {
            // Silently fail if logging fails
        }
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($to, $userName, $resetLink) {
        $subject = "Password Reset Request - " . $this->appName;
        
        $body = $this->renderTemplate('password_reset', [
            'userName' => $userName,
            'resetLink' => $resetLink,
            'expiryTime' => '1 hour'
        ]);

        return $this->send($to, $subject, $body);
    }

    /**
     * Send account notification email
     */
    public function sendNotificationEmail($to, $userName, $title, $message, $link = null, $type = null, $context = []) {
        $subject = $title . " - " . $this->appName . " Notification";

        $fullLink = $link ? BASE_URL . ltrim($link, '/') : null;

        // Use type-specific template if available
        $templateName = 'notification';
        if ($type && file_exists(ROOT_PATH . 'templates/emails/' . $type . '_notification.php')) {
            $templateName = $type . '_notification';
        }

        $templateData = array_merge([
            'userName' => $userName,
            'title' => $title,
            'message' => $message,
            'actionUrl' => $fullLink,
        ], $context);

        $body = $this->renderTemplate($templateName, $templateData);

        return $this->send($to, $subject, $body);
    }

    /**
     * Send message notification email
     */
    public function sendMessageNotificationEmail($to, $fromUser, $subject, $messagePreview) {
        $emailSubject = "New Message: " . $subject;
        
        $body = $this->renderTemplate('message_notification', [
            'fromUser' => $fromUser,
            'subject' => $subject,
            'messagePreview' => $messagePreview,
            'inboxUrl' => BASE_URL . 'modules/messaging/inbox'
        ]);

        return $this->send($to, $emailSubject, $body);
    }

    /**
     * Send dispatch notification email
     */
    public function sendDispatchNotificationEmail($to, $userName, $dispatchNumber, $status) {
        $subject = "Dispatch Update: " . $dispatchNumber . " - Status: " . $status;
        
        $body = $this->renderTemplate('dispatch_notification', [
            'userName' => $userName,
            'dispatchNumber' => $dispatchNumber,
            'status' => $status,
            'dispatchUrl' => BASE_URL . 'modules/dispatch/list'
        ]);

        return $this->send($to, $subject, $body);
    }

    /**
     * Render email template
     */
    private function renderTemplate($templateName, $data = []) {
        $templatePath = ROOT_PATH . 'templates/emails/' . $templateName . '.php';

        if (!file_exists($templatePath)) {
            // Fallback to simple text template
            return $this->getDefaultTemplate($templateName, $data);
        }

        ob_start();
        extract($data);
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Get default email template
     */
    private function getDefaultTemplate($templateName, $data = []) {
        $html = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #e8488a 0%, #d63a78 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                .button { display: inline-block; padding: 10px 20px; background: #e8488a; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . htmlspecialchars($this->appName) . '</h1>
                </div>
                <div class="content">
        ';

        switch ($templateName) {
            case 'password_reset':
                $html .= '<p>Hello ' . htmlspecialchars($data['userName']) . ',</p>';
                $html .= '<p>You requested a password reset for your ' . htmlspecialchars($this->appName) . ' account.</p>';
                $html .= '<p><a href="' . htmlspecialchars($data['resetLink']) . '" class="button">Reset Your Password</a></p>';
                $html .= '<p>This link will expire in ' . htmlspecialchars($data['expiryTime']) . '.</p>';
                $html .= '<p>If you did not request this, please ignore this email.</p>';
                break;

            case 'notification':
                $html .= '<p>Hello ' . htmlspecialchars($data['userName']) . ',</p>';
                $html .= '<h2>' . htmlspecialchars($data['title']) . '</h2>';
                $html .= '<p>' . nl2br(htmlspecialchars($data['message'])) . '</p>';
                break;

            case 'message_notification':
                $html .= '<p>Hello,</p>';
                $html .= '<p>You have a new message from <strong>' . htmlspecialchars($data['fromUser']) . '</strong></p>';
                $html .= '<p><strong>Subject:</strong> ' . htmlspecialchars($data['subject']) . '</p>';
                $html .= '<p><strong>Preview:</strong></p>';
                $html .= '<blockquote>' . nl2br(htmlspecialchars(substr($data['messagePreview'], 0, 200))) . '...</blockquote>';
                $html .= '<p><a href="' . htmlspecialchars($data['inboxUrl']) . '" class="button">View Full Message</a></p>';
                break;

            case 'dispatch_notification':
                $html .= '<p>Hello ' . htmlspecialchars($data['userName']) . ',</p>';
                $html .= '<p>Dispatch <strong>' . htmlspecialchars($data['dispatchNumber']) . '</strong> has been updated.</p>';
                $html .= '<p><strong>New Status:</strong> ' . htmlspecialchars($data['status']) . '</p>';
                $html .= '<p><a href="' . htmlspecialchars($data['dispatchUrl']) . '" class="button">View Dispatch</a></p>';
                break;
        }

        $html .= '
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($this->appName) . '. All rights reserved.</p>
                    <p>Please do not reply to this email. Contact support if you have questions.</p>
                </div>
            </div>
        </body>
        </html>
        ';

        return $html;
    }

    /**
     * Test SMTP connection
     */
    public function testConnection() {
        if ($this->driver === 'smtp') {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $this->host;
                $mail->SMTPAuth = true;
                $mail->Username = $this->username;
                $mail->Password = $this->password;
                
                if ($this->encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($this->encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                }
                
                $mail->Port = $this->port;
                $mail->Timeout = 10; // 10 second timeout

                if ($mail->smtpConnect()) {
                    $mail->smtpClose();
                    return [
                        'success' => true,
                        'message' => 'SMTP connection successful'
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connected to server but authentication failed'
                    ];
                }
            } catch (PHPMailerException $e) {
                return [
                    'success' => false,
                    'message' => 'SMTP connection failed: ' . $mail->ErrorInfo
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => 'SMTP connection failed: ' . $e->getMessage()
                ];
            }
        }

        try {
            // For mail() function, test by checking if mail configuration exists
            if (ini_get('sendmail_path') || ini_get('SMTP')) {
                return [
                    'success' => true,
                    'message' => 'Mail system is configured and ready'
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'Mail system available (using server default configuration)'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Mail system test failed: ' . $e->getMessage()
            ];
        }
    }
}
?>
