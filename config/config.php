<?php
// ============================================================
// MilkRoute Configuration
// ============================================================
// EDIT THESE BEFORE UPLOADING TO YOUR SERVER

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// App
define('APP_NAME', 'MilkRoute');
define('APP_URL', 'https://yourdomain.com'); // No trailing slash
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_VERSION', '1.0.0');

// Admin panel URL segment (keep secret, change this)
define('ADMIN_PATH', 'manage-panel-xk92');

// Brevo (Sendinblue) - for email verification & password reset only
define('BREVO_API_KEY', 'your-brevo-api-key-here');
define('MAIL_FROM_EMAIL', 'noreply@yourdomain.com');
define('MAIL_FROM_NAME', 'MilkRoute');

// Security
define('SESSION_NAME', 'milkroute_sess');
define('CSRF_TOKEN_NAME', 'mr_csrf');
define('JWT_SECRET', 'change-this-to-random-64-char-string');

// Session duration (seconds)
define('CUSTOMER_SESSION_DURATION', 86400 * 30); // 30 days
define('ADMIN_SESSION_DURATION', 86400 * 1);     // 1 day

// Email token expiry (seconds)
define('EMAIL_VERIFY_EXPIRY', 86400);   // 24 hours
define('RESET_TOKEN_EXPIRY', 3600);     // 1 hour

// Delivery defaults
define('DEFAULT_DELIVERY_TIME', '07:00:00');
define('DELIVERY_CUTOFF_HOUR', 20); // 8 PM - after this, changes go to next day

date_default_timezone_set(APP_TIMEZONE);
