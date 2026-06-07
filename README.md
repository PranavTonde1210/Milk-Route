# MilkRoute — Deployment Guide (InfinityFree Shared Hosting)

## Project Structure
```
milkroute/
├── config/
│   └── config.php          ← Edit DB & Brevo credentials here
├── core/
│   ├── Bootstrap.php       ← App bootstrap & helpers
│   ├── Database.php        ← PDO singleton
│   ├── Auth.php            ← Session auth (customer + admin)
│   ├── Mailer.php          ← Brevo email (verify + reset only)
│   └── Response.php        ← JSON response helper
├── models/
│   ├── CustomerModel.php
│   ├── DeliveryModel.php
│   ├── PaymentModel.php
│   └── Models.php          ← Product, Subscription, Notification, Admin
├── api/
│   ├── customer/
│   │   ├── auth.php        ← /api/customer/auth.php
│   │   └── dashboard.php   ← /api/customer/dashboard.php
│   └── admin/
│       ├── auth.php        ← /api/admin/auth.php
│       ├── customers.php
│       ├── deliveries.php
│       ├── payments.php
│       ├── products.php
│       └── reports.php
├── customer/
│   ├── verify-email.php    ← Email verification landing page
│   └── reset-password.php  ← Password reset page
├── admin/
│   ├── index.html          ← Admin SPA
│   ├── panel.php           ← Admin entry point (secret URL)
│   └── .htaccess           ← Blocks direct /admin access
├── public/
│   └── index.html          ← Customer SPA
├── cron/
│   └── daily.php           ← Daily cron job
├── install/
│   └── schema.sql          ← Database schema
├── logs/                   ← Auto-created, write-protected
├── .htaccess               ← Root security rules
└── index.php               ← Root entry point
```

---

## Step 1: Create Database

1. Log in to **InfinityFree cPanel**
2. Go to **MySQL Databases**
3. Create a new database and user, assign all privileges
4. Go to **phpMyAdmin**
5. Select your database
6. Click **Import** → choose `install/schema.sql` → click Go

---

## Step 2: Configure the App

Edit `config/config.php`:

```php
define('DB_HOST', 'localhost');       // Usually localhost on InfinityFree
define('DB_NAME', 'epiz_xxxx_milkroute'); // Your DB name
define('DB_USER', 'epiz_xxxx_user');       // Your DB user
define('DB_PASS', 'your_db_password');

define('APP_URL',  'https://yourdomain.com');  // No trailing slash
define('ADMIN_PATH', 'manage-panel-xk92');     // Change to something secret!

define('BREVO_API_KEY', 'xkeysib-your-key-here');
define('MAIL_FROM_EMAIL', 'noreply@yourdomain.com');
```

---

## Step 3: Upload Files

**Option A — File Manager (cPanel):**
1. Zip the entire `milkroute/` folder contents (not the folder itself)
2. Open cPanel → File Manager → `htdocs/` (public root)
3. Upload the zip → Extract here

**Option B — FTP:**
1. Connect with FileZilla to your InfinityFree FTP
2. Upload all files from `milkroute/` into `htdocs/`

**Final folder should look like:**
```
htdocs/
├── config/
├── core/
├── models/
├── api/
├── customer/
├── admin/
├── public/
├── cron/
├── install/
├── .htaccess
├── index.php
└── ...
```

---

## Step 4: Set Up the Cron Job

In cPanel → **Cron Jobs**, add:

```
0 0 * * *    php /home/username/htdocs/cron/daily.php
```

Or if CLI isn't available, use a URL-based cron (free services like cron-job.org):
```
URL: https://yourdomain.com/cron/daily.php?key=CHANGE_THIS_CRON_SECRET_KEY
Time: Daily at midnight
```

> **Important:** Change `CHANGE_THIS_CRON_SECRET_KEY` in `cron/daily.php` to a random string.

---

## Step 5: Access the App

| URL | Purpose |
|-----|---------|
| `yourdomain.com/` | Customer portal (public) |
| `yourdomain.com/manage-panel-xk92/` | Admin panel (secret URL) |
| `yourdomain.com/customer/verify-email.php?token=...` | Email verification |
| `yourdomain.com/customer/reset-password.php?token=...` | Password reset |

**Default Admin Login:**
- Email: `admin@milkroute.com`
- Password: `password` ← **Change this immediately after first login!**

---

## Step 6: Get Brevo API Key

1. Sign up at [brevo.com](https://www.brevo.com)
2. Go to **Settings → SMTP & API → API Keys**
3. Create a new key and paste it in `config.php`
4. Add and verify your sender email domain in Brevo

---

## Security Checklist

- [ ] Change `ADMIN_PATH` to something random in `config.php`
- [ ] Change the cron secret key in `cron/daily.php`
- [ ] Change `JWT_SECRET` to a random 64-char string in `config.php`
- [ ] Change default admin password immediately after login
- [ ] Delete `install/` folder after importing schema
- [ ] Ensure `logs/` is not web-accessible (.htaccess handles this)
- [ ] Enable HTTPS (InfinityFree provides free SSL via SSL/TLS in cPanel)

---

## How Admin ↔ Customer Sync Works

| Admin Action | Customer Effect |
|---|---|
| Mark delivery → Delivered | Customer Home shows ✅ Delivered status |
| Mark delivery → Not Delivered | Customer sees issue, notification sent |
| Set new price | All pending deliveries auto-updated, bills recalculated, customers notified |
| Record payment | Customer bill balance updated, notification sent |
| Bulk mark all delivered | All pending customer deliveries updated simultaneously |
| Send broadcast notification | All customers see it in notification panel |
| Toggle customer active | Customer cannot log in if inactive |

---

## API Reference (Quick)

**Customer APIs** (require customer session):
- `GET  /api/customer/auth.php?action=me`
- `POST /api/customer/auth.php?action=login`
- `POST /api/customer/auth.php?action=register`
- `POST /api/customer/auth.php?action=forgot-password`
- `POST /api/customer/auth.php?action=reset-password`
- `GET  /api/customer/dashboard.php?action=home`
- `POST /api/customer/dashboard.php?action=update-qty`
- `POST /api/customer/dashboard.php?action=skip`
- `POST /api/customer/dashboard.php?action=subscription-save`
- `POST /api/customer/dashboard.php?action=subscription-remove`
- `GET  /api/customer/dashboard.php?action=payment`
- `GET  /api/customer/dashboard.php?action=calendar`
- `GET  /api/customer/dashboard.php?action=notifications`

**Admin APIs** (require admin session):
- `POST /api/admin/auth.php?action=login`
- `GET  /api/admin/customers.php?action=list`
- `POST /api/admin/deliveries.php?action=mark`
- `POST /api/admin/deliveries.php?action=bulk-mark`
- `POST /api/admin/payments.php?action=record`
- `POST /api/admin/products.php?action=set-price`
- `GET  /api/admin/reports.php?action=dashboard`
