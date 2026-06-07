<?php
class DB {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }

    public static function getInstance(): DB {
        if (self::$instance === null) {
            self::$instance = new DB();
        }
        return self::$instance;
    }

    public function pdo(): PDO {
        return $this->pdo;
    }

    // Shorthand: query with params, returns PDOStatement
    public function run(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch all rows
    public function fetchAll(string $sql, array $params = []): array {
        return $this->run($sql, $params)->fetchAll();
    }

    // Fetch single row
    public function fetchOne(string $sql, array $params = []): ?array {
        $row = $this->run($sql, $params)->fetch();
        return $row ?: null;
    }

    // Fetch single value
    public function fetchValue(string $sql, array $params = []) {
        return $this->run($sql, $params)->fetchColumn();
    }

    // Insert and return last insert id
    public function insert(string $sql, array $params = []): int {
        $this->run($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    // Begin transaction
    public function begin(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { $this->pdo->rollBack(); }
}
