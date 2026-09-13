<?php
require_once __DIR__ . '/../models/Table.php';

class ReservationController {
    private $tableModel;
    private $reservationModel;

    public function __construct($db) {
        $this->tableModel = new TableModel($db);
        $this->reservationModel = new Reservation($db);
    }

    public function availableTables($date, $time) {
        requireCustomer();
        $date = Validator::date($date);
        $time = Validator::time($time);
        if ($date === false || $time === false) {
            return [];
        }
        $all = $this->tableModel->all();
        $reservedIds = $this->reservationModel->reservedTableIds($date, $time);
        foreach ($all as &$t) {
            $t['reserved'] = in_array($t['id'], $reservedIds);
        }
        return $all;
    }

    public function book($userId, $tableId, $date, $time, $guests) {
        requireCustomer();
        $date = Validator::date($date);
        $time = Validator::time($time);
        $tableId = Validator::tableId($tableId);
        $guests = Validator::integer($guests, 1, 20);
        if ($date === false || $time === false || $tableId === false || $guests === false) {
            return false;
        }
        $end = date('H:i:s', strtotime($time . ' +1 hour'));
        return $this->reservationModel->create((int)$_SESSION['user_id'], $tableId, $date, $time, $end, $guests);
    }

    public function myReservations($userId) {
        requireCustomer();
        return $this->reservationModel->findByUser($userId);
    }

    public function updateStatus($id, $status) {
        requireAdmin();
        return $this->reservationModel->updateStatus($id, $status);
    }
}
