<?php
class PaymentModel {
    private DB $db;

    public function __construct() {
        $this->db = DB::getInstance();
    }

    // Calculate what a customer owes for a given month
    public function calculateMonthTotal(int $customerId, int $month, int $year): array {
        $deliveries = $this->db->fetchAll(
            "SELECT dd.product_id, dd.qty_delivered, dd.price_at_delivery, dd.delivery_date,
             mp.name as product_name
             FROM daily_deliveries dd
             JOIN milk_products mp ON mp.id = dd.product_id
             WHERE dd.customer_id = ? AND MONTH(dd.delivery_date) = ?
             AND YEAR(dd.delivery_date) = ? AND dd.status = 'delivered'",
            [$customerId, $month, $year]
        );

        $productSummary = [];
        $grandTotal = 0;

        foreach ($deliveries as $d) {
            $amount = (float)$d['qty_delivered'] * (float)$d['price_at_delivery'];
            $grandTotal += $amount;
            $pid = $d['product_id'];
            if (!isset($productSummary[$pid])) {
                $productSummary[$pid] = [
                    'product_name' => $d['product_name'],
                    'total_qty'    => 0,
                    'total_amount' => 0,
                    'price_history'=> [],
                ];
            }
            $productSummary[$pid]['total_qty'] += $d['qty_delivered'];
            $productSummary[$pid]['total_amount'] += $amount;
            // Track price changes
            $price = number_format($d['price_at_delivery'], 2);
            if (!isset($productSummary[$pid]['price_history'][$price])) {
                $productSummary[$pid]['price_history'][$price] = [
                    'price' => $d['price_at_delivery'], 'qty' => 0
                ];
            }
            $productSummary[$pid]['price_history'][$price]['qty'] += $d['qty_delivered'];
        }

        // Count delivered & skipped days
        $stats = $this->db->fetchOne(
            "SELECT
             SUM(status = 'delivered') as delivered_days,
             SUM(status = 'skipped') as skipped_days,
             SUM(CASE WHEN status='delivered' THEN qty_delivered ELSE 0 END) as total_qty
             FROM daily_deliveries
             WHERE customer_id = ? AND MONTH(delivery_date) = ? AND YEAR(delivery_date) = ?",
            [$customerId, $month, $year]
        );

        return [
            'grand_total'    => round($grandTotal, 2),
            'product_summary'=> array_values($productSummary),
            'delivered_days' => (int)($stats['delivered_days'] ?? 0),
            'skipped_days'   => (int)($stats['skipped_days'] ?? 0),
            'total_qty'      => (float)($stats['total_qty'] ?? 0),
        ];
    }

    // Get or create payment record for month
    public function getOrCreate(int $customerId, int $month, int $year): array {
        $payment = $this->db->fetchOne(
            'SELECT * FROM payments WHERE customer_id = ? AND month = ? AND year = ?',
            [$customerId, $month, $year]
        );
        if ($payment) return $payment;

        // Calculate total
        $calc = $this->calculateMonthTotal($customerId, $month, $year);

        // Previous balance
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear  = $month === 1 ? $year - 1 : $year;
        $prevBalance = (float)($this->db->fetchValue(
            'SELECT balance FROM payments WHERE customer_id = ? AND month = ? AND year = ?',
            [$customerId, $prevMonth, $prevYear]
        ) ?? 0);

        $total = $calc['grand_total'] + $prevBalance;

        $id = $this->db->insert(
            'INSERT INTO payments (customer_id, month, year, total_amount, paid_amount, balance, status)
             VALUES (?, ?, ?, ?, 0, ?, ?)',
            [$customerId, $month, $year, $total, $total, 'unpaid']
        );
        return $this->db->fetchOne('SELECT * FROM payments WHERE id = ?', [$id]);
    }

    // Admin: record a payment
    public function recordPayment(int $customerId, int $month, int $year, float $amount,
                                  string $method, string $ref = '', int $adminId = 0): array {
        $payment = $this->getOrCreate($customerId, $month, $year);
        $newPaid = (float)$payment['paid_amount'] + $amount;
        $newBalance = max(0, (float)$payment['total_amount'] - $newPaid);
        $status = $newBalance <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');

        $this->db->run(
            'UPDATE payments SET paid_amount = ?, balance = ?, status = ?,
             payment_method = ?, payment_date = CURDATE(), transaction_ref = ?, recorded_by = ?
             WHERE id = ?',
            [$newPaid, $newBalance, $status, $method, $ref, $adminId, $payment['id']]
        );

        // Notify customer
        $notif = new NotificationModel();
        $notif->create(
            $customerId,
            'Payment Recorded',
            "Payment of ₹{$amount} recorded via {$method} for " . date('F Y', mktime(0,0,0,$month,1,$year)) . ". Balance: ₹{$newBalance}",
            'payment',
            $adminId
        );

        return $this->db->fetchOne('SELECT * FROM payments WHERE id = ?', [$payment['id']]);
    }

    // Sync total when admin updates price or new delivery recorded
    public function syncTotal(int $customerId, int $month, int $year): void {
        $payment = $this->db->fetchOne(
            'SELECT * FROM payments WHERE customer_id = ? AND month = ? AND year = ?',
            [$customerId, $month, $year]
        );
        if (!$payment) return;

        $calc = $this->calculateMonthTotal($customerId, $month, $year);
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear  = $month === 1 ? $year - 1 : $year;
        $prevBalance = (float)($this->db->fetchValue(
            'SELECT balance FROM payments WHERE customer_id = ? AND month = ? AND year = ?',
            [$customerId, $prevMonth, $prevYear]
        ) ?? 0);

        $newTotal   = $calc['grand_total'] + $prevBalance;
        $newBalance = max(0, $newTotal - (float)$payment['paid_amount']);
        $status     = $newBalance <= 0 ? 'paid' : ((float)$payment['paid_amount'] > 0 ? 'partial' : 'unpaid');

        $this->db->run(
            'UPDATE payments SET total_amount = ?, balance = ?, status = ? WHERE id = ?',
            [$newTotal, $newBalance, $status, $payment['id']]
        );
    }

    public function getHistory(int $customerId, int $limit = 12): array {
        return $this->db->fetchAll(
            'SELECT * FROM payments WHERE customer_id = ? ORDER BY year DESC, month DESC LIMIT ?',
            [$customerId, $limit]
        );
    }

    public function getAllOutstanding(): array {
        return $this->db->fetchAll(
            "SELECT p.*, c.name as customer_name, c.wing, c.flat_number, c.mobile
             FROM payments p JOIN customers c ON c.id = p.customer_id
             WHERE p.status IN ('unpaid','partial') ORDER BY p.year DESC, p.month DESC"
        );
    }

    public function getMonthSummary(int $month, int $year): array {
        return $this->db->fetchOne(
            "SELECT
             SUM(total_amount) as total_billed,
             SUM(paid_amount) as total_collected,
             SUM(balance) as total_outstanding,
             SUM(status='paid') as fully_paid,
             SUM(status='partial') as partial,
             SUM(status='unpaid') as unpaid
             FROM payments WHERE month = ? AND year = ?",
            [$month, $year]
        ) ?? [];
    }
}
