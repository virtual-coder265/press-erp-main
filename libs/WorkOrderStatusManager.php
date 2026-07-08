<?php

class WorkOrderStatusManager
{
    public const STATUS_DRAFT = 'Draft';
    public const STATUS_WAITING_PAYMENT = 'Waiting Payment';
    public const STATUS_READY = 'Ready for Production';
    public const STATUS_IN_PRODUCTION = 'In Production';
    public const STATUS_AWAITING_DISPATCH = 'Awaiting Dispatch';
    public const STATUS_DISPATCHED = 'Dispatched';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    private PDO $pdo;

    private static array $allowedTransitions = [
        self::STATUS_DRAFT => [self::STATUS_WAITING_PAYMENT, self::STATUS_READY, self::STATUS_CANCELLED],
        self::STATUS_WAITING_PAYMENT => [self::STATUS_READY, self::STATUS_CANCELLED],
        self::STATUS_READY => [self::STATUS_IN_PRODUCTION, self::STATUS_CANCELLED],
        self::STATUS_IN_PRODUCTION => [self::STATUS_AWAITING_DISPATCH, self::STATUS_CANCELLED],
        self::STATUS_AWAITING_DISPATCH => [self::STATUS_DISPATCHED, self::STATUS_CANCELLED],
        self::STATUS_DISPATCHED => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function getAllStatuses(): array
    {
        return array_keys(self::$allowedTransitions);
    }

    public static function isTransitionAllowed(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === $toStatus) {
            return true;
        }

        return in_array($toStatus, self::$allowedTransitions[$fromStatus] ?? [], true);
    }

    public function changeStatus(int $workOrderId, string $newStatus, int $userId, string $remarks = ''): array
    {
        if (!in_array($newStatus, self::getAllStatuses(), true)) {
            return ['success' => false, 'message' => 'Invalid work-order status.'];
        }

        $stmt = $this->pdo->prepare("SELECT id, status FROM work_orders WHERE id = ?");
        $stmt->execute([$workOrderId]);
        $workOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$workOrder) {
            return ['success' => false, 'message' => 'Work order not found.'];
        }

        $currentStatus = (string) $workOrder['status'];
        if (!self::isTransitionAllowed($currentStatus, $newStatus)) {
            return ['success' => false, 'message' => 'This work-order status transition is not allowed.'];
        }

        $timestampColumns = [
            self::STATUS_IN_PRODUCTION => 'production_started_at',
            self::STATUS_AWAITING_DISPATCH => 'dispatch_ready_at',
            self::STATUS_DISPATCHED => 'dispatched_at',
            self::STATUS_COMPLETED => 'completed_at',
            self::STATUS_CANCELLED => 'cancelled_at',
        ];

        $sets = ['status = :status', 'updated_by = :updated_by'];
        if (isset($timestampColumns[$newStatus])) {
            $sets[] = $timestampColumns[$newStatus] . ' = COALESCE(' . $timestampColumns[$newStatus] . ', NOW())';
        }

        $sql = 'UPDATE work_orders SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $update = $this->pdo->prepare($sql);
        $update->execute([
            'status' => $newStatus,
            'updated_by' => $userId,
            'id' => $workOrderId,
        ]);

        $movement = $this->pdo->prepare("
            INSERT INTO production_movements
                (work_order_id, movement_type, sender_user_id, remarks)
            VALUES
                (?, ?, ?, ?)
        ");
        $movement->execute([$workOrderId, 'status_change', $userId, trim($currentStatus . ' -> ' . $newStatus . ($remarks !== '' ? ' | ' . $remarks : ''))]);

        return ['success' => true, 'message' => 'Work-order status updated.'];
    }
}

