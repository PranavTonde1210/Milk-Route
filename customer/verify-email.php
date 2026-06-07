<?php
// customer/verify-email.php
require_once __DIR__ . '/../core/Bootstrap.php';

$token = sanitize($_GET['token'] ?? '');
$cm    = new CustomerModel();
$ok    = $token && $cm->verifyEmail($token);
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Email Verification — MilkRoute</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;background:#0a1a0f;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.box{background:#0f2317;border-radius:16px;padding:40px 32px;text-align:center;max-width:400px;width:90%;border:1px solid #1a3d22;}
.icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;}
h2{color:#e8f5eb;margin:0 0 8px;}p{color:#a3c9a8;font-size:14px;line-height:1.6;}
.btn{display:inline-block;background:#22c55e;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:bold;margin-top:20px;}
</style></head><body>
<div class="box">
<?php if ($ok): ?>
  <div class="icon" style="background:#0d3320;">✅</div>
  <h2 style="color:#22c55e;">Email Verified!</h2>
  <p>Your account is now active. You can log in to MilkRoute and start managing your milk subscription.</p>
  <a href="/" class="btn">Go to App →</a>
<?php else: ?>
  <div class="icon" style="background:#2a0d0d;">❌</div>
  <h2 style="color:#ef4444;">Invalid Link</h2>
  <p>This verification link is invalid or has expired (links expire after 24 hours). Please register again or contact support.</p>
  <a href="/" class="btn" style="background:#ef4444;">Back to Home</a>
<?php endif; ?>
</div></body></html>
