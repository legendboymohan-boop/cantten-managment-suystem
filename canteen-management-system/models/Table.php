<?php
class TableModel {
    private $conn;
    private $table = 'tables_';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function all() {
        $stmt = $this->conn->prepare("SELECT * FROM tables_ WHERE is_active = 1 ORDER BY table_number");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class Reservation {
    const DEPOSIT_AMOUNT = 5.00;
    const FREE_CANCEL_HOURS = 2;

    private $conn;
    private $table = 'reservations';

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    private function ensureSchema() {
        $columns = [
            'deposit_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 5.00",
            'payment_method' => "ENUM('esewa','khalti','cash') NULL",
            'payment_status' => "ENUM('unpaid','paid','refunded','forfeited') NOT NULL DEFAULT 'unpaid'",
            'transaction_ref' => 'VARCHAR(100) NULL',
            'cancelled_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $column => $definition) {
            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $stmt->execute([$this->table, $column]);
            if (!(int)$stmt->fetchColumn()) {
                $this->conn->exec("ALTER TABLE {$this->table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    public function create($userId, $tableId, $date, $start, $end, $guests) {
        $dateValue = DateTime::createFromFormat('!Y-m-d', $date);
        $startValue = DateTime::createFromFormat('!H:i:s', $start) ?: DateTime::createFromFormat('!H:i', $start);
        $endValue = DateTime::createFromFormat('!H:i:s', $end) ?: DateTime::createFromFormat('!H:i', $end);
        if (!$dateValue || !$startValue || !$endValue || $dateValue->format('Y-m-d') !== $date || $endValue <= $startValue || (int)$guests < 1 || $dateValue < new DateTime('today')) {
            return false;
        }

        $startTime = $startValue->format('H:i:s');
        $endTime = $endValue->format('H:i:s');
        $guests = (int)$guests;
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT capacity FROM tables_ WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt->execute([$tableId]);
            $capacity = $stmt->fetchColumn();
            if ($capacity === false || $guests > (int)$capacity) {
                $this->conn->rollBack();
                return false;
            }

            $stmt = $this->conn->prepare("SELECT id FROM {$this->table}
                WHERE table_id = ? AND reservation_date = ? AND status IN ('pending', 'confirmed')
                AND start_time < ? AND end_time > ? LIMIT 1");
            $stmt->execute([$tableId, $date, $endTime, $startTime]);
            if ($stmt->fetchColumn()) {
                $this->conn->rollBack();
                return false;
            }

            $stmt = $this->conn->prepare("INSERT INTO {$this->table}
                (user_id, table_id, reservation_date, start_time, end_time, guests, deposit_amount, payment_status, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'unpaid', 'pending')");
            $stmt->execute([$userId, $tableId, $date, $startTime, $endTime, $guests, self::DEPOSIT_AMOUNT]);
            $reservationId = $this->conn->lastInsertId();
            $this->conn->commit();
            return $reservationId;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function findByUser($userId) {
        $stmt = $this->conn->prepare("SELECT r.*, t.table_number, t.location FROM {$this->table} r
            JOIN tables_ t ON r.table_id = t.id WHERE r.user_id = ? ORDER BY r.reservation_date DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOwnedById($id, $userId) {
        $stmt = $this->conn->prepare("SELECT r.*, t.table_number, t.capacity, t.location
            FROM {$this->table} r JOIN tables_ t ON r.table_id = t.id
            WHERE r.id = ? AND r.user_id = ? LIMIT 1");
        $stmt->execute([(int)$id, (int)$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function payDeposit($reservationId, $userId, $method) {
        if (!in_array($method, ['esewa', 'khalti', 'cash'], true)) {
            return false;
        }
        $reservation = $this->findOwnedById($reservationId, $userId);
        if (!$reservation || $reservation['status'] === 'cancelled' || $reservation['status'] === 'completed' || $reservation['payment_status'] !== 'unpaid') {
            return false;
        }
        if ($method === 'cash') {
            $stmt = $this->conn->prepare("UPDATE {$this->table} SET payment_method = 'cash' WHERE id = ? AND user_id = ? AND payment_status = 'unpaid'");
            return $stmt->execute([(int)$reservationId, (int)$userId]) ? 'pending_counter' : false;
        }
        $transactionRef = strtoupper($method) . '-' . bin2hex(random_bytes(12));
        $stmt = $this->conn->prepare("UPDATE {$this->table}
            SET payment_method = ?, payment_status = 'paid', transaction_ref = ?, status = 'confirmed'
            WHERE id = ? AND user_id = ? AND payment_status = 'unpaid'");
        return $stmt->execute([$method, $transactionRef, (int)$reservationId, (int)$userId]) && $stmt->rowCount() ? 'paid' : false;
    }

    public function cancel($reservationId, $userId) {
        $reservation = $this->findOwnedById($reservationId, $userId);
        if (!$reservation || in_array($reservation['status'], ['cancelled', 'completed'], true)) {
            return false;
        }
        $start = new DateTime($reservation['reservation_date'] . ' ' . $reservation['start_time']);
        $hoursUntil = ($start->getTimestamp() - time()) / 3600;
        $outcome = 'no_charge';
        if ($reservation['payment_status'] === 'paid') {
            $outcome = $hoursUntil < self::FREE_CANCEL_HOURS ? 'forfeited' : 'refunded';
        }
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = 'cancelled', cancelled_at = NOW(), payment_status = CASE WHEN payment_status = 'paid' THEN ? ELSE payment_status END WHERE id = ? AND user_id = ? AND status NOT IN ('cancelled', 'completed')");
        return $stmt->execute([$outcome, (int)$reservationId, (int)$userId]) && $stmt->rowCount() ? $outcome : false;
    }

    public function adminCancel($reservationId, $paymentOutcome) {
        if (!in_array($paymentOutcome, ['refunded', 'forfeited', 'unpaid'], true)) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = 'cancelled', cancelled_at = NOW(), payment_status = CASE WHEN payment_status = 'paid' THEN ? ELSE payment_status END WHERE id = ? AND status NOT IN ('cancelled', 'completed')");
        return $stmt->execute([$paymentOutcome, (int)$reservationId]) && $stmt->rowCount();
    }

    public function adminUpdate($reservationId, $tableId, $date, $start, $end, $guests, $status) {
        $date = Validator::date($date);
        $start = Validator::time($start);
        $end = Validator::time($end);
        $tableId = Validator::tableId($tableId);
        $guests = Validator::integer($guests, 1, 20);
        if ($date === false || $start === false || $end === false || $tableId === false || $guests === false || !in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
            return false;
        }
        $startValue = new DateTime($date . ' ' . $start);
        $endValue = new DateTime($date . ' ' . $end);
        if ($endValue <= $startValue) {
            return false;
        }
        $tableStmt = $this->conn->prepare('SELECT capacity FROM tables_ WHERE id = ? AND is_active = 1 LIMIT 1');
        $tableStmt->execute([$tableId]);
        $capacity = $tableStmt->fetchColumn();
        if ($capacity === false || $guests > (int)$capacity) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET table_id = ?, reservation_date = ?, start_time = ?, end_time = ?, guests = ?, status = ? WHERE id = ?");
        return $stmt->execute([$tableId, $date, $start, $end, $guests, $status, (int)$reservationId]);
    }

    public function collectCashDeposit($reservationId) {
        $transactionRef = 'CASH-' . strtoupper(bin2hex(random_bytes(10)));
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET payment_status = 'paid', transaction_ref = ?, status = CASE WHEN status = 'pending' THEN 'confirmed' ELSE status END WHERE id = ? AND payment_method = 'cash' AND payment_status = 'unpaid' AND status != 'cancelled'");
        return $stmt->execute([$transactionRef, (int)$reservationId]) && $stmt->rowCount();
    }

    public function pendingCashDeposits() {
        $stmt = $this->conn->prepare("SELECT r.*, u.name AS customer_name, u.email AS customer_email, t.table_number, t.location
            FROM {$this->table} r JOIN users u ON r.user_id = u.id JOIN tables_ t ON r.table_id = t.id
            WHERE r.payment_method = 'cash' AND r.payment_status = 'unpaid' AND r.status != 'cancelled'
            ORDER BY r.reservation_date, r.start_time");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function paidDepositsSummary($from, $to) {
        $stmt = $this->conn->prepare("SELECT r.*, u.name AS customer_name, u.email AS customer_email, t.table_number, t.location
            FROM {$this->table} r JOIN users u ON r.user_id = u.id JOIN tables_ t ON r.table_id = t.id
            WHERE r.payment_status = 'paid' AND r.reservation_date BETWEEN ? AND ?
            ORDER BY r.reservation_date, r.start_time");
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = 0;
        foreach ($rows as $row) {
            $total += (float)$row['deposit_amount'];
        }
        return ['total' => $total, 'rows' => $rows];
    }

    public function all() {
        $stmt = $this->conn->prepare("SELECT r.*, u.name AS customer_name, u.email AS customer_email,
                t.table_number, t.location
                FROM {$this->table} r
                JOIN users u ON r.user_id = u.id
                JOIN tables_ t ON r.table_id = t.id
                ORDER BY CASE WHEN r.status = 'pending' THEN 0 WHEN r.status = 'confirmed' THEN 1 ELSE 2 END,
                    r.reservation_date ASC, r.start_time ASC");
            $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status) {
        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public function reservedTableIds($date, $time) {
        $stmt = $this->conn->prepare("SELECT table_id FROM {$this->table}
            WHERE reservation_date = ? AND status IN ('pending', 'confirmed')
            AND start_time < ADDTIME(?, '01:00:00') AND end_time > ?");
            $stmt->execute([$date, $time, $time]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
