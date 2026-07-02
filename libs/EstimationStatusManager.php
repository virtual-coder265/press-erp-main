<?php
/**
 * EstimationStatusManager
 * 
 * Manages estimation status transitions, validation, and history tracking
 * with support for workflow rules and audit trails.
 */
class EstimationStatusManager
{
    // Define valid statuses
    const STATUS_DRAFT = 'Draft';
    const STATUS_PERFORMER_INVOICED = 'Performer Invoiced';
    const STATUS_APPROVED = 'Approved';
    const STATUS_INVOICED = 'Invoiced';

    // Define allowed transitions (from_status => [to_statuses])
    private static $allowed_transitions = [
        self::STATUS_DRAFT => [self::STATUS_PERFORMER_INVOICED, self::STATUS_APPROVED],
        self::STATUS_PERFORMER_INVOICED => [self::STATUS_APPROVED, self::STATUS_INVOICED],
        self::STATUS_APPROVED => [self::STATUS_INVOICED, self::STATUS_PERFORMER_INVOICED],
        self::STATUS_INVOICED => [self::STATUS_DRAFT] // Can revert to draft if needed
    ];

    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all valid statuses
     */
    public static function getAllStatuses()
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PERFORMER_INVOICED,
            self::STATUS_APPROVED,
            self::STATUS_INVOICED
        ];
    }

    /**
     * Get status details with display info
     */
    public static function getStatusDetails($status)
    {
        $details = [
            self::STATUS_DRAFT => [
                'label' => 'Draft',
                'description' => 'Not yet submitted',
                'color' => 'yellow',
                'bg_class' => 'bg-yellow-100',
                'text_class' => 'text-yellow-800',
                'icon' => 'pencil'
            ],
            self::STATUS_PERFORMER_INVOICED => [
                'label' => 'Performer Invoiced',
                'description' => 'Invoiced to customer but not yet approved',
                'color' => 'blue',
                'bg_class' => 'bg-blue-100',
                'text_class' => 'text-blue-800',
                'icon' => 'clipboard-list'
            ],
            self::STATUS_APPROVED => [
                'label' => 'Approved',
                'description' => 'Approved by customer or costing department',
                'color' => 'green',
                'bg_class' => 'bg-green-100',
                'text_class' => 'text-green-800',
                'icon' => 'circle-check'
            ],
            self::STATUS_INVOICED => [
                'label' => 'Invoiced',
                'description' => 'Official invoice sent to customer for payment',
                'color' => 'purple',
                'bg_class' => 'bg-purple-100',
                'text_class' => 'text-purple-800',
                'icon' => 'receipt'
            ]
        ];

        return $details[$status] ?? null;
    }

    /**
     * Get allowed transitions for a given status
     */
    public static function getAllowedTransitions($current_status)
    {
        return self::$allowed_transitions[$current_status] ?? [];
    }

    /**
     * Check if a status transition is allowed
     */
    public static function isTransitionAllowed($from_status, $to_status)
    {
        if ($from_status === $to_status) {
            return false; // No change
        }
        $allowed = self::$allowed_transitions[$from_status] ?? [];
        return in_array($to_status, $allowed);
    }

    /**
     * Change estimation status with validation and history tracking
     * 
     * @param int $estimation_id
     * @param string $new_status
     * @param int $changed_by User ID
     * @param string $reason Optional reason for the change
     * @return array ['success' => bool, 'message' => string]
     */
    public function changeStatus($estimation_id, $new_status, $changed_by, $reason = '')
    {
        try {
            // Validate status is valid
            if (!in_array($new_status, self::getAllStatuses())) {
                return ['success' => false, 'message' => 'Invalid status: ' . htmlspecialchars($new_status)];
            }

            // Get current estimation
            $stmt = $this->pdo->prepare("SELECT id, status FROM estimations WHERE id = :id");
            $stmt->execute(['id' => $estimation_id]);
            $estimation = $stmt->fetch();

            if (!$estimation) {
                return ['success' => false, 'message' => 'Estimation not found'];
            }

            $current_status = $estimation['status'];

            // Check if transition is allowed
            if (!self::isTransitionAllowed($current_status, $new_status)) {
                $allowed = self::getAllowedTransitions($current_status);
                $allowed_str = implode(', ', $allowed) ?: 'none';
                return [
                    'success' => false,
                    'message' => "Cannot change from '{$current_status}' to '{$new_status}'. Allowed transitions: {$allowed_str}"
                ];
            }

            $this->pdo->beginTransaction();

            // Update estimation status
            $updateStmt = $this->pdo->prepare("
                UPDATE estimations 
                SET status = :new_status, updated_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([
                'new_status' => $new_status,
                'id' => $estimation_id
            ]);

            // Record in history
            $historyStmt = $this->pdo->prepare("
                INSERT INTO estimation_status_history 
                (estimation_id, old_status, new_status, changed_by, change_reason)
                VALUES (:est_id, :old_status, :new_status, :changed_by, :reason)
            ");
            $historyStmt->execute([
                'est_id' => $estimation_id,
                'old_status' => $current_status,
                'new_status' => $new_status,
                'changed_by' => $changed_by,
                'reason' => $reason
            ]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Status updated from '{$current_status}' to '{$new_status}' successfully"
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get status history for an estimation
     */
    public function getStatusHistory($estimation_id)
    {
        $stmt = $this->pdo->prepare("
            SELECT esh.*, u.name as changed_by_name
            FROM estimation_status_history esh
            LEFT JOIN users u ON esh.changed_by = u.id
            WHERE esh.estimation_id = :id
            ORDER BY esh.changed_at DESC
        ");
        $stmt->execute(['id' => $estimation_id]);
        return $stmt->fetchAll();
    }

    /**
     * Get estimation statistics by status
     */
    public function getStatisticsByStatus()
    {
        $stmt = $this->pdo->query("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(total_amount) as total_amount
            FROM estimations
            GROUP BY status
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get estimations by status
     */
    public function getEstimationsByStatus($status, $limit = null, $offset = 0)
    {
        $query = "
            SELECT e.*, u.name as created_by_name
            FROM estimations e
            LEFT JOIN users u ON e.created_by = u.id
            WHERE e.status = :status
            ORDER BY e.created_at DESC
        ";

        if ($limit) {
            $query .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute(['status' => $status]);
        if ($limit) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute(['status' => $status]);
        return $stmt->fetchAll();
    }

    /**
     * Bulk change status for multiple estimations
     * (Use with caution - logs each change)
     */
    public function bulkChangeStatus($estimation_ids, $new_status, $changed_by, $reason = '')
    {
        if (!is_array($estimation_ids)) {
            $estimation_ids = [$estimation_ids];
        }

        $successful = 0;
        $failed = 0;
        $errors = [];

        foreach ($estimation_ids as $est_id) {
            $result = $this->changeStatus($est_id, $new_status, $changed_by, $reason);
            if ($result['success']) {
                $successful++;
            } else {
                $failed++;
                $errors[] = "ID {$est_id}: " . $result['message'];
            }
        }

        return [
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors,
            'message' => "Changed {$successful} estimations. {$failed} failed."
        ];
    }

    /**
     * Create status badge HTML for display
     */
    public static function getStatusBadgeHtml($status)
    {
        $details = self::getStatusDetails($status);
        if (!$details) {
            return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Unknown</span>';
        }

        return sprintf(
            '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full %s %s" title="%s">%s</span>',
            $details['bg_class'],
            $details['text_class'],
            htmlspecialchars($details['description']),
            htmlspecialchars($details['label'])
        );
    }
}
