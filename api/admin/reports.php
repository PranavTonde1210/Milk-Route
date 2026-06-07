<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$adminId = Auth::requireAdmin();
$action  = $_GET['action'] ?? '';

switch ($action) {

    // ── Main dashboard stats ──────────────────────────────────
    case 'dashboard':
        $am = new AdminModel();
        $dm = new DeliveryModel();
        $db = DB::getInstance();

        $stats = $am->getDashboardStats();

        // Recent 5 customers
        $recentCustomers = $db->fetchAll(
            'SELECT id, name, wing, flat_number, delivery_pattern, created_at
             FROM customers ORDER BY created_at DESC LIMIT 5'
        );

        // Today delivery summary
        $todaySummary = $dm->getDaySummary(today());

        // Pending payments (top 5)
        $pendingPayments = $db->fetchAll(
            "SELECT p.balance, p.status, p.month, p.year, c.name, c.wing, c.flat_number
             FROM payments p JOIN customers c ON c.id = p.customer_id
             WHERE p.status IN ('unpaid','partial') ORDER BY p.balance DESC LIMIT 5"
        );

        // Products with current price
        $products = (new ProductModel())->getAllWithCompany();

        Response::success([
            'stats'            => $stats,
            'today_summary'    => $todaySummary,
            'recent_customers' => $recentCustomers,
            'pending_payments' => $pendingPayments,
            'products'         => $products,
        ]);
        break;

    // ── Monthly analytics ─────────────────────────────────────
    case 'monthly':
        $month = (int)($_GET['month'] ?? currentMonth());
        $year  = (int)($_GET['year']  ?? currentYear());
        $db    = DB::getInstance();

        // Daily litre totals for the month
        $dailyLitres = $db->fetchAll(
            "SELECT delivery_date, SUM(qty_delivered) as litres,
             COUNT(DISTINCT customer_id) as customers_served
             FROM daily_deliveries
             WHERE MONTH(delivery_date) = ? AND YEAR(delivery_date) = ? AND status = 'delivered'
             GROUP BY delivery_date ORDER BY delivery_date",
            [$month, $year]
        );

        // Per-product totals
        $productTotals = $db->fetchAll(
            "SELECT mp.name as product_name, mc.name as company_name,
             SUM(dd.qty_delivered) as total_qty,
             SUM(dd.qty_delivered * dd.price_at_delivery) as revenue
             FROM daily_deliveries dd
             JOIN milk_products mp ON mp.id = dd.product_id
             JOIN milk_companies mc ON mc.id = mp.company_id
             WHERE MONTH(dd.delivery_date) = ? AND YEAR(dd.delivery_date) = ?
             AND dd.status = 'delivered'
             GROUP BY dd.product_id ORDER BY revenue DESC",
            [$month, $year]
        );

        // Skip stats
        $skipStats = $db->fetchOne(
            "SELECT COUNT(*) as total_skips, COUNT(DISTINCT customer_id) as customers_skipped
             FROM daily_deliveries
             WHERE MONTH(delivery_date) = ? AND YEAR(delivery_date) = ? AND status = 'skipped'",
            [$month, $year]
        );

        // Payment summary
        $pm = new PaymentModel();
        $paymentSummary = $pm->getMonthSummary($month, $year);

        Response::success([
            'daily_litres'    => $dailyLitres,
            'product_totals'  => $productTotals,
            'skip_stats'      => $skipStats,
            'payment_summary' => $paymentSummary,
            'month'           => $month,
            'year'            => $year,
        ]);
        break;

    // ── Wing-wise delivery summary ────────────────────────────
    case 'wing-summary':
        $date = $_GET['date'] ?? today();
        $db   = DB::getInstance();
        $data = $db->fetchAll(
            "SELECT c.wing,
             COUNT(DISTINCT c.id) as total_customers,
             SUM(dd.qty_delivered) as total_litres,
             SUM(dd.status = 'delivered') as delivered_count,
             SUM(dd.status = 'pending') as pending_count,
             SUM(dd.status = 'skipped') as skipped_count
             FROM customers c
             LEFT JOIN daily_deliveries dd ON dd.customer_id = c.id AND dd.delivery_date = ?
             WHERE c.is_active = 1
             GROUP BY c.wing ORDER BY c.wing",
            [$date]
        );
        Response::success($data);
        break;

    // ── Export: customer list CSV ─────────────────────────────
    case 'export-customers':
        $db   = DB::getInstance();
        $rows = $db->fetchAll(
            "SELECT name, email, mobile, wing, flat_number, delivery_pattern,
             is_active, email_verified, created_at
             FROM customers ORDER BY wing, flat_number"
        );
        // Return as JSON; front-end can convert to CSV
        Response::success($rows);
        break;

    // ── Export: monthly delivery report ──────────────────────
    case 'export-deliveries':
        $month = (int)($_GET['month'] ?? currentMonth());
        $year  = (int)($_GET['year']  ?? currentYear());
        $db    = DB::getInstance();
        $rows  = $db->fetchAll(
            "SELECT c.name, c.wing, c.flat_number, mp.name as product,
             dd.delivery_date, dd.qty_delivered, dd.price_at_delivery,
             dd.qty_delivered * dd.price_at_delivery as amount, dd.status
             FROM daily_deliveries dd
             JOIN customers c ON c.id = dd.customer_id
             JOIN milk_products mp ON mp.id = dd.product_id
             WHERE MONTH(dd.delivery_date) = ? AND YEAR(dd.delivery_date) = ?
             ORDER BY c.wing, c.flat_number, dd.delivery_date",
            [$month, $year]
        );
        Response::success($rows);
        break;

    default:
        Response::error('Unknown action.', 404);
}
