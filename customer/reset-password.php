<?php
// customer/reset-password.php
require_once __DIR__ . '/../core/Bootstrap.php';

$token    = sanitize($_GET['token'] ?? '');
$cm       = new CustomerModel();
$customer = $token ? $cm->findByResetToken($token) : null;
$success  = false;
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';
    if (strlen($newPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'Passwords do not match.';
    } elseif (!$customer) {
        $error = 'Invalid or expired reset link.';
    } else {
        $cm->updatePassword($customer['id'], $newPass);
        $success = true;
    }
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Reset Password — MilkRoute</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0a1a0f;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
.box{background:#0f2317;border-radius:16px;padding:32px;max-width:400px;width:100%;border:1px solid #1a3d22;}
.logo{text-align:center;margin-bottom:24px;}
.logo-icon{width:48px;height:48px;border-radius:14px;background:#22c55e;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:22px;}
h2{color:#e8f5eb;font-size:18px;margin-bottom:4px;text-align:center;}
p{color:#a3c9a8;font-size:13px;text-align:center;margin-bottom:20px;}
.form-group{margin-bottom:14px;}
label{display:block;font-size:11px;color:#5a8c60;margin-bottom:5px;}
input{width:100%;background:#0a1a0f;border:1px solid #1a3d22;border-radius:8px;padding:10px 12px;color:#e8f5eb;font-size:13px;outline:none;}
input:focus{border-color:#15803d;}
.btn{width:100%;background:#22c55e;color:#fff;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:bold;cursor:pointer;margin-top:8px;}
.btn:hover{background:#16a34a;}
.error{background:#2a0d0d;color:#ef4444;border-radius:8px;padding:10px 12px;font-size:12px;margin-bottom:14px;}
.success-box{text-align:center;}
.icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;}
a.link{display:inline-block;background:#22c55e;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:bold;margin-top:20px;}
</style></head>
<body>
<div class="box">
  <div class="logo">
    <div class="logo-icon">🥛</div>
    <h2>MilkRoute</h2>
  </div>

<?php if ($success): ?>
  <div class="success-box">
    <div class="icon" style="background:#0d3320;">🔐</div>
    <h2 style="color:#22c55e;">Password Reset!</h2>
    <p>Your password has been updated. You can now log in with your new password.</p>
    <a href="/" class="link">Go to Login →</a>
  </div>

<?php elseif (!$customer): ?>
  <div class="success-box">
    <div class="icon" style="background:#2a0d0d;">❌</div>
    <h2 style="color:#ef4444;">Invalid Link</h2>
    <p>This reset link is invalid or has expired (links expire after 1 hour). Please request a new reset link.</p>
    <a href="/" class="link" style="background:#ef4444;">Back to Home</a>
  </div>

<?php else: ?>
  <h2>Create New Password</h2>
  <p style="margin-bottom:20px;">Enter your new password below.</p>
  <?php if ($error): ?>
    <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <div class="form-group">
      <label>New Password</label>
      <input type="password" name="new_password" placeholder="Minimum 8 characters" required minlength="8">
    </div>
    <div class="form-group">
      <label>Confirm New Password</label>
      <input type="password" name="confirm_password" placeholder="Repeat password" required>
    </div>
    <button type="submit" class="btn">Reset Password</button>
  </form>
<?php endif; ?>
</div>
</body></html>
