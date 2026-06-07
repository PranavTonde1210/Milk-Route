<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$adminId = Auth::requireAdmin();
$action  = $_GET['action'] ?? '';
$data    = json_decode(file_get_contents('php://input'), true) ?? [];
$method  = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── Today's delivery board ────────────────────────────────
    case 'today':
        $date   = $_GET['date'] ?? today();
        $status = $_GET['status'] ?? '';
        $search = sanitize($_GET['search'] ?? '');
        $dm     = new DeliveryModel();
        Response::success([
            'deliveries' => $dm->getByDate($date, $status, $search),
            'summary'    => $dm->getDaySummary($date),
            'date'       => $date,
        ]);
        break;

    // ── Generate delivery rows for a date (all active customers) ─
    case 'generate':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $date = $data['date'] ?? today();
        $db   = DB::getInstance();
        $customers = $db->fetchAll(
            'SELECT id FROM customers WHERE is_active = 1 AND email_verified = 1'
        );
        $dm    = new DeliveryModel();
        $count = 0;
        foreach ($customers as $c) {
            $dm->generateForDate($c['id'], $date);
            $count++;
        }
        Response::success(['generated_for' => $count], "Delivery rows created for $date.");
        break;

    // ── Mark single delivery ──────────────────────────────────
    case 'mark':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $deliveryId = (int)($data['delivery_id'] ?? 0);
        $status     = $data['status'] ?? 'delivered';
        $qty        = (float)($data['qty_delivered'] ?? 0);
        if (!$deliveryId) Response::error('delivery_id required.');
        if (!in_array($status, ['delivered', 'not_delivered', 'skipped'])) {
            Response::error('Invalid status.');
        }
        $dm = new DeliveryModel();
        $ok = $dm->markDelivery($deliveryId, $status, $qty, $adminId);

        // Sync payment for this customer's current month
        $db = DB::getInstance();
        $delivery = $db->fetchOne('SELECT customer_id, delivery_date FROM daily_deliveries WHERE id = ?', [$deliveryId]);
        if ($delivery) {
            $month = (int)date('n', strtotime($delivery['delivery_date']));
            $year  = (int)date('Y', strtotime($delivery['delivery_date']));
            (new PaymentModel())->syncTotal($delivery['customer_id'], $month, $year);
        }

        $ok ? Response::success([], 'Delivery updated.') : Response::error('Delivery not found.', 404);
        break;

    // ── Bulk mark all pending as delivered for a date ─────────
    case 'bulk-mark':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $date       = $data['date'] ?? today();
        $customerId = (int)($data['customer_id'] ?? 0) ?: null;
        $dm         = new DeliveryModel();
        $dm->bulkMarkDelivered($date, $customerId);

        // Sync payments for affected customers
        $db = DB::getInstance();
        $affectedCustomers = $db->fetchAll(
            "SELECT DISTINCT customer_id FROM daily_deliveries
             WHERE delivery_date = ? AND status = 'delivered'", [$date]
        );
        $pm    = new PaymentModel();
        $month = (int)date('n', strtotime($date));
        $year  = (int)date('Y', strtotime($date));
        foreach ($affectedCustomers as $c) {
            $pm->syncTotal($c['customer_id'], $month, $year);
        }

        Response::success(['date' => $date], 'All pending deliveries marked as delivered.');
        break;

    // ── Update delivery qty (admin adjustment) ────────────────
    case 'update-qty':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $deliveryId = (int)($data['delivery_id'] ?? 0);
        $qty        = (float)($data['qty'] ?? 0);
        if (!$deliveryId) Response::error('delivery_id required.');
        $db = DB::getInstance();
        $db->run(
            'UPDATE daily_deliveries SET qty_ordered = ? WHERE id = ?', [$qty, $deliveryId]
        );
        Response::success([], 'Quantity updated.');
        break;

    // ── Skip a customer for date range (admin) ────────────────
    case 'skip':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $customerId = (int)($data['customer_id'] ?? 0);
        $dateStart  = $data['date_start'] ?? today();
        $dateEnd    = $data['date_end'] ?? today();
        if (!$customerId) Response::error('customer_id required.');
        $db = DB::getInstance();
        $db->run(
            'INSERT INTO skip_requests (customer_id, skip_date_start, skip_date_end, reason)
             VALUES (?, ?, ?, ?)',
            [$customerId, $dateStart, $dateEnd, 'admin_set']
        );
        $db->run(
            "UPDATE daily_deliveries SET status = 'skipped', skip_reason = 'admin_set'
             WHERE customer_id = ? AND delivery_date BETWEEN ? AND ? AND status = 'pending'",
            [$customerId, $dateStart, $dateEnd]
        );
        Response::success([], 'Skip set for customer.');
        break;

    // ── Delivery stats for dashboard ──────────────────────────
    case 'stats':
        $date = $_GET['date'] ?? today();
        $dm   = new DeliveryModel();
        $db   = DB::getInstance();

        // Weekly trend (last 7 days)
        $weekTrend = $db->fetchAll(
            "SELECT delivery_date, SUM(qty_delivered) as total_litres,
             COUNT(DISTINCT customer_id) as customers
             FROM daily_deliveries
             WHERE delivery_date >= DATE_SUB(?, INTERVAL 6 DAY) AND status = 'delivered'
             GROUP BY delivery_date ORDER BY delivery_date",
            [$date]
        );

        Response::success([
            'today'      => $dm->getDaySummary($date),
            'week_trend' => $weekTrend,
        ]);
        break;

    default:
        Response::error('Unknown action.', 404);
}
