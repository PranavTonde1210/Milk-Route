<?php
class DeliveryModel {
    private DB $db;

    public function __construct() {
        $this->db = DB::getInstance();
    }

    // Generate delivery rows for a customer for a given date
    // Called by cron or when admin triggers it
    public function generateForDate(int $customerId, string $date): void {
        $customer = $this->db->fetchOne(
            'SELECT * FROM customers WHERE id = ? AND is_active = 1', [$customerId]
        );
        if (!$customer) return;

        // Check skip requests
        $skipped = $this->db->fetchOne(
            'SELECT id FROM skip_requests
             WHERE customer_id = ? AND skip_date_start <= ? AND skip_date_end >= ?',
            [$customerId, $date, $date]
        );

        // Check alternate day pattern
        $isAlternateSkip = false;
        if ($customer['delivery_pattern'] === 'alternate' && $customer['alternate_start']) {
            $isAlternateSkip = !isAlternateDayDelivery($customer['alternate_start'], $date);
        }

        $subscriptions = $this->db->fetchAll(
            'SELECT s.*, p.name as product_name FROM subscriptions s
             JOIN milk_products p ON p.id = s.product_id
             WHERE s.customer_id = ? AND s.is_active = 1', [$customerId]
        );

        foreach ($subscriptions as $sub) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM daily_deliveries WHERE customer_id = ? AND product_id = ? AND delivery_date = ?',
                [$customerId, $sub['product_id'], $date]
            );
            if ($exists) continue;

            $status = ($skipped || $isAlternateSkip) ? 'skipped' : 'pending';
            $skipReason = $skipped ? 'customer_request' : ($isAlternateSkip ? 'alternate_day' : null);
            $price = getPriceForProductOnDate($sub['product_id'], $date);
            $qty = ($status === 'skipped') ? 0 : $sub['default_qty'];

            $this->db->run(
                'INSERT INTO daily_deliveries
                 (customer_id, product_id, delivery_date, qty_ordered, qty_delivered,
                  status, skip_reason, price_at_delivery, marked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$customerId, $sub['product_id'], $date, $qty,
                 $status === 'skipped' ? 0 : null,
                 $status, $skipReason, $price, 'system']
            );
        }
    }

    // Admin: mark all pending deliveries as delivered for a date
    public function bulkMarkDelivered(string $date, ?int $customerId = null): int {
        $where = 'delivery_date = ? AND status = ?';
        $params = [$date, 'pending'];
        if ($customerId) {
            $where .= ' AND customer_id = ?';
            $params[] = $customerId;
        }
        // Set qty_delivered = qty_ordered for bulk mark
        $this->db->run(
            "UPDATE daily_deliveries SET status = 'delivered',
             qty_delivered = qty_ordered, delivery_time = NOW(), marked_by = 'admin'
             WHERE $where", $params
        );
        return $this->db->fetchValue(
            "SELECT ROW_COUNT()"
        );
    }

    // Admin: mark single delivery
    public function markDelivery(int $deliveryId, string $status, float $qtyDelivered, int $adminId): bool {
        $delivery = $this->db->fetchOne('SELECT * FROM daily_deliveries WHERE id = ?', [$deliveryId]);
        if (!$delivery) return false;

        $this->db->run(
            "UPDATE daily_deliveries SET status = ?, qty_delivered = ?,
             delivery_time = NOW(), marked_by = 'admin'
             WHERE id = ?",
            [$status, $qtyDelivered, $deliveryId]
        );

        // Notify customer
        $notif = new NotificationModel();
        $msg = $status === 'delivered'
            ? "Your milk ({$qtyDelivered}L) was delivered today."
            : "We could not deliver your milk today. Please contact your distributor.";
        $notif->create($delivery['customer_id'], 'Delivery Update', $msg, 'delivery', $adminId);

        return true;
    }

    // Customer: update today's qty (before cutoff)
    public function updateTodayQty(int $customerId, int $productId, float $qty): bool {
        if (isDeliveryCutoffPassed()) return false;
        $this->db->run(
            "UPDATE daily_deliveries SET qty_ordered = ?, updated_at = NOW()
             WHERE customer_id = ? AND product_id = ? AND delivery_date = ? AND status = 'pending'",
            [$qty, $customerId, $productId, today()]
        );
        return true;
    }

    // Get today's deliveries for customer
    public function getTodayForCustomer(int $customerId): array {
        return $this->db->fetchAll(
            'SELECT dd.*, mp.name as product_name, mc.name as company_name
             FROM daily_deliveries dd
             JOIN milk_products mp ON mp.id = dd.product_id
             JOIN milk_companies mc ON mc.id = mp.company_id
             WHERE dd.customer_id = ? AND dd.delivery_date = ?',
            [$customerId, today()]
        );
    }

    // Get calendar data for month (customer)
    public function getMonthCalendar(int $customerId, int $month, int $year): array {
        return $this->db->fetchAll(
            'SELECT delivery_date, SUM(qty_delivered) as total_qty, status
             FROM daily_deliveries
             WHERE customer_id = ? AND MONTH(delivery_date) = ? AND YEAR(delivery_date) = ?
             GROUP BY delivery_date, status
             ORDER BY delivery_date',
            [$customerId, $month, $year]
        );
    }

    // Admin: get all deliveries for a date (with customer info)
    public function getByDate(string $date, string $status = '', string $search = ''): array {
        $where = ['dd.delivery_date = ?'];
        $params = [$date];
        if ($status) { $where[] = 'dd.status = ?'; $params[] = $status; }
        if ($search) {
            $where[] = '(c.name LIKE ? OR c.flat_number LIKE ?)';
            $params[] = "%$search%"; $params[] = "%$search%";
        }
        $whereStr = implode(' AND ', $where);
        return $this->db->fetchAll(
            "SELECT dd.*, c.name as customer_name, c.wing, c.flat_number,
             mp.name as product_name, mc.name as company_name
             FROM daily_deliveries dd
             JOIN customers c ON c.id = dd.customer_id
             JOIN milk_products mp ON mp.id = dd.product_id
             JOIN milk_companies mc ON mc.id = mp.company_id
             WHERE $whereStr ORDER BY c.wing, c.flat_number, mp.name",
            $params
        );
    }

    // Admin: delivery summary stats for date
    public function getDaySummary(string $date): array {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) as count, SUM(qty_ordered) as total_qty
             FROM daily_deliveries WHERE delivery_date = ? GROUP BY status",
            [$date]
        );
        $summary = ['pending' => 0, 'delivered' => 0, 'not_delivered' => 0, 'skipped' => 0, 'total_litres' => 0];
        foreach ($rows as $r) {
            $summary[$r['status']] = (int)$r['count'];
            $summary['total_litres'] += (float)$r['total_qty'];
        }
        return $summary;
    }

    // Customer: report not delivered
    public function reportNotDelivered(int $customerId, string $date): bool {
        $this->db->run(
            "UPDATE daily_deliveries SET status = 'not_delivered', marked_by = 'customer'
             WHERE customer_id = ? AND delivery_date = ? AND status = 'delivered'",
            [$customerId, $date]
        );
        return true;
    }
}
