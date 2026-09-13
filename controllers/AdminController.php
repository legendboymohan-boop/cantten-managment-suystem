<?php
require_once __DIR__ . '/../models/MenuItem.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Table.php';
require_once __DIR__ . '/../models/Promo.php';
require_once __DIR__ . '/../models/InventoryLog.php';

class AdminController {
    private $menuItemModel;
    private $orderModel;
    private $userModel;
    private $reservationModel;
    private $promoModel;
    private $inventoryLogModel;

    public function __construct($db) {
        $this->menuItemModel = new MenuItem($db);
        $this->orderModel = new Order($db);
        $this->userModel = new User($db);
        $this->reservationModel = new Reservation($db);
        $this->promoModel = new Promo($db);
        $this->inventoryLogModel = new InventoryLog($db);
    }

    public function dashboardData() {
        requireAdmin();
        return [
            'menu_counts' => $this->menuItemModel->counts(),
            'order_stats' => $this->orderModel->todayStats(),
            'customers' => $this->userModel->countActiveCustomers(),
            'recent_orders' => $this->orderModel->recent(5),
            'low_stock' => $this->menuItemModel->lowStock(),
            'vendor_reorders' => $this->menuItemModel->reorderQueue(),
            'inventory_logs' => $this->inventoryLogModel->recent(10),
            'reservations' => $this->reservationModel->all(),
            'promo_claims' => $this->promoModel->all(),
            'most_ordered_today' => $this->orderModel->mostOrdered('today'),
            'most_ordered_week' => $this->orderModel->mostOrdered('week'),
        ];
    }

    public function reportData($days = 30) {
        requireAdmin();
        return $this->orderModel->reportSummary($days);
    }

    public function autoReorder($id) {
        requireAdmin();
        $item = $this->menuItemModel->find($id);
        if (!$item) {
            return false;
        }

        $previousStock = (int)($item['current_stock'] ?? 0);
        $qty = $this->menuItemModel->recommendedRestockQty($previousStock, (int)($item['reorder_level'] ?? 0));
        $result = $this->menuItemModel->autoReorder($id, $qty);

        if ($result) {
            $this->inventoryLogModel->add($id, 'vendor_reorder', $qty, $previousStock, $previousStock + $qty, 'Automated vendor reorder');
        }

        return $result;
    }

    public function updateReservationStatus($id, $status) {
        requireAdmin();
        return $this->reservationModel->updateStatus($id, $status);
    }
}
