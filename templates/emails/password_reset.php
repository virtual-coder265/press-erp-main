<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #e8488a 0%, #d63a78 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { display: inline-block; padding: 10px 20px; background: #e8488a; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
        .warning { color: #d9534f; font-size: 12px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Password Reset Request</h1>
            <p>Gov Press ERP System</p>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($userName); ?>,</p>
            
            <p>You requested a password reset for your Gov Press ERP account.</p>
            
            <p>Click the button below to reset your password:</p>
            
            <p><a href="<?php echo htmlspecialchars($resetLink); ?>" class="button">Reset Password</a></p>
            
            <p><strong>Or copy this link in your browser:</strong><br>
            <code><?php echo htmlspecialchars($resetLink); ?></code></p>
            
            <div class="warning">
                <p><strong>⚠️ Security Notice:</strong></p>
                <p>This password reset link will expire in <?php echo htmlspecialchars($expiryTime); ?>. If you did not request this reset, please ignore this email and your password will remain unchanged.</p>
            </div>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Gov Press Enterprise Resource Planning System. All rights reserved.</p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
