<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$action = $_GET['action'] ?? '';
$data   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ── Register (Step 5 submit) ─────────────────────────────
    case 'register':
        $required = ['name','email','mobile','password','wing','flat_number','delivery_pattern'];
        foreach ($required as $f) {
            if (empty($data[$f])) Response::error("Field '$f' is required.");
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) Response::error('Invalid email address.');
        if (strlen($data['password']) < 8) Response::error('Password must be at least 8 characters.');

        $cm = new CustomerModel();
        if ($cm->findByEmail($data['email'])) Response::error('Email already registered.', 409);

        $token = generateToken();
        $customerId = $cm->create([
            'name'             => sanitize($data['name']),
            'email'            => strtolower(trim($data['email'])),
            'mobile'           => sanitize($data['mobile']),
            'password'         => $data['password'],
            'wing'             => sanitize($data['wing']),
            'flat_number'      => sanitize($data['flat_number']),
            'delivery_pattern' => in_array($data['delivery_pattern'], ['daily','alternate']) ? $data['delivery_pattern'] : 'daily',
            'alternate_start'  => $data['delivery_pattern'] === 'alternate' ? today() : null,
            'verify_token'     => $token,
        ]);

        // Save subscriptions
        if (!empty($data['subscriptions']) && is_array($data['subscriptions'])) {
            $sm = new SubscriptionModel();
            foreach ($data['subscriptions'] as $sub) {
                if (!empty($sub['product_id']) && !empty($sub['qty']) && $sub['qty'] > 0) {
                    $sm->upsert($customerId, (int)$sub['product_id'], (float)$sub['qty']);
                }
            }
        }

        // Send verification email
        $customer = $cm->findById($customerId);
        Mailer::sendVerification($customer['email'], $customer['name'], $token);

        Response::success(['customer_id' => $customerId],
            'Account created! Please check your email to verify your account.', 201);
        break;

    // ── Verify Email ──────────────────────────────────────────
    case 'verify-email':
        $token = $data['token'] ?? $_GET['token'] ?? '';
        if (!$token) Response::error('Verification token required.');
        $cm = new CustomerModel();
        if ($cm->verifyEmail($token)) {
            Response::success([], 'Email verified successfully! You can now login.');
        } else {
            Response::error('Invalid or expired verification link.', 400);
        }
        break;

    // ── Login ────────────────────────────────────────────────
    case 'login':
        if (empty($data['email']) || empty($data['password'])) {
            Response::error('Email and password are required.');
        }
        $cm = new CustomerModel();
        $customer = $cm->findByEmail(strtolower(trim($data['email'])));
        if (!$customer || !verifyPassword($data['password'], $customer['password'])) {
            Response::error('Invalid email or password.', 401);
        }
        if (!$customer['email_verified']) {
            Response::error('Please verify your email first. Check your inbox.', 403);
        }
        if (!$customer['is_active']) {
            Response::error('Your account has been deactivated. Contact support.', 403);
        }
        Auth::loginCustomer($customer);
        Response::success([
            'id'   => $customer['id'],
            'name' => $customer['name'],
            'wing' => $customer['wing'],
            'flat' => $customer['flat_number'],
        ], 'Login successful.');
        break;

    // ── Logout ───────────────────────────────────────────────
    case 'logout':
        Auth::logoutCustomer();
        Response::success([], 'Logged out.');
        break;

    // ── Forgot Password ───────────────────────────────────────
    case 'forgot-password':
        if (empty($data['email'])) Response::error('Email is required.');
        $cm = new CustomerModel();
        $customer = $cm->findByEmail(strtolower(trim($data['email'])));
        // Always return success to prevent email enumeration
        if ($customer && $customer['email_verified']) {
            $token = generateToken();
            $cm->setResetToken($customer['id'], $token);
            Mailer::sendPasswordReset($customer['email'], $customer['name'], $token);
        }
        Response::success([], 'If that email exists, a reset link has been sent.');
        break;

    // ── Reset Password ────────────────────────────────────────
    case 'reset-password':
        if (empty($data['token']) || empty($data['password'])) {
            Response::error('Token and new password required.');
        }
        if (strlen($data['password']) < 8) Response::error('Password must be at least 8 characters.');
        $cm = new CustomerModel();
        $customer = $cm->findByResetToken($data['token']);
        if (!$customer) Response::error('Invalid or expired reset link.', 400);
        $cm->updatePassword($customer['id'], $data['password']);
        Response::success([], 'Password reset successful. You can now login.');
        break;

    // ── Check Auth Status ─────────────────────────────────────
    case 'me':
        if (!Auth::isCustomerLoggedIn()) Response::error('Not logged in.', 401);
        $cm = new CustomerModel();
        $c  = $cm->findById(Auth::customerId());
        unset($c['password'], $c['reset_token'], $c['email_verify_token']);
        Response::success($c);
        break;

    default:
        Response::error('Unknown action.', 404);
}
