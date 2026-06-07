<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$adminId = Auth::requireAdmin();
$action  = $_GET['action'] ?? '';
$data    = json_decode(file_get_contents('php://input'), true) ?? [];
$method  = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── List all customers (paginated + search) ───────────────
    case 'list':
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, (int)($_GET['per_page'] ?? 20));
        $search  = sanitize($_GET['search'] ?? '');
        $cm      = new CustomerModel();
        $result  = $cm->getAll($page, $perPage, $search);
        Response::paginated($result['items'], $result['total'], $page, $perPage);
        break;

    // ── Customer stats summary ────────────────────────────────
    case 'stats':
        $cm = new CustomerModel();
        Response::success($cm->getStats());
        break;

    // ── View single customer (full detail) ───────────────────
    case 'view':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) Response::error('Customer ID required.');
        $cm      = new CustomerModel();
        $sm      = new SubscriptionModel();
        $pm      = new PaymentModel();
        $dm      = new DeliveryModel();
        $customer = $cm->findById($id);
        if (!$customer) Response::error('Customer not found.', 404);
        unset($customer['password'], $customer['reset_token'], $customer['email_verify_token']);
        Response::success([
            'customer'      => $customer,
            'subscriptions' => $sm->getAdminView($id),
            'payment'       => $pm->getOrCreate($id, currentMonth(), currentYear()),
            'recent_deliveries' => $dm->getByDate(today(), '', ''), // all today, filter client-side
        ]);
        break;

    // ── Toggle active status ──────────────────────────────────
    case 'toggle-active':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $id = (int)($data['id'] ?? 0);
        if (!$id) Response::error('Customer ID required.');
        $cm     = new CustomerModel();
        $active = $cm->toggleActive($id);
        Response::success(['is_active' => $active], 'Status updated.');
        break;

    // ── Update customer profile (admin) ──────────────────────
    case 'update':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $id = (int)($data['id'] ?? 0);
        if (!$id) Response::error('Customer ID required.');
        $cm = new CustomerModel();
        $cm->update($id, [
            'name'             => sanitize($data['name'] ?? ''),
            'mobile'           => sanitize($data['mobile'] ?? ''),
            'wing'             => sanitize($data['wing'] ?? ''),
            'flat_number'      => sanitize($data['flat_number'] ?? ''),
            'delivery_pattern' => in_array($data['delivery_pattern'] ?? '', ['daily','alternate'])
                                   ? $data['delivery_pattern'] : null,
        ]);
        Response::success([], 'Customer updated.');
        break;

    // ── Manage subscription (admin sets for customer) ─────────
    case 'subscription-save':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $customerId = (int)($data['customer_id'] ?? 0);
        $productId  = (int)($data['product_id'] ?? 0);
        $qty        = (float)($data['qty'] ?? 0);
        if (!$customerId || !$productId) Response::error('customer_id and product_id required.');
        $sm = new SubscriptionModel();
        $sm->upsert($customerId, $productId, $qty);
        // Regenerate tomorrow's delivery row
        $dm = new DeliveryModel();
        $dm->generateForDate($customerId, date('Y-m-d', strtotime('+1 day')));
        Response::success([], 'Subscription saved.');
        break;

    case 'subscription-remove':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $customerId = (int)($data['customer_id'] ?? 0);
        $productId  = (int)($data['product_id'] ?? 0);
        if (!$customerId || !$productId) Response::error('IDs required.');
        (new SubscriptionModel())->remove($customerId, $productId);
        Response::success([], 'Subscription removed.');
        break;

    // ── Customer delivery history ─────────────────────────────
    case 'delivery-history':
        $id    = (int)($_GET['id'] ?? 0);
        $month = (int)($_GET['month'] ?? currentMonth());
        $year  = (int)($_GET['year']  ?? currentYear());
        if (!$id) Response::error('Customer ID required.');
        $dm = new DeliveryModel();
        Response::success($dm->getMonthCalendar($id, $month, $year));
        break;

    // ── Customer payment history ──────────────────────────────
    case 'payment-history':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) Response::error('Customer ID required.');
        $pm = new PaymentModel();
        Response::success($pm->getHistory($id, 12));
        break;

    // ── Send notification to single customer ──────────────────
    case 'notify':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $customerId = (int)($data['customer_id'] ?? 0);
        $title   = sanitize($data['title'] ?? '');
        $message = sanitize($data['message'] ?? '');
        if (!$title || !$message) Response::error('Title and message required.');
        $nm = new NotificationModel();
        if ($customerId) {
            $nm->create($customerId, $title, $message, 'general', $adminId);
        } else {
            $nm->broadcast($title, $message, 'general', $adminId);
        }
        Response::success([], $customerId ? 'Notification sent.' : 'Broadcast sent to all customers.');
        break;

    default:
        Response::error('Unknown action.', 404);
}
