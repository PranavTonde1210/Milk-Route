<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$adminId = Auth::requireAdmin();
$action  = $_GET['action'] ?? '';
$data    = json_decode(file_get_contents('php://input'), true) ?? [];
$method  = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── All outstanding payments ──────────────────────────────
    case 'outstanding':
        $pm = new PaymentModel();
        Response::success($pm->getAllOutstanding());
        break;

    // ── Month summary ─────────────────────────────────────────
    case 'month-summary':
        $month = (int)($_GET['month'] ?? currentMonth());
        $year  = (int)($_GET['year']  ?? currentYear());
        $pm    = new PaymentModel();
        $db    = DB::getInstance();

        $summary = $pm->getMonthSummary($month, $year);
        $topCustomers = $db->fetchAll(
            'SELECT c.name, c.wing, c.flat_number, p.total_amount, p.paid_amount,
             p.balance, p.status
             FROM payments p JOIN customers c ON c.id = p.customer_id
             WHERE p.month = ? AND p.year = ?
             ORDER BY p.balance DESC LIMIT 10',
            [$month, $year]
        );

        Response::success([
            'summary'       => $summary,
            'top_debtors'   => $topCustomers,
            'month'         => $month,
            'year'          => $year,
        ]);
        break;

    // ── Record payment ────────────────────────────────────────
    case 'record':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $required = ['customer_id', 'month', 'year', 'amount', 'method'];
        foreach ($required as $f) {
            if (empty($data[$f])) Response::error("Field '$f' is required.");
        }
        $validMethods = ['cash','upi','bank_transfer','other'];
        if (!in_array($data['method'], $validMethods)) {
            Response::error('Invalid payment method.');
        }
        $pm      = new PaymentModel();
        $payment = $pm->recordPayment(
            (int)$data['customer_id'],
            (int)$data['month'],
            (int)$data['year'],
            (float)$data['amount'],
            $data['method'],
            sanitize($data['ref'] ?? ''),
            $adminId
        );
        Response::success($payment, 'Payment recorded successfully.');
        break;

    // ── Get payment detail for customer + month ───────────────
    case 'detail':
        $customerId = (int)($_GET['customer_id'] ?? 0);
        $month      = (int)($_GET['month'] ?? currentMonth());
        $year       = (int)($_GET['year']  ?? currentYear());
        if (!$customerId) Response::error('customer_id required.');
        $pm      = new PaymentModel();
        $payment = $pm->getOrCreate($customerId, $month, $year);
        $calc    = $pm->calculateMonthTotal($customerId, $month, $year);
        $history = $pm->getHistory($customerId, 6);
        $cm      = new CustomerModel();
        $customer = $cm->findById($customerId);
        Response::success([
            'customer' => ['name'=>$customer['name'],'wing'=>$customer['wing'],'flat'=>$customer['flat_number']],
            'payment'  => $payment,
            'breakdown'=> $calc,
            'history'  => $history,
        ]);
        break;

    // ── Sync all payment totals for a month (after price changes) ─
    case 'sync-month':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $month = (int)($data['month'] ?? currentMonth());
        $year  = (int)($data['year']  ?? currentYear());
        $db    = DB::getInstance();
        $pm    = new PaymentModel();
        $customers = $db->fetchAll('SELECT id FROM customers WHERE is_active = 1');
        foreach ($customers as $c) {
            $pm->syncTotal($c['id'], $month, $year);
        }
        Response::success(['count' => count($customers)], 'All payments synced.');
        break;

    // ── Revenue report (last 6 months) ───────────────────────
    case 'revenue-report':
        $db = DB::getInstance();
        $months = $db->fetchAll(
            "SELECT month, year,
             SUM(total_amount) as billed, SUM(paid_amount) as collected,
             SUM(balance) as outstanding,
             COUNT(DISTINCT customer_id) as customers
             FROM payments
             WHERE (year * 100 + month) >= ((YEAR(CURDATE()) * 100 + MONTH(CURDATE())) - 6)
             GROUP BY year, month ORDER BY year DESC, month DESC LIMIT 6"
        );
        Response::success($months);
        break;

    default:
        Response::error('Unknown action.', 404);
}
