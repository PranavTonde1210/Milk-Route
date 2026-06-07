<?php
class CustomerModel {
    private DB $db;

    public function __construct() {
        $this->db = DB::getInstance();
    }

    public function findByEmail(string $email): ?array {
        return $this->db->fetchOne('SELECT * FROM customers WHERE email = ?', [$email]);
    }

    public function findById(int $id): ?array {
        return $this->db->fetchOne('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    public function create(array $data): int {
        return $this->db->insert(
            'INSERT INTO customers (name, email, mobile, password, wing, flat_number,
             delivery_pattern, alternate_start, email_verify_token, email_verify_expires)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'], $data['email'], $data['mobile'],
                hashPassword($data['password']),
                $data['wing'], $data['flat_number'],
                $data['delivery_pattern'] ?? 'daily',
                $data['alternate_start'] ?? null,
                $data['verify_token'],
                date('Y-m-d H:i:s', time() + EMAIL_VERIFY_EXPIRY),
            ]
        );
    }

    public function verifyEmail(string $token): bool {
        $customer = $this->db->fetchOne(
            'SELECT id FROM customers WHERE email_verify_token = ?
             AND email_verify_expires > NOW() AND email_verified = 0',
            [$token]
        );
        if (!$customer) return false;
        $this->db->run(
            'UPDATE customers SET email_verified = 1, email_verify_token = NULL,
             email_verify_expires = NULL WHERE id = ?',
            [$customer['id']]
        );
        return true;
    }

    public function setResetToken(int $id, string $token): void {
        $this->db->run(
            'UPDATE customers SET reset_token = ?, reset_token_expires = ? WHERE id = ?',
            [$token, date('Y-m-d H:i:s', time() + RESET_TOKEN_EXPIRY), $id]
        );
    }

    public function findByResetToken(string $token): ?array {
        return $this->db->fetchOne(
            'SELECT * FROM customers WHERE reset_token = ? AND reset_token_expires > NOW()',
            [$token]
        );
    }

    public function updatePassword(int $id, string $newPassword): void {
        $this->db->run(
            'UPDATE customers SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
            [hashPassword($newPassword), $id]
        );
    }

    public function update(int $id, array $data): void {
        $fields = [];
        $params = [];
        $allowed = ['name', 'mobile', 'wing', 'flat_number', 'delivery_pattern', 'alternate_start'];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        $this->db->run('UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public function getAll(int $page = 1, int $perPage = 20, string $search = ''): array {
        $offset = ($page - 1) * $perPage;
        $where = '';
        $params = [];
        if ($search) {
            $where = 'WHERE (name LIKE ? OR email LIKE ? OR mobile LIKE ? OR flat_number LIKE ?)';
            $like = "%$search%";
            $params = [$like, $like, $like, $like];
        }
        $items = $this->db->fetchAll(
            "SELECT id, name, email, mobile, wing, flat_number, delivery_pattern,
             is_active, email_verified, created_at
             FROM customers $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );
        $total = (int)$this->db->fetchValue("SELECT COUNT(*) FROM customers $where", $params);
        return ['items' => $items, 'total' => $total];
    }

    public function toggleActive(int $id): bool {
        $this->db->run('UPDATE customers SET is_active = NOT is_active WHERE id = ?', [$id]);
        return (bool)$this->db->fetchValue('SELECT is_active FROM customers WHERE id = ?', [$id]);
    }

    public function getStats(): array {
        return [
            'total'    => (int)$this->db->fetchValue('SELECT COUNT(*) FROM customers'),
            'active'   => (int)$this->db->fetchValue('SELECT COUNT(*) FROM customers WHERE is_active = 1'),
            'verified' => (int)$this->db->fetchValue('SELECT COUNT(*) FROM customers WHERE email_verified = 1'),
            'new_this_month' => (int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM customers WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?',
                [currentMonth(), currentYear()]
            ),
        ];
    }
}
