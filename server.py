#!/usr/bin/env python3
"""
MilkRoute Live Server - Flask + SQLite
Runs the full customer + admin SPA with real backend APIs
"""
import sqlite3, hashlib, secrets, json, os, time
from datetime import datetime, date, timedelta
from functools import wraps
from flask import Flask, request, jsonify, session, send_from_directory, g

app = Flask(__name__, static_folder='static')
app.secret_key = secrets.token_hex(32)
app.config['SESSION_TYPE'] = 'filesystem'

DB_PATH = '/home/claude/milkroute_live/db/milkroute.db'

# ─────────────────────────────────────────────────────────
# DATABASE
# ─────────────────────────────────────────────────────────
def get_db():
    if 'db' not in g:
        g.db = sqlite3.connect(DB_PATH)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA journal_mode=WAL")
        g.db.execute("PRAGMA foreign_keys=ON")
    return g.db

@app.teardown_appcontext
def close_db(e=None):
    db = g.pop('db', None)
    if db: db.close()

def q(sql, params=()):
    return get_db().execute(sql, params)

def fetchall(sql, params=()):
    return [dict(r) for r in q(sql, params).fetchall()]

def fetchone(sql, params=()):
    r = q(sql, params).fetchone()
    return dict(r) if r else None

def fetchval(sql, params=()):
    r = q(sql, params).fetchone()
    return r[0] if r else None

def run(sql, params=()):
    db = get_db()
    cur = db.execute(sql, params)
    db.commit()
    return cur.lastrowid

def today():
    return date.today().isoformat()

def now():
    return datetime.now().isoformat(sep=' ', timespec='seconds')

def hash_pw(pw):
    return hashlib.sha256(pw.encode()).hexdigest()

def verify_pw(pw, h):
    return hash_pw(pw) == h

def current_month():
    return date.today().month

def current_year():
    return date.today().year

def get_price(product_id, on_date):
    return fetchval(
        "SELECT price_per_litre FROM milk_prices WHERE product_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1",
        (product_id, on_date)
    )

def is_alternate_delivery(alternate_start, check_date):
    if not alternate_start:
        return True
    start = date.fromisoformat(alternate_start)
    chk   = date.fromisoformat(check_date) if isinstance(check_date, str) else check_date
    return (chk - start).days % 2 == 0

# ─────────────────────────────────────────────────────────
# SCHEMA + SEED
# ─────────────────────────────────────────────────────────
SCHEMA = """
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, email TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL, role TEXT DEFAULT 'admin',
    created_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, email TEXT UNIQUE NOT NULL,
    mobile TEXT NOT NULL, password TEXT NOT NULL,
    wing TEXT NOT NULL, flat_number TEXT NOT NULL,
    delivery_pattern TEXT DEFAULT 'daily',
    alternate_start TEXT,
    is_active INTEGER DEFAULT 1,
    email_verified INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS milk_companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, tagline TEXT,
    logo_color TEXT DEFAULT '#22c55e',
    is_active INTEGER DEFAULT 1
);
CREATE TABLE IF NOT EXISTS milk_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL, name TEXT NOT NULL,
    description TEXT, is_active INTEGER DEFAULT 1,
    FOREIGN KEY(company_id) REFERENCES milk_companies(id)
);
CREATE TABLE IF NOT EXISTS milk_prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price_per_litre REAL NOT NULL,
    effective_from TEXT NOT NULL,
    effective_to TEXT,
    created_by INTEGER,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY(product_id) REFERENCES milk_products(id)
);
CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
    default_qty REAL DEFAULT 1.0, is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(customer_id, product_id),
    FOREIGN KEY(customer_id) REFERENCES customers(id),
    FOREIGN KEY(product_id) REFERENCES milk_products(id)
);
CREATE TABLE IF NOT EXISTS daily_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
    delivery_date TEXT NOT NULL,
    qty_ordered REAL DEFAULT 0, qty_delivered REAL,
    status TEXT DEFAULT 'pending',
    skip_reason TEXT, price_at_delivery REAL,
    delivery_time TEXT, marked_by TEXT DEFAULT 'system',
    notes TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    UNIQUE(customer_id, product_id, delivery_date),
    FOREIGN KEY(customer_id) REFERENCES customers(id),
    FOREIGN KEY(product_id) REFERENCES milk_products(id)
);
CREATE TABLE IF NOT EXISTS skip_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    skip_date_start TEXT NOT NULL, skip_date_end TEXT NOT NULL,
    reason TEXT DEFAULT 'customer_request',
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY(customer_id) REFERENCES customers(id)
);
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    month INTEGER NOT NULL, year INTEGER NOT NULL,
    total_amount REAL DEFAULT 0,
    paid_amount REAL DEFAULT 0, balance REAL DEFAULT 0,
    payment_method TEXT DEFAULT 'cash',
    payment_date TEXT, status TEXT DEFAULT 'unpaid',
    transaction_ref TEXT, notes TEXT, recorded_by INTEGER,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    UNIQUE(customer_id, month, year),
    FOREIGN KEY(customer_id) REFERENCES customers(id)
);
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER,
    title TEXT NOT NULL, message TEXT NOT NULL,
    type TEXT DEFAULT 'general', is_read INTEGER DEFAULT 0,
    created_by INTEGER,
    created_at TEXT DEFAULT (datetime('now'))
);
"""

def seed():
    db = get_db()
    # Admins
    if not fetchval("SELECT id FROM admins WHERE email='admin@milkroute.com'"):
        run("INSERT INTO admins(name,email,password,role) VALUES(?,?,?,?)",
            ('Super Admin','admin@milkroute.com',hash_pw('admin123'),'superadmin'))

    # Companies
    companies = [
        (1,'Amul','Goodness of pure milk','#0066b2'),
        (2,'Chitale','Fresh & healthy dairy','#e31837'),
        (3,'Gokul','Pure by tradition','#f97316'),
        (4,'Govardhan','Simply authentic','#facc15'),
    ]
    for co in companies:
        if not fetchval("SELECT id FROM milk_companies WHERE id=?", (co[0],)):
            run("INSERT INTO milk_companies(id,name,tagline,logo_color) VALUES(?,?,?,?)", co)

    # Products
    products = [
        (1,1,'Amul Taaza','Standardised toned milk'),
        (2,1,'Amul Gold','Full cream milk'),
        (3,1,'Amul Cow Milk','Pure cow milk'),
        (4,1,'Amul Slim & Trim','Double toned milk'),
        (5,2,'Chitale Buffalo','Rich buffalo milk'),
        (6,2,'Chitale Cow Milk','Fresh cow milk'),
        (7,3,'Gokul Standard','Pure standard milk'),
        (8,4,'Govardhan A2','A2 milk'),
    ]
    for p in products:
        if not fetchval("SELECT id FROM milk_products WHERE id=?", (p[0],)):
            run("INSERT INTO milk_products(id,company_id,name,description) VALUES(?,?,?,?)", p)

    # Prices
    prices = [(1,32),(2,67),(3,60),(4,65),(5,74),(6,64),(7,28),(8,80)]
    for pid, price in prices:
        if not fetchval("SELECT id FROM milk_prices WHERE product_id=?", (pid,)):
            run("INSERT INTO milk_prices(product_id,price_per_litre,effective_from) VALUES(?,?,?)",
                (pid, price, '2026-01-01'))

    # Demo customers
    demo_customers = [
        ('Rahul Sharma','rahul@demo.com','9876543210','I','1104','alternate','2026-06-01'),
        ('Pranav Tonde','pranav@demo.com','9876543211','A','101','daily','2026-06-02'),
        ('Sneha Patil','sneha@demo.com','9876543212','B','204','daily','2026-06-03'),
        ('Amit Desai','amit@demo.com','9876543213','C','301','alternate','2026-06-04'),
        ('Priya Shah','priya@demo.com','9876543214','A','502','daily','2026-06-05'),
        ('Rohan More','rohan@demo.com','9876543215','D','102','daily','2026-06-01'),
    ]
    for c in demo_customers:
        if not fetchval("SELECT id FROM customers WHERE email=?", (c[1],)):
            run("""INSERT INTO customers(name,email,mobile,wing,flat_number,delivery_pattern,
                   alternate_start,password,email_verified)
                   VALUES(?,?,?,?,?,?,?,?,1)""",
                (c[0],c[1],c[2],c[3],c[4],c[5],
                 c[6] if c[5]=='alternate' else None, hash_pw('demo123')))

    # Demo subscriptions
    subs = [
        (1,1,1.5),(1,5,0.5),  # Rahul: Amul Taaza 1.5L + Chitale Buffalo 0.5L
        (2,1,1.0),(2,2,0.5),  # Pranav: Amul Taaza 1L + Amul Gold 0.5L
        (3,2,1.0),             # Sneha: Amul Gold 1L
        (4,1,1.0),             # Amit: Amul Taaza 1L
        (5,5,1.0),             # Priya: Chitale Buffalo 1L
        (6,1,1.0),             # Rohan: Amul Taaza 1L
    ]
    for s in subs:
        try:
            run("INSERT OR IGNORE INTO subscriptions(customer_id,product_id,default_qty) VALUES(?,?,?)", s)
        except: pass

    # Generate last 7 days + today deliveries for all customers
    customers = fetchall("SELECT * FROM customers WHERE is_active=1")
    for offset in range(-7, 1):
        d = (date.today() + timedelta(days=offset)).isoformat()
        for cust in customers:
            generate_deliveries_for(cust['id'], d)

    # Mark past deliveries as delivered
    run("""UPDATE daily_deliveries SET status='delivered',
           qty_delivered=qty_ordered, delivery_time='07:30:00', marked_by='system'
           WHERE delivery_date < ? AND status='pending'""", (today(),))

    db.commit()
    print("✅ Database seeded")

def generate_deliveries_for(customer_id, for_date, force_refresh=False):
    cust = fetchone("SELECT * FROM customers WHERE id=?", (customer_id,))
    if not cust: return

    # Check skips
    skip = fetchone(
        "SELECT id FROM skip_requests WHERE customer_id=? AND skip_date_start<=? AND skip_date_end>=?",
        (customer_id, for_date, for_date))

    is_alt_skip = False
    if cust['delivery_pattern'] == 'alternate' and cust['alternate_start']:
        is_alt_skip = not is_alternate_delivery(cust['alternate_start'], for_date)

    subs = fetchall(
        "SELECT * FROM subscriptions WHERE customer_id=? AND is_active=1", (customer_id,))

    db = get_db()

    # Get existing pending rows so we can refresh qty if subs changed
    existing_pending = {
        r['product_id']: r for r in fetchall(
            "SELECT * FROM daily_deliveries WHERE customer_id=? AND delivery_date=? AND status='pending'",
            (customer_id, for_date))
    }
    active_product_ids = {s['product_id'] for s in subs}

    # Remove pending rows for products no longer subscribed
    for pid in list(existing_pending.keys()):
        if pid not in active_product_ids:
            db.execute("DELETE FROM daily_deliveries WHERE customer_id=? AND product_id=? AND delivery_date=? AND status='pending'",
                       (customer_id, pid, for_date))

    for sub in subs:
        price = get_price(sub['product_id'], for_date) or 0
        status = 'skipped' if (skip or is_alt_skip) else 'pending'
        skip_reason = 'customer_request' if skip else ('alternate_day' if is_alt_skip else None)
        qty = 0 if status == 'skipped' else sub['default_qty']
        try:
            # If already exists as pending, update qty to match current default
            if sub['product_id'] in existing_pending and status == 'pending':
                db.execute(
                    "UPDATE daily_deliveries SET qty_ordered=?,price_at_delivery=? WHERE customer_id=? AND product_id=? AND delivery_date=? AND status='pending'",
                    (qty, price, customer_id, sub['product_id'], for_date))
            else:
                db.execute("""INSERT OR IGNORE INTO daily_deliveries
                    (customer_id,product_id,delivery_date,qty_ordered,status,skip_reason,price_at_delivery,marked_by)
                    VALUES(?,?,?,?,?,?,?,?)""",
                    (customer_id, sub['product_id'], for_date, qty, status, skip_reason, price, 'system'))
        except: pass
    db.commit()

def sync_payment(customer_id, month, year):
    rows = fetchall("""
        SELECT dd.qty_delivered, dd.price_at_delivery FROM daily_deliveries dd
        WHERE dd.customer_id=? AND strftime('%m',delivery_date)=? AND strftime('%Y',delivery_date)=?
        AND dd.status='delivered'""",
        (customer_id, str(month).zfill(2), str(year)))
    total = sum((r['qty_delivered'] or 0) * (r['price_at_delivery'] or 0) for r in rows)

    # Previous month balance
    pm = 12 if month == 1 else month - 1
    py = year - 1 if month == 1 else year
    prev_bal = fetchval(
        "SELECT balance FROM payments WHERE customer_id=? AND month=? AND year=?",
        (customer_id, pm, py)) or 0
    grand = round(total + prev_bal, 2)

    existing = fetchone("SELECT * FROM payments WHERE customer_id=? AND month=? AND year=?",
                        (customer_id, month, year))
    if existing:
        new_bal = max(0, grand - existing['paid_amount'])
        status = 'paid' if new_bal <= 0 else ('partial' if existing['paid_amount'] > 0 else 'unpaid')
        run("UPDATE payments SET total_amount=?,balance=?,status=? WHERE id=?",
            (grand, new_bal, status, existing['id']))
    else:
        run("""INSERT OR IGNORE INTO payments(customer_id,month,year,total_amount,paid_amount,balance,status)
               VALUES(?,?,?,?,0,?,?)""", (customer_id, month, year, grand, grand, 'unpaid'))

def add_notif(customer_id, title, message, ntype='general', created_by=None):
    run("INSERT INTO notifications(customer_id,title,message,type,created_by) VALUES(?,?,?,?,?)",
        (customer_id, title, message, ntype, created_by))

# ─────────────────────────────────────────────────────────
# AUTH HELPERS
# ─────────────────────────────────────────────────────────
def ok(data=None, msg='Success', code=200):
    return jsonify({'success': True, 'message': msg, 'data': data or {}}), code

def err(msg='Error', code=400):
    return jsonify({'success': False, 'message': msg}), code

def require_customer(f):
    @wraps(f)
    def decorated(*a, **kw):
        if not session.get('customer_id'):
            return err('Please login.', 401)
        return f(*a, **kw)
    return decorated

def require_admin(f):
    @wraps(f)
    def decorated(*a, **kw):
        if not session.get('admin_id'):
            return err('Admin access required.', 401)
        return f(*a, **kw)
    return decorated

# ─────────────────────────────────────────────────────────
# STATIC FILES
# ─────────────────────────────────────────────────────────
@app.route('/')
def index():
    return send_from_directory('static', 'index.html')

@app.route('/admin')
def admin_index():
    return send_from_directory('static', 'admin.html')

# ─────────────────────────────────────────────────────────
# CUSTOMER AUTH APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/customer/auth', methods=['GET','POST'])
def customer_auth():
    action = request.args.get('action','')
    data = request.get_json(silent=True) or {}

    if action == 'register':
        req = ['name','email','mobile','password','wing','flat_number','delivery_pattern']
        for f in req:
            if not data.get(f): return err(f'{f} is required.')
        if len(data['password']) < 6: return err('Password min 6 characters.')
        if fetchone("SELECT id FROM customers WHERE email=?", (data['email'].lower(),)):
            return err('Email already registered.', 409)
        token = secrets.token_hex(32)
        alt_start = today() if data['delivery_pattern'] == 'alternate' else None
        cid = run("""INSERT INTO customers(name,email,mobile,password,wing,flat_number,
                     delivery_pattern,alternate_start,email_verified)
                     VALUES(?,?,?,?,?,?,?,?,1)""",
                  (data['name'], data['email'].lower(), data['mobile'],
                   hash_pw(data['password']), data['wing'], data['flat_number'],
                   data['delivery_pattern'], alt_start))
        subs = data.get('subscriptions', [])
        for s in subs:
            if s.get('product_id') and s.get('qty', 0) > 0:
                run("INSERT OR IGNORE INTO subscriptions(customer_id,product_id,default_qty) VALUES(?,?,?)",
                    (cid, s['product_id'], s['qty']))
        generate_deliveries_for(cid, today())
        sync_payment(cid, current_month(), current_year())
        add_notif(cid, 'Welcome to MilkRoute!', f'Hi {data["name"]}, your account is ready. Enjoy fresh milk every day!', 'general')
        return ok({'customer_id': cid}, 'Account created! You can now login.', 201)

    if action == 'login':
        if not data.get('email') or not data.get('password'):
            return err('Email and password required.')
        cust = fetchone("SELECT * FROM customers WHERE email=?", (data['email'].lower(),))
        if not cust or not verify_pw(data['password'], cust['password']):
            return err('Invalid email or password.', 401)
        if not cust['is_active']: return err('Account deactivated.', 403)
        session['customer_id']   = cust['id']
        session['customer_name'] = cust['name']
        return ok({'id': cust['id'], 'name': cust['name'], 'wing': cust['wing'],
                   'flat_number': cust['flat_number']}, 'Login successful.')

    if action == 'logout':
        session.pop('customer_id', None)
        session.pop('customer_name', None)
        return ok({}, 'Logged out.')

    if action == 'me':
        cid = session.get('customer_id')
        if not cid: return err('Not logged in.', 401)
        c = fetchone("SELECT id,name,email,mobile,wing,flat_number,delivery_pattern,alternate_start,created_at FROM customers WHERE id=?", (cid,))
        return ok(c)

    if action == 'forgot-password':
        return ok({}, 'Reset link sent (email disabled in demo).')

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# CUSTOMER DASHBOARD APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/customer/dashboard', methods=['GET','POST'])
@require_customer
def customer_dashboard():
    action = request.args.get('action','')
    data   = request.get_json(silent=True) or {}
    cid    = session['customer_id']

    if action == 'home':
        cust = fetchone("SELECT id,name,email,wing,flat_number,delivery_pattern,alternate_start FROM customers WHERE id=?", (cid,))
        generate_deliveries_for(cid, today(), force_refresh=True)
        today_dels = fetchall("""
            SELECT dd.*,mp.name as product_name,mc.name as company_name
            FROM daily_deliveries dd
            JOIN milk_products mp ON mp.id=dd.product_id
            JOIN milk_companies mc ON mc.id=mp.company_id
            WHERE dd.customer_id=? AND dd.delivery_date=?""", (cid, today()))
        tmrw = (date.today() + timedelta(days=1)).isoformat()
        generate_deliveries_for(cid, tmrw, force_refresh=True)
        tmrw_dels = fetchall("""
            SELECT dd.*,mp.name as product_name,mc.name as company_name
            FROM daily_deliveries dd
            JOIN milk_products mp ON mp.id=dd.product_id
            JOIN milk_companies mc ON mc.id=mp.company_id
            WHERE dd.customer_id=? AND dd.delivery_date=?""", (cid, tmrw))
        sync_payment(cid, current_month(), current_year())
        payment = fetchone("SELECT * FROM payments WHERE customer_id=? AND month=? AND year=?",
                           (cid, current_month(), current_year())) or {'balance': 0}
        unread = fetchval("SELECT COUNT(*) FROM notifications WHERE (customer_id=? OR customer_id IS NULL) AND is_read=0", (cid,)) or 0
        is_alt_skip_today = False
        if cust['delivery_pattern'] == 'alternate' and cust['alternate_start']:
            is_alt_skip_today = not is_alternate_delivery(cust['alternate_start'], today())
        return ok({
            'customer': cust, 'today_deliveries': today_dels,
            'tomorrow_deliveries': tmrw_dels, 'unread_notifications': unread,
            'balance_due': payment.get('balance', 0),
            'today': today(), 'tomorrow': tmrw,
            'is_alt_skip_today': is_alt_skip_today,
        })


    if action == 'update-qty':
        pid = data.get('product_id'); qty = data.get('qty', 0)
        if not pid: return err('product_id required.')
        run("""UPDATE daily_deliveries SET qty_ordered=?,updated_at=?
               WHERE customer_id=? AND product_id=? AND delivery_date=? AND status='pending'""",
            (qty, now(), cid, pid, today()))
        return ok({}, 'Quantity updated.')

    if action == 'skip':
        stype = data.get('type', 'today')
        ds = data.get('date_start', today())
        de = data.get('date_end', today())
        if stype == 'today':
            ds = today(); de = today()
        if ds > de:
            return err('End date must be after start date.')
        run("INSERT INTO skip_requests(customer_id,skip_date_start,skip_date_end,reason) VALUES(?,?,?,?)",
            (cid, ds, de, 'customer_request'))
        run("""UPDATE daily_deliveries SET status='skipped',skip_reason='customer_request',qty_ordered=0
               WHERE customer_id=? AND delivery_date BETWEEN ? AND ? AND status='pending'""",
            (cid, ds, de))
        sync_payment(cid, current_month(), current_year())
        return ok({'date_start': ds, 'date_end': de}, f'Skip saved from {ds} to {de}.')

    if action == 'cancel-skip':
        d = data.get('date', today())
        run("DELETE FROM skip_requests WHERE customer_id=? AND skip_date_start<=? AND skip_date_end>=?", (cid, d, d))
        return ok({}, 'Skip cancelled.')

    if action == 'report-not-delivered':
        d = data.get('date', today())
        run("UPDATE daily_deliveries SET status='not_delivered',marked_by='customer' WHERE customer_id=? AND delivery_date=? AND status='delivered'", (cid, d))
        add_notif(cid, 'Issue Reported', 'Your not-delivered report has been submitted. Your distributor will follow up.', 'delivery')
        return ok({}, 'Issue reported.')

    if action == 'subscriptions':
        subs = fetchall("""
            SELECT s.*,mp.name as product_name,mc.name as company_name,
            (SELECT price_per_litre FROM milk_prices WHERE product_id=s.product_id AND effective_from<=? ORDER BY effective_from DESC LIMIT 1) as current_price
            FROM subscriptions s
            JOIN milk_products mp ON mp.id=s.product_id
            JOIN milk_companies mc ON mc.id=mp.company_id
            WHERE s.customer_id=? AND s.is_active=1""", (today(), cid))
        return ok(subs)

    if action == 'subscription-save':
        pid = data.get('product_id'); qty = data.get('qty', 0)
        if not pid: return err('product_id required.')
        run("INSERT OR REPLACE INTO subscriptions(customer_id,product_id,default_qty,is_active) VALUES(?,?,?,1)", (cid, pid, qty))
        # Refresh tomorrow's delivery with new qty
        tmrw = (date.today() + timedelta(days=1)).isoformat()
        generate_deliveries_for(cid, tmrw, force_refresh=True)
        return ok({}, 'Subscription updated.')

    if action == 'subscription-remove':
        pid = data.get('product_id')
        run("UPDATE subscriptions SET is_active=0 WHERE customer_id=? AND product_id=?", (cid, pid))
        # Remove tomorrow's pending delivery for this product
        tmrw = (date.today() + timedelta(days=1)).isoformat()
        run("DELETE FROM daily_deliveries WHERE customer_id=? AND product_id=? AND delivery_date>=? AND status='pending'",
            (cid, pid, today()))
        return ok({}, 'Removed.')

    if action == 'calendar':
        month = int(request.args.get('month', current_month()))
        year  = int(request.args.get('year', current_year()))
        rows = fetchall("""
            SELECT delivery_date, SUM(qty_delivered) as total_qty, status
            FROM daily_deliveries WHERE customer_id=?
            AND strftime('%m',delivery_date)=? AND strftime('%Y',delivery_date)=?
            GROUP BY delivery_date, status ORDER BY delivery_date""",
            (cid, str(month).zfill(2), str(year)))
        return ok(rows)

    if action == 'payment':
        month = int(request.args.get('month', current_month()))
        year  = int(request.args.get('year', current_year()))
        sync_payment(cid, month, year)
        payment = fetchone("SELECT * FROM payments WHERE customer_id=? AND month=? AND year=?", (cid, month, year))
        # Breakdown
        del_rows = fetchall("""
            SELECT dd.product_id,mp.name as product_name,SUM(dd.qty_delivered) as total_qty,
            SUM(dd.qty_delivered*dd.price_at_delivery) as total_amount
            FROM daily_deliveries dd JOIN milk_products mp ON mp.id=dd.product_id
            WHERE dd.customer_id=? AND strftime('%m',dd.delivery_date)=? AND strftime('%Y',dd.delivery_date)=?
            AND dd.status='delivered' GROUP BY dd.product_id""",
            (cid, str(month).zfill(2), str(year)))
        stats = fetchone("""
            SELECT SUM(status='delivered') as delivered_days, SUM(status='skipped') as skipped_days,
            SUM(CASE WHEN status='delivered' THEN qty_delivered ELSE 0 END) as total_qty
            FROM daily_deliveries WHERE customer_id=?
            AND strftime('%m',delivery_date)=? AND strftime('%Y',delivery_date)=?""",
            (cid, str(month).zfill(2), str(year))) or {}
        history = fetchall("SELECT * FROM payments WHERE customer_id=? ORDER BY year DESC, month DESC LIMIT 6", (cid,))
        return ok({'payment': payment, 'breakdown': {'product_summary': del_rows, 'grand_total': payment['total_amount'] if payment else 0,
                   'delivered_days': stats.get('delivered_days') or 0,
                   'skipped_days': stats.get('skipped_days') or 0,
                   'total_qty': stats.get('total_qty') or 0}, 'history': history})

    if action == 'notifications':
        notes = fetchall("SELECT * FROM notifications WHERE customer_id=? OR customer_id IS NULL ORDER BY created_at DESC LIMIT 30", (cid,))
        return ok(notes)

    if action == 'notification-read':
        nid = data.get('id')
        if nid: run("UPDATE notifications SET is_read=1 WHERE id=?", (nid,))
        else: run("UPDATE notifications SET is_read=1 WHERE customer_id=?", (cid,))
        return ok({}, 'Marked read.')

    if action == 'profile-update':
        run("UPDATE customers SET name=?,mobile=?,wing=?,flat_number=?,updated_at=? WHERE id=?",
            (data.get('name',''), data.get('mobile',''), data.get('wing',''), data.get('flat_number',''), now(), cid))
        return ok({}, 'Profile updated.')

    if action == 'change-password':
        cust = fetchone("SELECT password FROM customers WHERE id=?", (cid,))
        if not verify_pw(data.get('current_password',''), cust['password']): return err('Current password incorrect.', 403)
        if len(data.get('new_password','')) < 6: return err('Min 6 characters.')
        run("UPDATE customers SET password=? WHERE id=?", (hash_pw(data['new_password']), cid))
        return ok({}, 'Password changed.')

    if action == 'products':
        companies = fetchall("SELECT * FROM milk_companies WHERE is_active=1")
        products  = fetchall("""SELECT mp.*,mc.name as company_name,mc.logo_color,
            (SELECT price_per_litre FROM milk_prices WHERE product_id=mp.id AND effective_from<=? ORDER BY effective_from DESC LIMIT 1) as current_price
            FROM milk_products mp JOIN milk_companies mc ON mc.id=mp.company_id WHERE mp.is_active=1""", (today(),))
        return ok({'companies': companies, 'products': products})

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# ADMIN AUTH APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/admin/auth', methods=['GET','POST'])
def admin_auth():
    action = request.args.get('action','')
    data   = request.get_json(silent=True) or {}

    if action == 'login':
        admin = fetchone("SELECT * FROM admins WHERE email=?", (data.get('email','').lower(),))
        if not admin or not verify_pw(data.get('password',''), admin['password']):
            return err('Invalid credentials.', 401)
        session['admin_id']   = admin['id']
        session['admin_name'] = admin['name']
        session['admin_role'] = admin['role']
        return ok({'id': admin['id'], 'name': admin['name'], 'role': admin['role']}, 'Login successful.')

    if action == 'logout':
        session.pop('admin_id', None)
        session.pop('admin_name', None)
        return ok({}, 'Logged out.')

    if action == 'me':
        if not session.get('admin_id'): return err('Not logged in.', 401)
        a = fetchone("SELECT id,name,email,role FROM admins WHERE id=?", (session['admin_id'],))
        return ok(a)

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# ADMIN CUSTOMER APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/admin/customers', methods=['GET','POST'])
@require_admin
def admin_customers():
    action = request.args.get('action','')
    data   = request.get_json(silent=True) or {}

    if action == 'list':
        page = int(request.args.get('page', 1))
        per  = int(request.args.get('per_page', 20))
        srch = request.args.get('search', '')
        offset = (page-1)*per
        if srch:
            like = f'%{srch}%'
            items = fetchall("SELECT id,name,email,mobile,wing,flat_number,delivery_pattern,is_active,email_verified,created_at FROM customers WHERE name LIKE ? OR email LIKE ? OR mobile LIKE ? OR flat_number LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?", (like,like,like,like,per,offset))
            total = fetchval("SELECT COUNT(*) FROM customers WHERE name LIKE ? OR email LIKE ? OR mobile LIKE ? OR flat_number LIKE ?", (like,like,like,like))
        else:
            items = fetchall("SELECT id,name,email,mobile,wing,flat_number,delivery_pattern,is_active,email_verified,created_at FROM customers ORDER BY created_at DESC LIMIT ? OFFSET ?", (per,offset))
            total = fetchval("SELECT COUNT(*) FROM customers")
        return jsonify({'success':True,'data':items,'meta':{'total':total,'page':page,'per_page':per}})

    if action == 'stats':
        return ok({
            'total': fetchval("SELECT COUNT(*) FROM customers") or 0,
            'active': fetchval("SELECT COUNT(*) FROM customers WHERE is_active=1") or 0,
            'verified': fetchval("SELECT COUNT(*) FROM customers WHERE email_verified=1") or 0,
            'new_this_month': fetchval("SELECT COUNT(*) FROM customers WHERE strftime('%m',created_at)=? AND strftime('%Y',created_at)=?", (str(current_month()).zfill(2), str(current_year()))) or 0,
        })

    if action == 'view':
        cid = int(request.args.get('id', 0))
        c = fetchone("SELECT id,name,email,mobile,wing,flat_number,delivery_pattern,is_active,email_verified,created_at FROM customers WHERE id=?", (cid,))
        subs = fetchall("SELECT s.*,mp.name as product_name,mc.name as company_name FROM subscriptions s JOIN milk_products mp ON mp.id=s.product_id JOIN milk_companies mc ON mc.id=mp.company_id WHERE s.customer_id=?", (cid,))
        payment = fetchone("SELECT * FROM payments WHERE customer_id=? AND month=? AND year=?", (cid, current_month(), current_year()))
        return ok({'customer': c, 'subscriptions': subs, 'payment': payment})

    if action == 'toggle-active':
        cid = data.get('id')
        cur = fetchval("SELECT is_active FROM customers WHERE id=?", (cid,))
        run("UPDATE customers SET is_active=? WHERE id=?", (0 if cur else 1, cid))
        return ok({'is_active': not cur}, 'Status updated.')

    if action == 'update':
        cid = data.get('id')
        run("UPDATE customers SET name=?,mobile=?,wing=?,flat_number=? WHERE id=?",
            (data.get('name'), data.get('mobile'), data.get('wing'), data.get('flat_number'), cid))
        return ok({}, 'Updated.')

    if action == 'notify':
        cid = data.get('customer_id')
        add_notif(cid, data.get('title',''), data.get('message',''), 'general', session['admin_id'])
        return ok({}, 'Notification sent.')


    if action == 'add-milk':
        customer_id = int(data.get('customer_id', 0))
        product_id  = int(data.get('product_id', 0))
        qty         = float(data.get('qty', 1.0))
        if not customer_id or not product_id: return err('customer_id and product_id required.')
        run("INSERT OR REPLACE INTO subscriptions(customer_id,product_id,default_qty,is_active) VALUES(?,?,?,1)", (customer_id, product_id, qty))
        generate_deliveries_for(customer_id, (date.today()+timedelta(days=1)).isoformat(), force_refresh=True)
        add_notif(customer_id, 'Subscription Updated', 'A new milk product has been added to your subscription.', 'general', session['admin_id'])
        return ok({}, 'Milk added to customer subscription.')

    if action == 'remove-milk':
        customer_id = int(data.get('customer_id', 0))
        product_id  = int(data.get('product_id', 0))
        if not customer_id or not product_id: return err('IDs required.')
        run("UPDATE subscriptions SET is_active=0 WHERE customer_id=? AND product_id=?", (customer_id, product_id))
        run("DELETE FROM daily_deliveries WHERE customer_id=? AND product_id=? AND delivery_date>=? AND status='pending'", (customer_id, product_id, today()))
        add_notif(customer_id, 'Subscription Updated', 'A milk product has been removed from your subscription.', 'general', session['admin_id'])
        return ok({}, 'Milk removed.')

    if action == 'customer-subs':
        cid_cs = int(request.args.get('id', 0))
        if not cid_cs: return err('id required.')
        subs = fetchall("""SELECT s.*,mp.name as product_name,mc.name as company_name FROM subscriptions s JOIN milk_products mp ON mp.id=s.product_id JOIN milk_companies mc ON mc.id=mp.company_id WHERE s.customer_id=?""", (cid_cs,))
        all_prods = fetchall("""SELECT mp.*,mc.name as company_name FROM milk_products mp JOIN milk_companies mc ON mc.id=mp.company_id WHERE mp.is_active=1""")
        return ok({'subscriptions': subs, 'all_products': all_prods})

    if action == 'update-customer':
        cid_u = int(data.get('id', 0))
        if not cid_u: return err('id required.')
        run("UPDATE customers SET name=?,mobile=?,wing=?,flat_number=?,delivery_pattern=?,updated_at=? WHERE id=?", (data.get('name'), data.get('mobile'), data.get('wing'), data.get('flat_number'), data.get('delivery_pattern','daily'), now(), cid_u))
        return ok({}, 'Customer updated.')

    return err('Unknown action.', 404)


    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# ADMIN DELIVERY APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/admin/deliveries', methods=['GET','POST'])
@require_admin
def admin_deliveries():
    action = request.args.get('action','')
    data   = request.get_json(silent=True) or {}

    if action == 'today':
        d      = request.args.get('date', today())
        status = request.args.get('status', '')
        srch   = request.args.get('search', '')
        where  = ['dd.delivery_date=?']; params = [d]
        if status: where.append('dd.status=?'); params.append(status)
        if srch:
            where.append('(c.name LIKE ? OR c.flat_number LIKE ?)')
            params += [f'%{srch}%', f'%{srch}%']
        w = ' AND '.join(where)
        dels = fetchall(f"""SELECT dd.*,c.name as customer_name,c.wing,c.flat_number,
            mp.name as product_name,mc.name as company_name
            FROM daily_deliveries dd
            JOIN customers c ON c.id=dd.customer_id
            JOIN milk_products mp ON mp.id=dd.product_id
            JOIN milk_companies mc ON mc.id=mp.company_id
            WHERE {w} ORDER BY c.wing,c.flat_number,mp.name""", params)
        summary = {}
        for s in ['pending','delivered','not_delivered','skipped']:
            summary[s] = fetchval(f"SELECT COUNT(*) FROM daily_deliveries WHERE delivery_date=? AND status=?", (d, s)) or 0
        summary['total_litres'] = fetchval("SELECT COALESCE(SUM(qty_delivered),0) FROM daily_deliveries WHERE delivery_date=? AND status='delivered'", (d,)) or 0
        return ok({'deliveries': dels, 'summary': summary, 'date': d})

    if action == 'generate':
        d = data.get('date', today())
        customers = fetchall("SELECT id FROM customers WHERE is_active=1")
        for c in customers:
            generate_deliveries_for(c['id'], d)
        return ok({'count': len(customers)}, f'Generated for {d}.')

    if action == 'mark':
        did    = data.get('delivery_id')
        status = data.get('status', 'delivered')
        qty    = data.get('qty_delivered', 0)
        run("UPDATE daily_deliveries SET status=?,qty_delivered=?,delivery_time=?,marked_by='admin',updated_at=? WHERE id=?",
            (status, qty, now()[:8]+'07:30:00', now(), did))
        delivery = fetchone("SELECT customer_id,delivery_date FROM daily_deliveries WHERE id=?", (did,))
        if delivery:
            m = int(delivery['delivery_date'][5:7])
            y = int(delivery['delivery_date'][:4])
            sync_payment(delivery['customer_id'], m, y)
            msg = f"Your milk ({qty}L) was delivered." if status == 'delivered' else "Delivery issue reported."
            add_notif(delivery['customer_id'], 'Delivery Update', msg, 'delivery', session['admin_id'])
        return ok({}, 'Updated.')

    if action == 'bulk-mark':
        d = data.get('date', today())
        run("UPDATE daily_deliveries SET status='delivered',qty_delivered=qty_ordered,delivery_time='07:30:00',marked_by='admin' WHERE delivery_date=? AND status='pending'", (d,))
        customers = fetchall("SELECT DISTINCT customer_id FROM daily_deliveries WHERE delivery_date=?", (d,))
        m, y = int(d[5:7]), int(d[:4])
        for c in customers:
            sync_payment(c['customer_id'], m, y)
            add_notif(c['customer_id'], 'Delivery Update', 'Your milk has been delivered today.', 'delivery', session['admin_id'])
        return ok({}, f'All deliveries marked for {d}.')

    if action == 'export':
        d = request.args.get('date', today())
        rows = fetchall(
            "SELECT c.wing,c.flat_number,c.name as customer_name,mp.name as product_name,mc.name as company_name,dd.qty_ordered,dd.qty_delivered,dd.status,dd.price_at_delivery FROM daily_deliveries dd JOIN customers c ON c.id=dd.customer_id JOIN milk_products mp ON mp.id=dd.product_id JOIN milk_companies mc ON mc.id=mp.company_id WHERE dd.delivery_date=? ORDER BY c.wing,c.flat_number,mp.name",
            (d,))
        from collections import OrderedDict
        wings = OrderedDict()
        for r in rows:
            w = r['wing']; f = r['flat_number']
            if w not in wings: wings[w] = OrderedDict()
            if f not in wings[w]: wings[w][f] = {'customer_name': r['customer_name'], 'items': []}
            wings[w][f]['items'].append(r)
        lines_out = [f"Delivery Export — {d}", "="*40]
        for wing, flats in wings.items():
            lines_out.append(f"Wing {wing}")
            lines_out.append("-"*20)
            for flat, info in flats.items():
                items = info['items']
                if all(i['status'] == 'skipped' for i in items):
                    lines_out.append(f"  {flat}  Skipped")
                else:
                    milk_strs = [f"{i['product_name']} {i['qty_delivered'] or i['qty_ordered']}L" for i in items if i['status'] != 'skipped']
                    lines_out.append(f"  {flat}  {', '.join(milk_strs)}")
            lines_out.append("")
        export_text = '\n'.join(lines_out)
        return jsonify({'success': True, 'data': rows, 'text': export_text, 'date': d})

    if action == 'stats':
        d = request.args.get('date', today())
        week = []
        for i in range(6, -1, -1):
            day = (date.today() - timedelta(days=i)).isoformat()
            L = fetchval("SELECT COALESCE(SUM(qty_delivered),0) FROM daily_deliveries WHERE delivery_date=? AND status='delivered'", (day,)) or 0
            week.append({'delivery_date': day, 'total_litres': L})
        summary = {}
        for s in ['pending','delivered','not_delivered','skipped']:
            summary[s] = fetchval("SELECT COUNT(*) FROM daily_deliveries WHERE delivery_date=? AND status=?", (d, s)) or 0
        return ok({'today': summary, 'week_trend': week})

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# ADMIN PAYMENT APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/admin/payments', methods=['GET','POST'])
@require_admin
def admin_payments():
    action = request.args.get('action','')
    data   = request.get_json(silent=True) or {}

    if action == 'outstanding':
        rows = fetchall("""SELECT p.*,c.name as customer_name,c.wing,c.flat_number,c.mobile
            FROM payments p JOIN customers c ON c.id=p.customer_id
            WHERE p.status IN ('unpaid','partial') ORDER BY p.balance DESC""")
        return ok(rows)

    if action == 'month-summary':
        m = int(request.args.get('month', current_month()))
        y = int(request.args.get('year', current_year()))
        summary = fetchone("""SELECT SUM(total_amount) as total_billed,SUM(paid_amount) as total_collected,
            SUM(balance) as total_outstanding,
            SUM(status='paid') as fully_paid,SUM(status='partial') as partial,SUM(status='unpaid') as unpaid
            FROM payments WHERE month=? AND year=?""", (m, y)) or {}
        return ok({'summary': summary, 'month': m, 'year': y})

    if action == 'record':
        cid    = data.get('customer_id')
        month  = int(data.get('month', current_month()))
        year   = int(data.get('year', current_year()))
        amount = float(data.get('amount', 0))
        method = data.get('method', 'cash')
        if not cid or not amount: return err('customer_id and amount required.')
        sync_payment(cid, month, year)
        existing = fetchone("SELECT * FROM payments WHERE customer_id=? AND month=? AND year=?", (cid, month, year))
        if not existing: return err('Payment record not found.')
        new_paid = existing['paid_amount'] + amount
        new_bal  = max(0, existing['total_amount'] - new_paid)
        status   = 'paid' if new_bal <= 0 else ('partial' if new_paid > 0 else 'unpaid')
        run("UPDATE payments SET paid_amount=?,balance=?,status=?,payment_method=?,payment_date=?,recorded_by=?,updated_at=? WHERE id=?",
            (new_paid, new_bal, status, method, today(), session['admin_id'], now(), existing['id']))
        add_notif(cid, 'Payment Recorded', f'Payment of ₹{amount:.0f} via {method} recorded. Balance: ₹{new_bal:.0f}', 'payment', session['admin_id'])
        updated = fetchone("SELECT * FROM payments WHERE id=?", (existing['id'],))
        return ok(updated, 'Payment recorded.')

    if action == 'revenue-report':
        rows = []
        for i in range(5, -1, -1):
            m = ((current_month() - 1 - i) % 12) + 1
            y = current_year() - ((current_month() - 1 - i) // 12 + (1 if i > current_month()-1 else 0))
            r = fetchone("SELECT SUM(total_amount) as billed,SUM(paid_amount) as collected,SUM(balance) as outstanding FROM payments WHERE month=? AND year=?", (m, y)) or {}
            r['month'] = m; r['year'] = y
            rows.append(r)
        return ok(rows)

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# ADMIN PRODUCT APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/admin/products', methods=['GET','POST'])
@require_admin
def admin_products():
    action = request.args.get('action','')
    data   = request.get_json(silent=True) or {}

    if action == 'list':
        companies = fetchall("SELECT * FROM milk_companies WHERE is_active=1")
        products  = fetchall("""SELECT mp.*,mc.name as company_name,mc.logo_color,
            (SELECT price_per_litre FROM milk_prices WHERE product_id=mp.id AND effective_from<=? ORDER BY effective_from DESC LIMIT 1) as current_price
            FROM milk_products mp JOIN milk_companies mc ON mc.id=mp.company_id""", (today(),))
        return ok({'companies': companies, 'products': products})

    if action == 'add':
        cid  = data.get('company_id'); name = data.get('name','')
        if not cid or not name: return err('company_id and name required.')
        pid = run("INSERT INTO milk_products(company_id,name,description) VALUES(?,?,?)",
                  (cid, name, data.get('description','')))
        if data.get('initial_price'):
            run("INSERT INTO milk_prices(product_id,price_per_litre,effective_from,created_by) VALUES(?,?,?,?)",
                (pid, float(data['initial_price']), today(), session['admin_id']))
        return ok({'id': pid}, 'Product added.')

    if action == 'set-price':
        pid   = data.get('product_id')
        price = float(data.get('price', 0))
        eff   = data.get('effective_from', today())
        if not pid or price <= 0: return err('product_id and price required.')
        run("UPDATE milk_prices SET effective_to=? WHERE product_id=? AND effective_to IS NULL", (eff, pid))
        run("INSERT INTO milk_prices(product_id,price_per_litre,effective_from,created_by) VALUES(?,?,?,?)",
            (pid, price, eff, session['admin_id']))
        # Update pending deliveries
        run("UPDATE daily_deliveries SET price_at_delivery=? WHERE product_id=? AND delivery_date>=? AND status='pending'", (price, pid, eff))
        # Sync all customer payments
        customers = fetchall("SELECT DISTINCT customer_id FROM daily_deliveries WHERE product_id=?", (pid,))
        for c in customers:
            sync_payment(c['customer_id'], current_month(), current_year())
            add_notif(c['customer_id'], 'Price Change', f'Milk price updated to ₹{price}/L from {eff}.', 'price_change', session['admin_id'])
        return ok({}, 'Price updated. Bills synced. Customers notified.')

    if action == 'price-history':
        pid = request.args.get('product_id')
        return ok(fetchall("SELECT * FROM milk_prices WHERE product_id=? ORDER BY effective_from DESC", (pid,)))

    if action == 'add-company':
        name = data.get('name','')
        if not name: return err('Name required.')
        cid = run("INSERT INTO milk_companies(name,tagline,logo_color) VALUES(?,?,?)",
                  (name, data.get('tagline',''), data.get('logo_color','#22c55e')))
        return ok({'id': cid}, 'Company added.')

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# ADMIN REPORTS APIs
# ─────────────────────────────────────────────────────────
@app.route('/api/admin/reports', methods=['GET','POST'])
@require_admin
def admin_reports():
    action = request.args.get('action','')

    if action == 'dashboard':
        stats = {
            'customers': fetchval("SELECT COUNT(*) FROM customers WHERE is_active=1") or 0,
            'today_delivered': fetchval("SELECT COUNT(DISTINCT customer_id) FROM daily_deliveries WHERE delivery_date=? AND status='delivered'", (today(),)) or 0,
            'today_pending': fetchval("SELECT COUNT(*) FROM daily_deliveries WHERE delivery_date=? AND status='pending'", (today(),)) or 0,
            'today_litres': fetchval("SELECT COALESCE(SUM(qty_delivered),0) FROM daily_deliveries WHERE delivery_date=? AND status='delivered'", (today(),)) or 0,
            'outstanding': fetchval("SELECT COALESCE(SUM(balance),0) FROM payments WHERE status IN ('unpaid','partial')") or 0,
            'month_collected': fetchval("SELECT COALESCE(SUM(paid_amount),0) FROM payments WHERE month=? AND year=?", (current_month(), current_year())) or 0,
        }
        recent = fetchall("SELECT id,name,wing,flat_number,delivery_pattern,created_at FROM customers ORDER BY created_at DESC LIMIT 5")
        pending_pay = fetchall("""SELECT p.balance,p.status,p.month,p.year,c.name,c.wing,c.flat_number
            FROM payments p JOIN customers c ON c.id=p.customer_id
            WHERE p.status IN ('unpaid','partial') ORDER BY p.balance DESC LIMIT 5""")
        products = fetchall("""SELECT mp.name,mc.name as company_name,
            (SELECT price_per_litre FROM milk_prices WHERE product_id=mp.id AND effective_from<=? ORDER BY effective_from DESC LIMIT 1) as current_price
            FROM milk_products mp JOIN milk_companies mc ON mc.id=mp.company_id WHERE mp.is_active=1""", (today(),))
        week = []
        for i in range(6, -1, -1):
            day = (date.today() - timedelta(days=i)).isoformat()
            L = fetchval("SELECT COALESCE(SUM(qty_delivered),0) FROM daily_deliveries WHERE delivery_date=? AND status='delivered'", (day,)) or 0
            week.append({'delivery_date': day, 'total_litres': L})
        return ok({'stats': stats, 'recent_customers': recent, 'pending_payments': pending_pay, 'products': products, 'week_trend': week})

    if action == 'wing-summary':
        d = request.args.get('date', today())
        wings = fetchall("SELECT DISTINCT wing FROM customers WHERE is_active=1 ORDER BY wing")
        result = []
        for w in wings:
            wing = w['wing']
            custs = fetchall("SELECT id FROM customers WHERE wing=? AND is_active=1", (wing,))
            total = len(custs)
            delivered = fetchval("SELECT COUNT(DISTINCT customer_id) FROM daily_deliveries WHERE delivery_date=? AND status='delivered' AND customer_id IN (SELECT id FROM customers WHERE wing=?)", (d, wing)) or 0
            litres = fetchval("SELECT COALESCE(SUM(qty_delivered),0) FROM daily_deliveries WHERE delivery_date=? AND status='delivered' AND customer_id IN (SELECT id FROM customers WHERE wing=?)", (d, wing)) or 0
            result.append({'wing': wing, 'total_customers': total, 'delivered_count': delivered, 'pending_count': total-delivered, 'total_litres': litres})
        return ok(result)

    if action == 'monthly':
        m = int(request.args.get('month', current_month()))
        y = int(request.args.get('year', current_year()))
        daily = fetchall("""SELECT delivery_date, SUM(qty_delivered) as litres, COUNT(DISTINCT customer_id) as customers_served
            FROM daily_deliveries WHERE strftime('%m',delivery_date)=? AND strftime('%Y',delivery_date)=? AND status='delivered'
            GROUP BY delivery_date ORDER BY delivery_date""", (str(m).zfill(2), str(y)))
        products = fetchall("""SELECT mp.name as product_name,mc.name as company_name,
            SUM(dd.qty_delivered) as total_qty, SUM(dd.qty_delivered*dd.price_at_delivery) as revenue
            FROM daily_deliveries dd JOIN milk_products mp ON mp.id=dd.product_id JOIN milk_companies mc ON mc.id=mp.company_id
            WHERE strftime('%m',dd.delivery_date)=? AND strftime('%Y',dd.delivery_date)=? AND dd.status='delivered'
            GROUP BY dd.product_id ORDER BY revenue DESC""", (str(m).zfill(2), str(y)))
        pay_summary = fetchone("SELECT SUM(total_amount) as total_billed, SUM(paid_amount) as total_collected FROM payments WHERE month=? AND year=?", (m,y)) or {}
        return ok({'daily_litres': daily, 'product_totals': products, 'payment_summary': pay_summary, 'month': m, 'year': y})

    return err('Unknown action.', 404)

# ─────────────────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────────────────
if __name__ == '__main__':
    os.makedirs('/home/claude/milkroute_live/db', exist_ok=True)
    with app.app_context():
        db = get_db()
        db.executescript(SCHEMA)
        db.commit()
        seed()
    print("🥛 MilkRoute Live Server starting on port 7861")
    print("   Customer Portal: http://localhost:7860/")
    print("   Admin Panel:     http://localhost:7860/admin")
    print("   Admin login:     admin@milkroute.com / admin123")
    print("   Demo customer:   rahul@demo.com / demo123")
    app.run(host='0.0.0.0', port=7861, debug=False)
