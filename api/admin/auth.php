<?php
require_once __DIR__ . '/../../core/Bootstrap.php';

$action = $_GET['action'] ?? '';
$data   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    case 'login':
        if (empty($data['email']) || empty($data['password'])) {
            Response::error('Email and password required.');
        }
        $am    = new AdminModel();
        $admin = $am->findByEmail(strtolower(trim($data['email'])));
        if (!$admin || !verifyPassword($data['password'], $admin['password'])) {
            // Rate-limit hint: in production add failed-attempt tracking
            Response::error('Invalid credentials.', 401);
        }
        Auth::loginAdmin($admin);
        Response::success([
            'id'   => $admin['id'],
            'name' => $admin['name'],
            'role' => $admin['role'],
        ], 'Admin login successful.');
        break;

    case 'logout':
        Auth::logoutAdmin();
        Response::success([], 'Logged out.');
        break;

    case 'me':
        if (!Auth::isAdminLoggedIn()) Response::error('Not logged in.', 401);
        $am = new AdminModel();
        Response::success($am->findById(Auth::adminId()));
        break;

    case 'change-password':
        $adminId = Auth::requireAdmin();
        $db      = DB::getInstance();
        $admin   = $db->fetchOne('SELECT * FROM admins WHERE id = ?', [$adminId]);
        if (!verifyPassword($data['current_password'] ?? '', $admin['password'])) {
            Response::error('Current password incorrect.', 403);
        }
        if (strlen($data['new_password'] ?? '') < 8) Response::error('Minimum 8 characters.');
        $db->run('UPDATE admins SET password = ? WHERE id = ?',
            [hashPassword($data['new_password']), $adminId]);
        Response::success([], 'Password updated.');
        break;

    default:
        Response::error('Unknown action.', 404);
}
