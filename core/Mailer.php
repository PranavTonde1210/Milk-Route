<?php
class Mailer {

    private static function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
        $payload = [
            'sender'     => ['email' => MAIL_FROM_EMAIL, 'name' => MAIL_FROM_NAME],
            'to'         => [['email' => $toEmail, 'name' => $toName]],
            'subject'    => $subject,
            'htmlContent'=> $htmlBody,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . BREVO_API_KEY,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode >= 200 && $httpCode < 300;
    }

    private static function baseTemplate(string $title, string $content): string {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">
        <style>body{margin:0;padding:0;background:#0a1a0f;font-family:Arial,sans-serif;}
        .wrap{max-width:520px;margin:32px auto;background:#0f2317;border-radius:16px;overflow:hidden;}
        .hdr{background:#22c55e;padding:24px;text-align:center;}
        .hdr h1{color:#fff;margin:0;font-size:22px;}
        .body{padding:28px 32px;color:#e8f5eb;}
        .body p{line-height:1.7;color:#a3c9a8;font-size:14px;}
        .btn{display:inline-block;background:#22c55e;color:#fff!important;text-decoration:none;
             padding:13px 28px;border-radius:10px;font-weight:bold;margin:16px 0;font-size:15px;}
        .footer{text-align:center;padding:16px;color:#5a8c60;font-size:12px;}
        .code{background:#152e1d;border:1px solid #1f4028;border-radius:8px;padding:16px;
              font-size:24px;font-weight:bold;text-align:center;letter-spacing:6px;color:#22c55e;margin:16px 0;}
        </style></head><body>
        <div class="wrap">
          <div class="hdr"><h1>🥛 ' . APP_NAME . '</h1></div>
          <div class="body"><h2 style="color:#e8f5eb;margin-top:0;">' . $title . '</h2>' . $content . '</div>
          <div class="footer">© ' . date('Y') . ' ' . APP_NAME . ' · Fresh dairy, delivered daily</div>
        </div></body></html>';
    }

    public static function sendVerification(string $email, string $name, string $token): bool {
        $link = APP_URL . '/customer/verify-email.php?token=' . $token;
        $content = '<p>Hi ' . htmlspecialchars($name) . ', welcome to ' . APP_NAME . '!</p>
        <p>Please verify your email address to activate your account and start receiving milk deliveries.</p>
        <p style="text-align:center;"><a href="' . $link . '" class="btn">✓ Verify Email Address</a></p>
        <p>Or copy this link into your browser:<br><small style="color:#5a8c60;word-break:break-all;">' . $link . '</small></p>
        <p>This link expires in 24 hours. If you did not sign up, ignore this email.</p>';
        return self::send($email, $name, 'Verify your ' . APP_NAME . ' email', self::baseTemplate('Verify Your Email', $content));
    }

    public static function sendPasswordReset(string $email, string $name, string $token): bool {
        $link = APP_URL . '/customer/reset-password.php?token=' . $token;
        $content = '<p>Hi ' . htmlspecialchars($name) . ',</p>
        <p>We received a request to reset your password. Click the button below to create a new password.</p>
        <p style="text-align:center;"><a href="' . $link . '" class="btn">🔐 Reset Password</a></p>
        <p>Or copy this link:<br><small style="color:#5a8c60;word-break:break-all;">' . $link . '</small></p>
        <p>This link expires in <strong style="color:#e8f5eb;">1 hour</strong>. If you did not request a reset, your account is safe — ignore this email.</p>';
        return self::send($email, $name, 'Reset your ' . APP_NAME . ' password', self::baseTemplate('Reset Your Password', $content));
    }
}
