<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #e8488a 0%, #d63a78 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { display: inline-block; padding: 10px 20px; background: #e8488a; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px; }
        .status-badge { display: inline-block; padding: 5px 10px; background: #d9edf7; color: #31708f; border-radius: 3px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Dispatch Update</h1>
            <p>Gov Press ERP System</p>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($userName); ?>,</p>
            
            <p>Your dispatch has been updated:</p>
            
            <p>
                <strong>Dispatch Number:</strong> <?php echo htmlspecialchars($dispatchNumber); ?><br>
                <strong>Status:</strong> <span class="status-badge"><?php echo htmlspecialchars($status); ?></span>
            </p>
            
            <p><a href="<?php echo htmlspecialchars($dispatchUrl); ?>" class="button">View Dispatch Details</a></p>
            
            <p>Please log in to your account for more information and to take any necessary actions.</p>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Gov Press Enterprise Resource Planning System. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
