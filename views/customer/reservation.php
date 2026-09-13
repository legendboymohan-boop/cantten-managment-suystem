<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/ReservationController.php';
requireCustomer();

$database = new Database();
$db = $database->connect();
$reservationController = new ReservationController($db);

$date = Validator::date($_GET['date'] ?? date('Y-m-d')) ?: date('Y-m-d');
$time = Validator::time($_GET['time'] ?? '12:00') ?: '12:00:00';
$guests = Validator::integer($_GET['guests'] ?? 2, 1, 8) ?: 2;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $date = Validator::date($_POST['date'] ?? null);
    $time = Validator::time($_POST['time'] ?? null);
    $guests = Validator::integer($_POST['guests'] ?? null, 1, 8);
    $tableId = Validator::tableId($_POST['table_id'] ?? null);
    if (isset($_POST['confirm']) && $date !== false && $time !== false && $guests !== false && $tableId !== false) {
        if ($reservationController->book($_SESSION['user_id'], $tableId, $date, $time, $guests)) {
            flash('success', 'Table reserved successfully!');
            redirect('views/customer/profile.php');
        }
        $message = 'That table is unavailable, too small, or the reservation details are invalid.';
    }
}

$tables = $reservationController->availableTables($date, $time);
$myReservations = $reservationController->myReservations($_SESSION['user_id']);
$myConfirmedTableIds = [];
foreach ($myReservations as $reservation) {
    $selectedTime = strtotime($date . ' ' . $time . ':00');
    $reservationStart = strtotime($reservation['reservation_date'] . ' ' . $reservation['start_time']);
    $reservationEnd = strtotime($reservation['reservation_date'] . ' ' . $reservation['end_time']);
    if ($reservation['status'] === 'confirmed' && $selectedTime >= $reservationStart && $selectedTime < $reservationEnd) {
        $myConfirmedTableIds[] = (int)$reservation['table_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reservation - CanteenPro</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../partials/header_customer.php'; ?>

<main class="container">
    <div class="reservation-layout">
        <div class="card">
            <h2>Book a Table</h2>
            <p class="muted small">Select your details to view available tables.</p>
            <form method="GET" id="filter-form">
                <label>Date</label>
                <input type="date" name="date" value="<?= e($date) ?>" onchange="document.getElementById('filter-form').submit()">

                <label>Time</label>
                <input type="time" name="time" value="<?= e(substr($time, 0, 5)) ?>" onchange="document.getElementById('filter-form').submit()">

                <label>Guests</label>
                <select name="guests" onchange="document.getElementById('filter-form').submit()">
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <option value="<?= $i ?>" <?= $guests == $i ? 'selected' : '' ?>><?= $i ?> People</option>
                    <?php endfor; ?>
                </select>
            </form>

            <form method="POST" id="book-form">
                <?= csrfField() ?>
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <input type="hidden" name="time" value="<?= e($time) ?>">
                <input type="hidden" name="guests" value="<?= e($guests) ?>">
                <input type="hidden" name="table_id" id="selected-table-id" value="">
                <div class="row-between" style="margin-top:20px;">
                    <span class="muted">Selected Table</span>
                    <strong id="selected-table-label">None</strong>
                </div>
                <button type="submit" name="confirm" class="btn-primary btn-block" id="confirm-btn" disabled>Confirm Reservation</button>
            </form>

            <div class="my-reservations">
                <h3>My Reservations</h3>
                <?php if (empty($myReservations)): ?>
                    <p class="muted small">You have no table reservations yet.</p>
                <?php else: ?>
                    <?php foreach ($myReservations as $reservation): ?>
                        <div class="reservation-line">
                            <strong>Table <?= e($reservation['table_number']) ?> (<?= e($reservation['location']) ?>)</strong>
                            <div class="muted small">
                                <?= date('M d, Y', strtotime($reservation['reservation_date'])) ?>,
                                <?= date('g:i A', strtotime($reservation['start_time'])) ?> - <?= date('g:i A', strtotime($reservation['end_time'])) ?>
                            </div>
                            <span class="status-pill status-<?= e($reservation['status']) ?>">
                                <?= $reservation['status'] === 'confirmed' ? 'Reserved' : ucfirst($reservation['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="row-between">
                <h3>Floor Plan</h3>
                <div class="legend">
                    <span><i class="dot available"></i> Available</span>
                    <span><i class="dot reserved"></i> Reserved</span>
                    <span><i class="dot my-reserved-dot"></i> Reserved by you</span>
                </div>
            </div>
            <div class="floor-plan">
                <?php foreach ($tables as $t): ?>
                    <?php $isMyConfirmedTable = in_array((int)$t['id'], $myConfirmedTableIds, true); ?>
                    <div class="table-tile <?= $isMyConfirmedTable ? 'my-reserved' : ($t['reserved'] ? 'reserved' : 'available') ?>"
                         data-id="<?= $t['id'] ?>" data-label="Table <?= e($t['table_number']) ?> (<?= $t['capacity'] ?>)">
                        🪑<br><?= e($t['table_number']) ?> (<?= $t['capacity'] ?>)
                        <?php if ($isMyConfirmedTable): ?><small>Reserved by you</small><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>
<script>
document.querySelectorAll('.table-tile.available').forEach(tile => {
    tile.addEventListener('click', () => {
        document.querySelectorAll('.table-tile').forEach(t => t.classList.remove('selected'));
        tile.classList.add('selected');
        document.getElementById('selected-table-id').value = tile.dataset.id;
        document.getElementById('selected-table-label').innerText = tile.dataset.label;
        document.getElementById('confirm-btn').disabled = false;
    });
});
</script>
</body>
</html>
