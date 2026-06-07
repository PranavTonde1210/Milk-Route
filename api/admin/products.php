<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$adminId = Auth::requireAdmin();
$action  = $_GET['action'] ?? '';
$data    = json_decode(file_get_contents('php://input'), true) ?? [];
$method  = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── List all products ─────────────────────────────────────
    case 'list':
        $pm = new ProductModel();
        Response::success([
            'companies' => $pm->getCompanies(),
            'products'  => $pm->getAllWithCompany(),
        ]);
        break;

    // ── Add product ───────────────────────────────────────────
    case 'add':
        if ($method !== 'POST') Response::error('POST required.', 405);
        if (empty($data['company_id']) || empty($data['name'])) {
            Response::error('company_id and name required.');
        }
        $pm = new ProductModel();
        $id = $pm->createProduct([
            'company_id'  => (int)$data['company_id'],
            'name'        => sanitize($data['name']),
            'description' => sanitize($data['description'] ?? ''),
        ]);
        // Set initial price if provided
        if (!empty($data['initial_price'])) {
            $pm->setPrice($id, (float)$data['initial_price'], today(), $adminId);
        }
        Response::success(['id' => $id], 'Product added.');
        break;

    // ── Update product ────────────────────────────────────────
    case 'update':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $id = (int)($data['id'] ?? 0);
        if (!$id) Response::error('Product ID required.');
        $pm = new ProductModel();
        $pm->updateProduct($id, [
            'name'        => sanitize($data['name'] ?? ''),
            'description' => sanitize($data['description'] ?? ''),
            'is_active'   => (int)($data['is_active'] ?? 1),
        ]);
        Response::success([], 'Product updated.');
        break;

    // ── Set new price ─────────────────────────────────────────
    case 'set-price':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $productId     = (int)($data['product_id'] ?? 0);
        $price         = (float)($data['price'] ?? 0);
        $effectiveFrom = $data['effective_from'] ?? today();
        if (!$productId || $price <= 0) Response::error('product_id and valid price required.');
        if ($effectiveFrom < today()) Response::error('Effective date cannot be in the past.');

        $pm = new ProductModel();
        $pm->setPrice($productId, $price, $effectiveFrom, $adminId);

        // setPrice() already: closes old price, updates pending deliveries, syncs payments, notifies customers

        Response::success([], 'Price updated. Pending deliveries and bills have been synced automatically.');
        break;

    // ── Price history for a product ───────────────────────────
    case 'price-history':
        $productId = (int)($_GET['product_id'] ?? 0);
        if (!$productId) Response::error('product_id required.');
        $pm = new ProductModel();
        Response::success($pm->getPriceHistory($productId));
        break;

    // ── Add company ───────────────────────────────────────────
    case 'add-company':
        Auth::requireSuperAdmin();
        if ($method !== 'POST') Response::error('POST required.', 405);
        if (empty($data['name'])) Response::error('Company name required.');
        $db = DB::getInstance();
        $id = $db->insert(
            'INSERT INTO milk_companies (name, tagline, logo_color) VALUES (?, ?, ?)',
            [sanitize($data['name']), sanitize($data['tagline'] ?? ''), sanitize($data['logo_color'] ?? '#22c55e')]
        );
        Response::success(['id' => $id], 'Company added.');
        break;

    // ── Toggle company active ─────────────────────────────────
    case 'toggle-company':
        if ($method !== 'POST') Response::error('POST required.', 405);
        $id = (int)($data['id'] ?? 0);
        if (!$id) Response::error('Company ID required.');
        $db = DB::getInstance();
        $db->run('UPDATE milk_companies SET is_active = NOT is_active WHERE id = ?', [$id]);
        Response::success([], 'Company status toggled.');
        break;

    default:
        Response::error('Unknown action.', 404);
}
