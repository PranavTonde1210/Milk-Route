<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/../models/CustomerModel.php';
require_once __DIR__ . '/../models/AdminModel.php';
require_once __DIR__ . '/../models/ProductModel.php';
require_once __DIR__ . '/../models/DeliveryModel.php';
require_once __DIR__ . '/../models/SubscriptionModel.php';
require_once __DIR__ . '/../models/PaymentModel.php';
require_once __DIR__ . '/../models/NotificationModel.php';

// Start session with secure settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name(SESSION_NAME);
session_start();

// CORS headers for API calls (same-origin, no external needed)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Helper functions
function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function generateToken(int $length = 64): string {
    return bin2hex(random_bytes($length / 2));
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function today(): string {
    return date('Y-m-d');
}

function currentMonth(): int { return (int)date('n'); }
function currentYear(): int  { return (int)date('Y'); }

function isDeliveryCutoffPassed(): bool {
    return (int)date('G') >= DELIVERY_CUTOFF_HOUR;
}

function getPriceForProductOnDate(int $productId, string $date): ?float {
    $db = DB::getInstance();
    $price = $db->fetchValue(
        "SELECT price_per_litre FROM milk_prices
         WHERE product_id = ? AND effective_from <= ?
         ORDER BY effective_from DESC LIMIT 1",
        [$productId, $date]
    );
    return $price ? (float)$price : null;
}

function formatINR(float $amount): string {
    return '₹' . number_format($amount, 2);
}

function isAlternateDayDelivery(string $alternateStart, string $date): bool {
    $start = new DateTime($alternateStart);
    $check = new DateTime($date);
    $diff  = $start->diff($check)->days;
    return ($diff % 2 === 0);
}
