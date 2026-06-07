<?php
// ── ProductModel ──────────────────────────────────────────────────────────────
class ProductModel {
    private DB $db;
    public function __construct() { $this->db = DB::getInstance(); }

    public function getAllWithCompany(): array {
        return $this->db->fetchAll(
            'SELECT mp.*, mc.name as company_name, mc.logo_color,
             (SELECT price_per_litre FROM milk_prices
              WHERE product_id = mp.id AND effective_from <= CURDATE()
              ORDER BY effective_from DESC LIMIT 1) as current_price
             FROM milk_products mp
             JOIN milk_companies mc ON mc.id = mp.company_id
             WHERE mp.is_active = 1 ORDER BY mc.name, mp.name'
        );
    }

    public function getCompanies(): array {
        return $this->db->fetchAll('SELECT * FROM milk_companies WHERE is_active = 1 ORDER BY name');
    }

    public function getByCompany(int $companyId): array {
        return $this->db->fetchAll(
            'SELECT mp.*,
             (SELECT price_per_litre FROM milk_prices
              WHERE product_id = mp.id AND effective_from <= CURDATE()
              ORDER BY effective_from DESC LIMIT 1) as current_price
             FROM milk_products mp WHERE mp.company_id = ? AND mp.is_active = 1',
            [$companyId]
        );
    }

    public function createProduct(array $data): int {
        return $this->db->insert(
            'INSERT INTO milk_products (company_id, name, description) VALUES (?, ?, ?)',
            [$data['company_id'], $data['name'], $data['description'] ?? '']
        );
    }

    public function updateProduct(int $id, array $data): void {
        $this->db->run(
            'UPDATE milk_products SET name = ?, description = ?, is_active = ? WHERE id = ?',
            [$data['name'], $data['description'] ?? '', $data['is_active'] ?? 1, $id]
        );
    }

    // Set new price (close previous, open new)
    public function setPrice(int $productId, float $price, string $effectiveFrom, int $adminId): void {
        // Close previous price
        $this->db->run(
            'UPDATE milk_prices SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
             WHERE product_id = ? AND effective_to IS NULL',
            [$effectiveFrom, $productId]
        );
        // Insert new price
        $this->db->insert(
            'INSERT INTO milk_prices (product_id, price_per_litre, effective_from, created_by)
             VALUES (?, ?, ?, ?)',
            [$productId, $price, $effectiveFrom, $adminId]
        );
        // Update delivery records that haven't been delivered yet
        $this->db->run(
            "UPDATE daily_deliveries SET price_at_delivery = ?
             WHERE product_id = ? AND delivery_date >= ? AND status = 'pending'",
            [$price, $productId, $effectiveFrom]
        );
        // Sync payments for affected customers this month
        $affected = $this->db->fetchAll(
            "SELECT DISTINCT customer_id FROM daily_deliveries
             WHERE product_id = ? AND MONTH(delivery_date) = MONTH(?) AND YEAR(delivery_date) = YEAR(?)",
            [$productId, $effectiveFrom, $effectiveFrom]
        );
        $pm = new PaymentModel();
        foreach ($affected as $a) {
            $pm->syncTotal($a['customer_id'], (int)date('n', strtotime($effectiveFrom)),
                           (int)date('Y', strtotime($effectiveFrom)));
        }
        // Notify all active customers
        $customers = DB::getInstance()->fetchAll('SELECT id FROM customers WHERE is_active = 1');
        $notif = new NotificationModel();
        foreach ($customers as $c) {
            $product = DB::getInstance()->fetchOne('SELECT name FROM milk_products WHERE id = ?', [$productId]);
            $notif->create($c['id'], 'Price Change', "{$product['name']} price updated to ₹{$price}/L from {$effectiveFrom}.", 'price_change', $adminId);
        }
    }

    public function getPriceHistory(int $productId): array {
        return $this->db->fetchAll(
            'SELECT * FROM milk_prices WHERE product_id = ? ORDER BY effective_from DESC', [$productId]
        );
    }
}

// ── SubscriptionModel ─────────────────────────────────────────────────────────
class SubscriptionModel {
    private DB $db;
    public function __construct() { $this->db = DB::getInstance(); }

    public function getForCustomer(int $customerId): array {
        return $this->db->fetchAll(
            'SELECT s.*, mp.name as product_name, mc.name as company_name,
             (SELECT price_per_litre FROM milk_prices WHERE product_id = s.product_id
              AND effective_from <= CURDATE() ORDER BY effective_from DESC LIMIT 1) as current_price
             FROM subscriptions s
             JOIN milk_products mp ON mp.id = s.product_id
             JOIN milk_companies mc ON mc.id = mp.company_id
             WHERE s.customer_id = ? AND s.is_active = 1',
            [$customerId]
        );
    }

    public function upsert(int $customerId, int $productId, float $qty): void {
        $exists = $this->db->fetchOne(
            'SELECT id FROM subscriptions WHERE customer_id = ? AND product_id = ?',
            [$customerId, $productId]
        );
        if ($exists) {
            $this->db->run(
                'UPDATE subscriptions SET default_qty = ?, is_active = 1 WHERE id = ?',
                [$qty, $exists['id']]
            );
        } else {
            $this->db->insert(
                'INSERT INTO subscriptions (customer_id, product_id, default_qty) VALUES (?, ?, ?)',
                [$customerId, $productId, $qty]
            );
        }
    }

    public function remove(int $customerId, int $productId): void {
        $this->db->run(
            'UPDATE subscriptions SET is_active = 0 WHERE customer_id = ? AND product_id = ?',
            [$customerId, $productId]
        );
    }

    public function getAdminView(int $customerId): array {
        return $this->db->fetchAll(
            'SELECT s.*, mp.name as product_name, mc.name as company_name
             FROM subscriptions s
             JOIN milk_products mp ON mp.id = s.product_id
             JOIN milk_companies mc ON mc.id = mp.company_id
             WHERE s.customer_id = ?', [$customerId]
        );
    }
}

// ── NotificationModel ──────────────────────────────────────────────────────────
class NotificationModel {
    private DB $db;
    public function __construct() { $this->db = DB::getInstance(); }

    public function create(?int $customerId, string $title, string $message,
                           string $type = 'general', ?int $adminId = null): int {
        return $this->db->insert(
            'INSERT INTO notifications (customer_id, title, message, type, created_by) VALUES (?, ?, ?, ?, ?)',
            [$customerId, $title, $message, $type, $adminId]
        );
    }

    public function getForCustomer(int $customerId, int $limit = 20): array {
        return $this->db->fetchAll(
            'SELECT * FROM notifications WHERE customer_id = ? OR customer_id IS NULL
             ORDER BY created_at DESC LIMIT ?',
            [$customerId, $limit]
        );
    }

    public function markRead(int $id, int $customerId): void {
        $this->db->run(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND (customer_id = ? OR customer_id IS NULL)',
            [$id, $customerId]
        );
    }

    public function markAllRead(int $customerId): void {
        $this->db->run(
            'UPDATE notifications SET is_read = 1 WHERE customer_id = ? AND is_read = 0', [$customerId]
        );
    }

    public function unreadCount(int $customerId): int {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE (customer_id = ? OR customer_id IS NULL) AND is_read = 0',
            [$customerId]
        );
    }

    public function broadcast(string $title, string $message, string $type, int $adminId): void {
        $this->create(null, $title, $message, $type, $adminId);
    }
}

// ── AdminModel ─────────────────────────────────────────────────────────────────
class AdminModel {
    private DB $db;
    public function __construct() { $this->db = DB::getInstance(); }

    public function findByEmail(string $email): ?array {
        return $this->db->fetchOne('SELECT * FROM admins WHERE email = ?', [$email]);
    }

    public function findById(int $id): ?array {
        return $this->db->fetchOne('SELECT id, name, email, role, created_at FROM admins WHERE id = ?', [$id]);
    }

    public function getDashboardStats(): array {
        $pm = new PaymentModel();
        return [
            'customers'      => (int)$this->db->fetchValue('SELECT COUNT(*) FROM customers WHERE is_active=1'),
            'today_delivered'=> (int)$this->db->fetchValue(
                "SELECT COUNT(DISTINCT customer_id) FROM daily_deliveries WHERE delivery_date=CURDATE() AND status='delivered'"
            ),
            'today_pending'  => (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM daily_deliveries WHERE delivery_date=CURDATE() AND status='pending'"
            ),
            'today_litres'   => (float)$this->db->fetchValue(
                "SELECT COALESCE(SUM(qty_delivered),0) FROM daily_deliveries WHERE delivery_date=CURDATE() AND status='delivered'"
            ),
            'outstanding'    => (float)$this->db->fetchValue(
                "SELECT COALESCE(SUM(balance),0) FROM payments WHERE status IN ('unpaid','partial')"
            ),
            'month_collected'=> (float)$this->db->fetchValue(
                "SELECT COALESCE(SUM(paid_amount),0) FROM payments WHERE month=? AND year=?",
                [currentMonth(), currentYear()]
            ),
        ];
    }
}
