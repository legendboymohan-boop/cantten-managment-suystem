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
    private $conn;
    private $table = 'reservations';

    public function __construct($db) {
        $this->conn = $db;
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
                (user_id, table_id, reservation_date, start_time, end_time, guests, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$userId, $tableId, $date, $startTime, $endTime, $guests]);
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
