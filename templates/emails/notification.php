<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #e8488a 0%, #d63a78 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .alert { padding: 15px; background: #fcf8e3; border: 1px solid #faebcc; border-radius: 4px; margin-bottom: 15px; }
        .button { display: inline-block; padding: 12px 24px; background: #e8488a; color: white !important; text-decoration: none; border-radius: 5px; margin-top: 15px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p>Gov Press ERP System</p>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($userName); ?>,</p>

            <div class="alert">
                <p><?php echo nl2br(htmlspecialchars($message)); ?></p>
            </div>

            <?php if (!empty($actionUrl)): ?>
            <p>
                <a href="<?php echo htmlspecialchars($actionUrl); ?>" class="button">View Now</a>
            </p>
            <p style="font-size:12px;color:#888;">Or copy this link: <?php echo htmlspecialchars($actionUrl); ?></p>
            <?php else: ?>
            <p>Please log in to your account to view more details and take action if needed.</p>
            <?php endif; ?>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Gov Press Enterprise Resource Planning System. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
