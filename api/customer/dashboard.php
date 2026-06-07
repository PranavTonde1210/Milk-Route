<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$customerId = Auth::requireCustomer();
$action     = $_GET['action'] ?? '';
$data       = json_decode(file_get_contents('php://input'), true) ?? [];
$method     = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── Home screen data ──────────────────────────────────────
    case 'home':
        $cm   = new CustomerModel();
        $dm   = new DeliveryModel();
        $sm   = new SubscriptionModel();
        $nm   = new NotificationModel();
        $pm   = new PaymentModel();

        $customer      = $cm->findById($customerId);
        $todayDelivery = $dm->getTodayForCustomer($customerId);
        $subs          = $sm->getForCustomer($customerId);
        $unread        = $nm->unreadCount($customerId);
        $payment       = $pm->getOrCreate($customerId, currentMonth(), currentYear());

        // Tomorrow's delivery info
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $tomorrowDeliveries = (new DB)->fetchAll(
            'SELECT dd.*, mp.name as product_name FROM daily_deliveries dd
             JOIN milk_products mp ON mp.id = dd.product_id
             WHERE dd.customer_id = ? AND dd.delivery_date = ?',
            [$customerId, $tomorrow]
        );

        Response::success([
            'customer'           => [
                'name'             => $customer['name'],
                'wing'             => $customer['wing'],
                'flat_number'      => $customer['flat_number'],
                'delivery_pattern' => $customer['delivery_pattern'],
            ],
            'today_deliveries'   => $todayDelivery,
            'tomorrow_deliveries'=> $tomorrowDeliveries,
            'subscriptions'      => $subs,
            'unread_notifications'=> $unread,
            'balance_due'        => $payment['balance'] ?? 0,
            'cutoff_passed'      => isDeliveryCutoffPassed(),
            'today'              => today(),
            'tomorrow'           => $tomorrow,
        ]);
        break;

    // ── Update today's qty (before cutoff) ───────────────────
    case 'update-qty':
        if ($method !== 'POST') Response::error('POST required.', 405);
        if (empty($data['product_id']) || !isset($data['qty'])) {
            Response::error('product_id and qty required.');
        }
        if (isDeliveryCutoffPassed()) {
            Response::error('Cutoff time passed. Changes will apply from tomorrow.', 422);
        }
        $dm = new DeliveryModel();
        $ok = $dm->updateTodayQty($customerId, (int)$data['product_id'], (float)$data['qty']);
        $ok ? Response::success([], 'Quantity updated.') : Response::error('Could not update quantity.');
        break;

    // ── Skip today / date range ───────────────────────────────
    case 'skip':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $type      = $data['type'] ?? 'today'; // 'today' or 'range'
        $dateStart = $type === 'today' ? today() : ($data['date_start'] ?? today());
        $dateEnd   = $type === 'today' ? today() : ($data['date_end'] ?? today());

        if ($dateStart > $dateEnd) Response::error('End date must be after start date.');

        $db = DB::getInstance();
        // Insert skip request
        $db->run(
            'INSERT INTO skip_requests (customer_id, skip_date_start, skip_date_end, reason)
             VALUES (?, ?, ?, ?)',
            [$customerId, $dateStart, $dateEnd, 'customer_request']
        );
        // Update pending deliveries in that range to skipped
        $db->run(
            "UPDATE daily_deliveries SET status = 'skipped', skip_reason = 'customer_request'
             WHERE customer_id = ? AND delivery_date BETWEEN ? AND ? AND status = 'pending'",
            [$customerId, $dateStart, $dateEnd]
        );

        // Sync payment
        (new PaymentModel())->syncTotal($customerId, currentMonth(), currentYear());

        Response::success([], 'Skip request saved.');
        break;

    // ── Cancel skip ───────────────────────────────────────────
    case 'cancel-skip':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $date = $data['date'] ?? today();
        $db   = DB::getInstance();
        $db->run(
            'DELETE FROM skip_requests WHERE customer_id = ? AND skip_date_start <= ? AND skip_date_end >= ?',
            [$customerId, $date, $date]
        );
        // Restore skipped deliveries back to pending (only future ones)
        if ($date >= today()) {
            $db->run(
                "UPDATE daily_deliveries SET status = 'pending', skip_reason = NULL
                 WHERE customer_id = ? AND delivery_date = ? AND skip_reason = 'customer_request'",
                [$customerId, $date]
            );
        }
        Response::success([], 'Skip cancelled.');
        break;

    // ── Report not delivered ──────────────────────────────────
    case 'report-not-delivered':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $date = $data['date'] ?? today();
        $dm   = new DeliveryModel();
        $dm->reportNotDelivered($customerId, $date);
        Response::success([], 'Issue reported. Your distributor has been notified.');
        break;

    // ── Get subscriptions ─────────────────────────────────────
    case 'subscriptions':
        $sm = new SubscriptionModel();
        Response::success($sm->getForCustomer($customerId));
        break;

    // ── Add / update subscription ─────────────────────────────
    case 'subscription-save':
        if ($method !== 'POST') Response::error('POST required.', 405);
        if (empty($data['product_id']) || empty($data['qty'])) {
            Response::error('product_id and qty required.');
        }
        $sm = new SubscriptionModel();
        $sm->upsert($customerId, (int)$data['product_id'], (float)$data['qty']);
        Response::success([], 'Subscription updated.');
        break;

    // ── Remove subscription ───────────────────────────────────
    case 'subscription-remove':
        if ($method !== 'POST') Response::error('POST required.', 405);
        if (empty($data['product_id'])) Response::error('product_id required.');
        $sm = new SubscriptionModel();
        $sm->remove($customerId, (int)$data['product_id']);
        Response::success([], 'Milk removed from subscription.');
        break;

    // ── Monthly calendar ──────────────────────────────────────
    case 'calendar':
        $month = (int)($_GET['month'] ?? currentMonth());
        $year  = (int)($_GET['year']  ?? currentYear());
        $dm    = new DeliveryModel();
        Response::success($dm->getMonthCalendar($customerId, $month, $year));
        break;

    // ── Payment info ──────────────────────────────────────────
    case 'payment':
        $month   = (int)($_GET['month'] ?? currentMonth());
        $year    = (int)($_GET['year']  ?? currentYear());
        $pm      = new PaymentModel();
        $payment = $pm->getOrCreate($customerId, $month, $year);
        $calc    = $pm->calculateMonthTotal($customerId, $month, $year);
        $history = $pm->getHistory($customerId, 6);
        Response::success([
            'payment'  => $payment,
            'breakdown'=> $calc,
            'history'  => $history,
        ]);
        break;

    // ── Notifications ─────────────────────────────────────────
    case 'notifications':
        $nm = new NotificationModel();
        Response::success($nm->getForCustomer($customerId, 30));
        break;

    case 'notification-read':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $nm = new NotificationModel();
        if (!empty($data['id'])) {
            $nm->markRead((int)$data['id'], $customerId);
        } else {
            $nm->markAllRead($customerId);
        }
        Response::success([], 'Marked as read.');
        break;

    // ── Profile update ────────────────────────────────────────
    case 'profile-update':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $cm = new CustomerModel();
        $cm->update($customerId, [
            'name'        => sanitize($data['name'] ?? ''),
            'mobile'      => sanitize($data['mobile'] ?? ''),
            'wing'        => sanitize($data['wing'] ?? ''),
            'flat_number' => sanitize($data['flat_number'] ?? ''),
        ]);
        Response::success([], 'Profile updated.');
        break;

    // ── Change password ───────────────────────────────────────
    case 'change-password':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $cm       = new CustomerModel();
        $customer = $cm->findById($customerId);
        if (!verifyPassword($data['current_password'] ?? '', $customer['password'])) {
            Response::error('Current password is incorrect.', 403);
        }
        if (strlen($data['new_password'] ?? '') < 8) {
            Response::error('New password must be at least 8 characters.');
        }
        $cm->updatePassword($customerId, $data['new_password']);
        Response::success([], 'Password changed successfully.');
        break;

    // ── Available products (for Add Milk flow) ────────────────
    case 'products':
        $pm = new ProductModel();
        Response::success([
            'companies' => $pm->getCompanies(),
            'products'  => $pm->getAllWithCompany(),
        ]);
        break;

    default:
        Response::error('Unknown action.', 404);
}
