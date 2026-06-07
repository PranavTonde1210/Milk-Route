#!/usr/bin/env php
<?php
/**
 * MilkRoute Daily Cron Job
 * Run via cPanel Cron: 0 0 * * * php /path/to/your/site/cron/daily.php
 * Or via URL cron:    0 0 * * * curl -s "https://yourdomain.com/cron/daily.php?key=YOUR_CRON_KEY"
 */

define('CRON_MODE', true);

// Only allow CLI or secret key
if (PHP_SAPI !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== 'CHANGE_THIS_CRON_SECRET_KEY') {
        http_response_code(403);
        die('Forbidden');
    }
}

require_once __DIR__ . '/../core/Bootstrap.php';

$log = [];
$log[] = "[" . date('Y-m-d H:i:s') . "] MilkRoute Daily Cron Starting";

try {
    $db          = DB::getInstance();
    $dm          = new DeliveryModel();
    $pm          = new PaymentModel();
    $nm          = new NotificationModel();

    $tomorrow    = date('Y-m-d', strtotime('+1 day'));
    $today       = today();

    // ── 1. Generate delivery rows for tomorrow ────────────────
    $customers = $db->fetchAll(
        'SELECT id FROM customers WHERE is_active = 1 AND email_verified = 1'
    );
    $count = 0;
    foreach ($customers as $c) {
        $dm->generateForDate($c['id'], $tomorrow);
        $count++;
    }
    $log[] = "Generated delivery rows for $tomorrow: $count customers";

    // ── 2. Auto-mark yesterday's pending as 'delivered' ───────
    // (Only if your distributor doesn't manually mark — remove if not needed)
    // $yesterday = date('Y-m-d', strtotime('-1 day'));
    // $db->run("UPDATE daily_deliveries SET status='delivered', qty_delivered=qty_ordered,
    //           delivery_time='07:00:00', marked_by='system'
    //           WHERE delivery_date=? AND status='pending'", [$yesterday]);

    // ── 3. Sync payment totals for current month ──────────────
    $month = currentMonth();
    $year  = currentYear();
    foreach ($customers as $c) {
        $pm->syncTotal($c['id'], $month, $year);
    }
    $log[] = "Payment totals synced for " . date('F Y') . " — $count customers";

    // ── 4. Send payment reminder on 25th of each month ───────
    if ((int)date('j') === 25) {
        $unpaid = $db->fetchAll(
            "SELECT p.customer_id, p.balance, c.name
             FROM payments p JOIN customers c ON c.id = p.customer_id
             WHERE p.month = ? AND p.year = ? AND p.status IN ('unpaid','partial')",
            [$month, $year]
        );
        foreach ($unpaid as $u) {
            $nm->create(
                $u['customer_id'],
                'Payment Reminder',
                "Hi {$u['name']}, your milk bill balance of ₹{$u['balance']} for " . date('F Y') . " is due. Please pay at your earliest.",
                'payment'
            );
        }
        $log[] = "Payment reminders sent: " . count($unpaid) . " customers";
    }

    // ── 5. New month: initialize payment records ──────────────
    if ((int)date('j') === 1) {
        foreach ($customers as $c) {
            $pm->getOrCreate($c['id'], $month, $year);
        }
        $log[] = "New month payment records initialized";
    }

    $log[] = "[" . date('Y-m-d H:i:s') . "] Cron completed successfully\n";

} catch (Exception $e) {
    $log[] = "ERROR: " . $e->getMessage();
}

// Write to log file
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
file_put_contents($logDir . '/cron.log', implode("\n", $log) . "\n", FILE_APPEND);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain');
}
echo implode("\n", $log);
