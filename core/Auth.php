<?php
class Auth {

    // ── Customer Auth ──────────────────────────────────────

    public static function loginCustomer(array $customer): void {
        session_regenerate_id(true);
        $_SESSION['customer_id']   = $customer['id'];
        $_SESSION['customer_name'] = $customer['name'];
        $_SESSION['customer_logged_in'] = true;
        $_SESSION['customer_login_time'] = time();
    }

    public static function logoutCustomer(): void {
        unset($_SESSION['customer_id'], $_SESSION['customer_name'],
              $_SESSION['customer_logged_in'], $_SESSION['customer_login_time']);
    }

    public static function isCustomerLoggedIn(): bool {
        if (empty($_SESSION['customer_logged_in'])) return false;
        // Session timeout check
        if (time() - ($_SESSION['customer_login_time'] ?? 0) > CUSTOMER_SESSION_DURATION) {
            self::logoutCustomer();
            return false;
        }
        return true;
    }

    public static function requireCustomer(): int {
        if (!self::isCustomerLoggedIn()) {
            Response::error('Unauthorized. Please login.', 401);
            exit;
        }
        return (int)$_SESSION['customer_id'];
    }

    public static function customerId(): ?int {
        return isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
    }

    // ── Admin Auth ──────────────────────────────────────────

    public static function loginAdmin(array $admin): void {
        session_regenerate_id(true);
        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_name']     = $admin['name'];
        $_SESSION['admin_role']     = $admin['role'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
    }

    public static function logoutAdmin(): void {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'],
              $_SESSION['admin_logged_in'], $_SESSION['admin_login_time']);
    }

    public static function isAdminLoggedIn(): bool {
        if (empty($_SESSION['admin_logged_in'])) return false;
        if (time() - ($_SESSION['admin_login_time'] ?? 0) > ADMIN_SESSION_DURATION) {
            self::logoutAdmin();
            return false;
        }
        return true;
    }

    public static function requireAdmin(): int {
        if (!self::isAdminLoggedIn()) {
            Response::error('Admin access required.', 401);
            exit;
        }
        return (int)$_SESSION['admin_id'];
    }

    public static function requireSuperAdmin(): int {
        self::requireAdmin();
        if ($_SESSION['admin_role'] !== 'superadmin') {
            Response::error('Superadmin access required.', 403);
            exit;
        }
        return (int)$_SESSION['admin_id'];
    }

    public static function adminId(): ?int {
        return isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    }

    public static function adminRole(): ?string {
        return $_SESSION['admin_role'] ?? null;
    }

    // ── CSRF ────────────────────────────────────────────────

    public static function generateCsrf(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = generateToken(32);
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    public static function validateCsrf(string $token): bool {
        return isset($_SESSION[CSRF_TOKEN_NAME]) &&
               hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }
}
