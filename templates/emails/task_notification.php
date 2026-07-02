<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #e8488a 0%, #d63a78 100%); color: white; padding: 20px; border-radius: 5px 5px 0 0; }
        .content { border: 1px solid #ddd; padding: 20px; border-radius: 0 0 5px 5px; background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .button { display: inline-block; padding: 12px 24px; background: #e8488a; color: white !important; text-decoration: none; border-radius: 5px; margin-top: 15px; font-weight: bold; }
        .task-details { background: white; border: 1px solid #e0e0e0; border-radius: 4px; padding: 15px; margin: 15px 0; }
        .task-details table { width: 100%; border-collapse: collapse; }
        .task-details td { padding: 6px 0; vertical-align: top; }
        .task-details td:first-child { font-weight: bold; width: 120px; color: #555; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Task Assignment</h1>
            <p>Gov Press ERP System</p>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($userName); ?>,</p>

            <p>
                <?php if (!empty($assigner)): ?>
                    <strong><?php echo htmlspecialchars($assigner); ?></strong> has assigned a task to you.
                <?php else: ?>
                    You have been assigned a new task.
                <?php endif; ?>
            </p>

            <div class="task-details">
                <table>
                    <tr>
                        <td>Task:</td>
                        <td><?php echo htmlspecialchars($taskTitle ?? $title); ?></td>
                    </tr>
                    <?php if (!empty($dueDate)): ?>
                    <tr>
                        <td>Due Date:</td>
                        <td><?php echo htmlspecialchars(date('d M Y', strtotime($dueDate))); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($priority)): ?>
                    <tr>
                        <td>Priority:</td>
                        <td><?php echo htmlspecialchars($priority); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($projectName)): ?>
                    <tr>
                        <td>Project:</td>
                        <td><?php echo htmlspecialchars($projectName); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (!empty($actionUrl)): ?>
            <p>
                <a href="<?php echo htmlspecialchars($actionUrl); ?>" class="button">View Task Now</a>
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
