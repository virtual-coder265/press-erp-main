<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { display: inline-block; padding: 12px 24px; background: #2563eb; color: white !important; text-decoration: none; border-radius: 5px; margin-top: 15px; font-weight: bold; }
        .wo-details { background: white; border: 1px solid #e0e0e0; border-radius: 4px; padding: 15px; margin: 15px 0; }
        .wo-details table { width: 100%; border-collapse: collapse; }
        .wo-details td { padding: 6px 0; vertical-align: top; }
        .wo-details td:first-child { font-weight: bold; width: 140px; color: #555; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Work Order Update</h1>
            <p>Gov Press ERP System</p>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($userName); ?>,</p>

            <p><?php echo htmlspecialchars($message ?? $title); ?></p>

            <div class="wo-details">
                <table>
                    <tr>
                        <td>Work order:</td>
                        <td><?php echo htmlspecialchars($workOrderNumber ?? ''); ?></td>
                    </tr>
                    <?php if (!empty($department)): ?>
                    <tr>
                        <td>Department:</td>
                        <td><?php echo htmlspecialchars($department); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($fromDepartment)): ?>
                    <tr>
                        <td>From:</td>
                        <td><?php echo htmlspecialchars($fromDepartment); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($senderName)): ?>
                    <tr>
                        <td>Sent by:</td>
                        <td><?php echo htmlspecialchars($senderName); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($remarks)): ?>
                    <tr>
                        <td>Notes:</td>
                        <td><?php echo htmlspecialchars($remarks); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (!empty($actionUrl)): ?>
            <p>
                <a href="<?php echo htmlspecialchars($actionUrl); ?>" class="button">Open Incoming Queue</a>
            </p>
            <p style="font-size:12px;color:#888;">Or copy this link: <?php echo htmlspecialchars($actionUrl); ?></p>
            <?php endif; ?>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Gov Press Enterprise Resource Planning System. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
