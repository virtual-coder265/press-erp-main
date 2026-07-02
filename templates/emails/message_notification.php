<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #e8488a 0%, #d63a78 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { display: inline-block; padding: 12px 24px; background: #e8488a; color: white !important; text-decoration: none; border-radius: 5px; margin-top: 15px; font-weight: bold; }
        .preview { background: white; padding: 15px; border-left: 4px solid #e8488a; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Message Received</h1>
            <p>Gov Press ERP System</p>
        </div>
        <div class="content">
            <p>Hello <?php echo !empty($userName) ? htmlspecialchars($userName) : 'there'; ?>,</p>

            <p><strong><?php echo htmlspecialchars($fromUser); ?></strong> has sent you a new message.</p>

            <p><strong>Subject:</strong> <?php echo htmlspecialchars($subject); ?></p>

            <div class="preview">
                <p><strong>Preview:</strong></p>
                <p><?php echo nl2br(htmlspecialchars(substr($messagePreview, 0, 300))); ?><?php echo strlen($messagePreview) > 300 ? '...' : ''; ?></p>
            </div>

            <p>
                <a href="<?php echo htmlspecialchars($inboxUrl ?? $actionUrl); ?>" class="button">View Full Message</a>
            </p>
            <p style="font-size:12px;color:#888;">Or copy this link: <?php echo htmlspecialchars($inboxUrl ?? $actionUrl); ?></p>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Gov Press Enterprise Resource Planning System. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
