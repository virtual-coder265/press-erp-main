<?php
/**
 * Project Documentation Template
 * 
 * @param array $project The project data array
 * @param array $tasks The tasks associated with the project
 * @param array $documentation Documentation entries for the tasks
 * @param array $business Business information array
 * @return string HTML output
 */

// Extract variables
$userName = $_SESSION['user_name'] ?? 'System User';
$generated_at = date('F j, Y, g:i A');

// Get business settings from database
require_once __DIR__ . '/../includes/settings_helper.php';
$settings = get_business_pdf_settings();

// Set default values if not set
$settings['business_name'] = $settings['business_name'] ?? 'Gov Press ERP';
$settings['business_logo'] = $settings['business_logo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Documentation - <?php echo htmlspecialchars($project['name']); ?></title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            line-height: 1.6;
            color: #1a202c;
            font-size: 11pt;
            margin: 0;
            padding: 0;
        }
        .container {
            padding: 30px;
        }
        .header {
            border-bottom: 2px solid #2d3748;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .logo {
            max-width: 140px;
            float: right;
        }
        .doc-title {
            font-size: 24pt;
            font-weight: bold;
            color: #2d3748;
            margin: 0;
        }
        .doc-meta {
            font-size: 10pt;
            color: #718096;
            margin-top: 5px;
        }
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 16pt;
            font-weight: bold;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .grid {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .grid-row {
            display: table-row;
        }
        .grid-cell {
            display: table-cell;
            padding: 5px 0;
            vertical-align: top;
        }
        .label {
            font-weight: bold;
            color: #4a5568;
            width: 150px;
        }
        .value {
            color: #1a202c;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-pending { background: #edf2f7; color: #4a5568; }
        .status-completed { background: #c6f6d5; color: #22543d; }
        .status-progress { background: #ebf8ff; color: #2b6cb0; }
        
        .description-box {
            background-color: #f7fafc;
            border-left: 4px solid #4a5568;
            padding: 15px;
            font-style: italic;
            color: #2d3748;
            margin-bottom: 20px;
        }
        
        .task-list {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .task-list th {
            background-color: #2d3748;
            color: white;
            text-align: left;
            padding: 10px;
            font-size: 10pt;
        }
        .task-list td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10pt;
            vertical-align: top;
        }
        
        .documentation-entry {
            margin-top: 10px;
            padding: 10px;
            background: #fffafa;
            border: 1px solid #fed7d7;
            border-radius: 6px;
            font-size: 9pt;
        }
        .entry-title {
            font-weight: bold;
            color: #9b2c2c;
            margin-bottom: 2px;
        }
        
        .footer {
            margin-top: 50px;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            font-size: 9pt;
            color: #a0aec0;
            text-align: center;
        }
        
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <?php if (!empty($settings['business_logo'])): ?>
                <img src="<?php echo $_SERVER['DOCUMENT_ROOT'] . $settings['business_logo']; ?>" class="logo">
            <?php endif; ?>
            <h1 class="doc-title">Project Documentation</h1>
            <div class="doc-meta">
                Ref: #PRJ-<?php echo str_pad($project['id'], 5, '0', STR_PAD_LEFT); ?> | 
                Generated on <?php echo $generated_at; ?> by <?php echo htmlspecialchars($userName); ?>
            </div>
        </div>

        <!-- Project Overview -->
        <section class="section">
            <h2 class="section-title">Overview</h2>
            <div class="grid">
                <div class="grid-row">
                    <div class="grid-cell label">Project Name</div>
                    <div class="grid-cell value"><strong><?php echo htmlspecialchars($project['name']); ?></strong></div>
                </div>
                <div class="grid-row">
                    <div class="grid-cell label">Status</div>
                    <div class="grid-cell value"><?php echo htmlspecialchars($project['status']); ?></div>
                </div>
                <div class="grid-row">
                    <div class="grid-cell label">Approval Status</div>
                    <div class="grid-cell value"><?php echo htmlspecialchars($project['approved_status']); ?></div>
                </div>
                <div class="grid-row">
                    <div class="grid-cell label">Timeline</div>
                    <div class="grid-cell value">
                        <?php 
                        $start = !empty($project['start_date']) ? date('M d, Y', strtotime($project['start_date'])) : 'N/A';
                        $end = !empty($project['end_date']) ? date('M d, Y', strtotime($project['end_date'])) : 'N/A';
                        echo "$start — $end";
                        ?>
                    </div>
                </div>
            </div>

            <div class="label" style="margin-bottom: 5px;">Project Brief:</div>
            <div class="description-box">
                <?php echo !empty($project['description']) ? nl2br(htmlspecialchars($project['description'])) : 'No description provided.'; ?>
            </div>
        </section>

        <!-- Task & Documentation Registry -->
        <section class="section">
            <h2 class="section-title">Task & Evidence Registry</h2>
            <table class="task-list">
                <thead>
                    <tr>
                        <th style="width: 25%;">Task Name</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 60%;">Evidence & Narrative Logs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 20px;">No tasks registered for this project.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($task['name']); ?></strong><br>
                                    <span style="font-size: 8pt; color: #718096;">
                                        Assignee: <?php echo htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($task['status']); ?>
                                </td>
                                <td>
                                    <?php 
                                    $task_docs = array_filter($documentation, function($d) use ($task) {
                                        return (int)$d['task_id'] === (int)$task['id'];
                                    });
                                    
                                    if (empty($task_docs)):
                                        echo '<span style="color: #a0aec0; font-style: italic;">No specific evidence logs recorded.</span>';
                                    else:
                                        foreach ($task_docs as $entry):
                                    ?>
                                        <div class="documentation-entry">
                                            <div class="entry-title">
                                                Log Entry: <?php echo date('M d, Y H:i', strtotime($entry['created_at'])); ?>
                                            </div>
                                            <div style="font-size: 8pt; margin-bottom: 4px; border-bottom: 1px dashed #eee; padding-bottom: 2px;">
                                                Status Transition: <?php echo htmlspecialchars($entry['old_status'] ?: 'Initial'); ?> &rarr; <?php echo htmlspecialchars($entry['new_status']); ?>
                                                <?php if($entry['uploader_name']): ?>
                                                    | Action by: <?php echo htmlspecialchars($entry['uploader_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($entry['documentation_text'])): ?>
                                                <div style="margin-top: 5px;"><?php echo nl2br(htmlspecialchars($entry['documentation_text'])); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['document_path'])): ?>
                                                <div style="margin-top: 5px; color: #2b6cb0; font-weight: bold; font-size: 8pt;">
                                                    [Attached Evidence File: <?php echo basename($entry['document_path']); ?>]
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php 
                                        endforeach;
                                    endif; 
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <!-- Footer -->
        <div class="footer">
            Final Documentation Report | Generated by <?php echo htmlspecialchars($settings['business_name']); ?> ERP System
            <br>&copy; <?php echo date('Y'); ?> All Rights Reserved.
        </div>
    </div>
</body>
</html>
